<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalTier;
use App\Models\PurchaseRequisition;
use App\Models\PrLineItem;
use App\Services\AuditTrailService;
use App\Services\DeliveryInstructionFromPrService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private DocumentNumberingService $docNumbering,
        private WorkflowEngine $workflow,
        private DeliveryInstructionFromPrService $deliveryInstructionFromPr,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseRequisition::with('sourceMr', 'sourceSr', 'lineItems', 'pihak1', 'approvalLogs.actor');

        if ($request->boolean('for_po_creation')) {
            $query->where('status', 'forwarded_to_p3')
                ->whereDoesntHave('purchaseOrders')
                ->where(function ($q) {
                    // SR PR: butuh PO (untuk Pre-RD setelah pengiriman ke vendor).
                    $q->where('source_type', 'sr')
                        // MR PR pengadaan: tidak termasuk WO/Transfer/SO yang berakhir di DI/DN.
                        ->orWhere(function ($q2) {
                            $q2->where('source_type', 'mr')
                                ->whereHas('sourceMr', function ($mrQ) {
                                    $mrQ->whereNotIn('source_type', ['wo', 'transfer', 'so']);
                                });
                        });
                });
        } elseif ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('pr_type')) {
            $query->byType($request->pr_type);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $prs = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($prs);
    }

    public function show(PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if ($purchaseRequisition->status === 'forwarded_to_p3') {
            $actorId = (int) request()->user()->id;
            DB::transaction(function () use ($purchaseRequisition, $actorId) {
                $this->deliveryInstructionFromPr->ensureForForwardedPurchaseRequisition($purchaseRequisition, $actorId, false);
            });
        }

        return response()->json([
            'pr' => $purchaseRequisition->fresh()->load('lineItems', 'pihak1', 'approvalLogs.actor', 'sourceMr.lineItems.item', 'sourceSr.lineItems', 'deliveryInstruction.deliveryNote'),
        ]);
    }

    public function inputPricing(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if ($purchaseRequisition->status !== 'auto_created') {
            return response()->json(['message' => 'PR is not in auto_created status.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:pr_line_items,id',
            'items.*.initial_price' => 'required|numeric|min:0',
        ]);

        $totalValue = 0;
        foreach ($validated['items'] as $item) {
            $lineItem = PrLineItem::where('id', $item['id'])
                ->where('pr_id', $purchaseRequisition->id)
                ->first();

            if (!$lineItem) {
                continue;
            }

            $lineItem->update(['initial_price' => $item['initial_price']]);
            $totalValue += $lineItem->qty * $item['initial_price'];
        }

        $tierCount = ApprovalTier::getTierCountForValue('pr', (int) $totalValue);

        $fromStatus = $purchaseRequisition->status;
        $purchaseRequisition->update([
            'status' => 'pending_pihak_ii',
            'total_value' => $totalValue,
            'tier_count' => $tierCount,
            'current_tier' => 0,
            'pihak1_id' => $request->user()->id,
        ]);

        $this->auditTrail->log('pr', $purchaseRequisition->id, $request->user()->id, $fromStatus, 'pending_pihak_ii', 'Pihak I input pricing');

        $this->notificationService->notifyUsersWithRole(
            'pihak_2',
            'pr_pending_approval',
            'PR Pending Approval',
            "PR {$purchaseRequisition->number} requires Pihak II approval. Value: Rp " . number_format($totalValue, 0, ',', '.'),
            'pr',
            $purchaseRequisition->id
        );

        return response()->json([
            'message' => 'Pricing input successfully. PR forwarded to Pihak II.',
            'pr' => $purchaseRequisition->fresh()->load('lineItems', 'pihak1'),
        ]);
    }

    public function approveByPihak2(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if ($purchaseRequisition->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'PR is not pending Pihak II approval.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'approve' && (int) $purchaseRequisition->pihak1_id === (int) $request->user()->id) {
            return response()->json(['message' => 'User who input pricing cannot approve this PR.'], 403);
        }

        if ($validated['action'] === 'decline') {
            $fromStatus = $purchaseRequisition->status;
            $purchaseRequisition->update(['status' => 'declined']);
            $this->auditTrail->log('pr', $purchaseRequisition->id, $request->user()->id, $fromStatus, 'declined', $validated['reason']);

            $source = $purchaseRequisition->source();
            if ($source) {
                $this->notificationService->notify(
                    $source->requestor_id,
                    'pr_declined',
                    'PR Declined',
                    "PR {$purchaseRequisition->number} has been declined by Pihak II.",
                    'pr',
                    $purchaseRequisition->id
                );
            }

            return response()->json(['message' => 'PR declined.', 'pr' => $purchaseRequisition->fresh()]);
        }

        $newTier = $purchaseRequisition->current_tier + 1;

        if ($newTier >= $purchaseRequisition->tier_count) {
            DB::transaction(function () use ($purchaseRequisition, $request, $newTier) {
                $fromStatus = $purchaseRequisition->status;
                $purchaseRequisition->update([
                    'status' => 'forwarded_to_p3',
                    'current_tier' => $newTier,
                ]);

                $this->auditTrail->log('pr', $purchaseRequisition->id, $request->user()->id, $fromStatus, 'forwarded_to_p3', 'PR fully approved by Pihak II (all tiers)');

                $mrForNotify = $purchaseRequisition->source_type === 'mr' ? $purchaseRequisition->source() : null;
                $deliveryChainAfterPr = $mrForNotify && $mrForNotify->createsDeliveryInstructionAfterPrApproval();

                if (!$deliveryChainAfterPr) {
                    $this->notificationService->notifyUsersWithRole(
                        'purchasing',
                        'pr_approved',
                        'PR Approved - Ready for PO',
                        "PR {$purchaseRequisition->number} has been fully approved. Ready for PO creation.",
                        'pr',
                        $purchaseRequisition->id
                    );
                }

                $this->deliveryInstructionFromPr->ensureForForwardedPurchaseRequisition(
                    $purchaseRequisition,
                    (int) $request->user()->id,
                    true
                );
            });
        } else {
            $purchaseRequisition->update(['current_tier' => $newTier]);
            $this->auditTrail->log('pr', $purchaseRequisition->id, $request->user()->id, 'pending_pihak_ii', 'pending_pihak_ii', "Pihak II Tier {$newTier}/{$purchaseRequisition->tier_count} approved");

            $remaining = $purchaseRequisition->tier_count - $newTier;
            $this->notificationService->notifyUsersWithRole(
                'pihak_2',
                'pr_pending_approval',
                "PR Pending Tier " . ($newTier + 1) . " Approval",
                "PR {$purchaseRequisition->number} requires Pihak II Tier " . ($newTier + 1) . " approval. {$remaining} tier(s) remaining.",
                'pr',
                $purchaseRequisition->id
            );
        }

        $prFresh = $purchaseRequisition->fresh()->load(
            'lineItems',
            'pihak1',
            'deliveryInstruction.deliveryNote',
            'sourceMr.lineItems.item',
            'sourceSr.lineItems',
        );

        $fullyApproved = $prFresh->status === 'forwarded_to_p3';
        $approvalMessage = "PR approved for tier {$newTier}. Additional approval tiers required.";
        if ($fullyApproved) {
            if ($prFresh->source_type === 'sr') {
                $approvalMessage = 'PR fully approved. Continue with Delivery Instruction and Delivery Note.';
            } else {
                $mr = $prFresh->source_type === 'mr' ? $prFresh->sourceMr : null;
                if ($mr && $mr->createsDeliveryInstructionAfterPrApproval()) {
                    $approvalMessage = 'PR fully approved. Continue with Delivery Instruction and Delivery Note (no Purchase Order for this MR).';
                } else {
                    $approvalMessage = 'PR fully approved and forwarded to Purchasing.';
                }
            }
        }

        return response()->json([
            'message' => $approvalMessage,
            'pr' => $prFresh,
        ]);
    }
}