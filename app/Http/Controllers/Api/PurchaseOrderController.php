<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalTier;
use App\Models\MasterVendor;
use App\Models\PoLineItem;
use App\Models\PriceComparison;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::with('purchaseRequisition', 'vendor', 'lineItems', 'priceComparisons', 'createdBy', 'approvalLogs.actor');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('pr_id')) {
            $query->where('pr_id', $request->pr_id);
        }

        $pos = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($pos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pr_id' => 'required|exists:purchase_requisitions,id',
            'vendor_id' => 'required|exists:master_vendors,id',
            'term_of_payment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
            'items.*.final_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:percentage,fixed',
            'items.*.description' => 'nullable|string',
            'price_comparisons' => 'required|array|min:2',
            'price_comparisons.*.vendor_name' => 'required|string',
            'price_comparisons.*.quoted_price' => 'required|numeric|min:0',
            'price_comparisons.*.notes' => 'nullable|string',
        ]);

        $pr = PurchaseRequisition::find($validated['pr_id']);
        if (!$pr || $pr->status !== 'forwarded_to_p3') {
            return response()->json(['message' => 'PR must be fully approved before creating PO.'], 422);
        }

        $vendor = MasterVendor::find($validated['vendor_id']);
        if (!$vendor || $vendor->status !== 'active') {
            return response()->json(['message' => 'Only active vendors can be selected.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $pr) {
            $totalValue = 0;
            foreach ($validated['items'] as $item) {
                $lineTotal = $item['qty'] * $item['final_price'];
                $discount = $item['discount'] ?? 0;
                if (($item['discount_type'] ?? 'fixed') === 'percentage') {
                    $lineTotal -= ($lineTotal * $discount / 100);
                } else {
                    $lineTotal -= $discount;
                }
                $totalValue += max(0, $lineTotal);
            }

            $discountValue = $validated['discount_value'] ?? 0;
            $discountType = $validated['discount_type'] ?? 'fixed';

            $tierCount = ApprovalTier::getTierCountForValue('po', (int) $totalValue);

            $po = PurchaseOrder::create([
                'number' => $this->docNumbering->generate('po'),
                'date' => now()->toDateString(),
                'pr_id' => $validated['pr_id'],
                'vendor_id' => $validated['vendor_id'],
                'pr_type' => $pr->pr_type,
                'total_value' => $totalValue,
                'discount_value' => $discountValue,
                'discount_type' => $discountType,
                'term_of_payment' => $validated['term_of_payment'] ?? null,
                'tier_count' => $tierCount,
                'current_tier' => 0,
                'status' => 'pending_pihak_ii',
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                PoLineItem::create([
                    'po_id' => $po->id,
                    'item_name' => $item['item_name'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'final_price' => $item['final_price'],
                    'discount' => $item['discount'] ?? 0,
                    'discount_type' => $item['discount_type'] ?? 'fixed',
                    'description' => $item['description'] ?? null,
                ]);
            }

            foreach ($validated['price_comparisons'] as $comparison) {
                PriceComparison::create([
                    'po_id' => $po->id,
                    'vendor_name' => $comparison['vendor_name'],
                    'quoted_price' => $comparison['quoted_price'],
                    'notes' => $comparison['notes'] ?? null,
                ]);
            }

            $this->auditTrail->log('po', $po->id, $request->user()->id, 'draft', 'pending_pihak_ii', 'PO created from PR ' . $pr->number);

            $this->notificationService->notifyUsersWithRole(
                'pihak_2',
                'po_pending_approval',
                'PO Pending Approval',
                "PO {$po->number} requires Pihak II approval. Value: Rp " . number_format($totalValue, 0, ',', '.'),
                'po',
                $po->id
            );

            return response()->json([
                'message' => 'Purchase Order created successfully.',
                'po' => $po->load('lineItems', 'priceComparisons', 'vendor', 'purchaseRequisition', 'createdBy'),
            ], 201);
        });
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json([
            'po' => $purchaseOrder->load('lineItems', 'priceComparisons', 'vendor', 'purchaseRequisition.lineItems', 'createdBy', 'approvalLogs.actor'),
        ]);
    }

    public function approveByPihak2(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'PO is not pending Pihak II approval.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $fromStatus = $purchaseOrder->status;
            $purchaseOrder->update(['status' => 'declined', 'decline_reason' => $validated['reason']]);
            $this->auditTrail->log('po', $purchaseOrder->id, $request->user()->id, $fromStatus, 'declined', $validated['reason']);

            $pr = $purchaseOrder->purchaseRequisition;
            $this->notificationService->notify(
                $purchaseOrder->created_by,
                'po_declined',
                'PO Declined',
                "PO {$purchaseOrder->number} has been declined. Reason: {$validated['reason']}",
                'po',
                $purchaseOrder->id
            );

            return response()->json(['message' => 'PO declined.', 'po' => $purchaseOrder->fresh()]);
        }

        $newTier = $purchaseOrder->current_tier + 1;

        if ($newTier >= $purchaseOrder->tier_count) {
            $fromStatus = $purchaseOrder->status;
            $purchaseOrder->update(['status' => 'approved', 'current_tier' => $newTier]);
            $this->auditTrail->log('po', $purchaseOrder->id, $request->user()->id, $fromStatus, 'approved', 'PO fully approved by Pihak II');

            $this->notificationService->notify(
                $purchaseOrder->created_by,
                'po_approved',
                'PO Approved',
                "PO {$purchaseOrder->number} has been fully approved. You may proceed with vendor engagement.",
                'po',
                $purchaseOrder->id
            );
        } else {
            $purchaseOrder->update(['current_tier' => $newTier]);
            $this->auditTrail->log('po', $purchaseOrder->id, $request->user()->id, 'pending_pihak_ii', 'pending_pihak_ii', "Pihak II Tier {$newTier}/{$purchaseOrder->tier_count} approved");

            $remaining = $purchaseOrder->tier_count - $newTier;
            $this->notificationService->notifyUsersWithRole(
                'pihak_2',
                'po_pending_approval',
                "PO Pending Tier " . ($newTier + 1) . " Approval",
                "PO {$purchaseOrder->number} requires Pihak II Tier " . ($newTier + 1) . " approval. {$remaining} tier(s) remaining.",
                'po',
                $purchaseOrder->id
            );
        }

        return response()->json([
            'message' => $purchaseOrder->status === 'approved' ? 'PO fully approved.' : "PO approved for tier {$newTier}. Additional approval tiers required.",
            'po' => $purchaseOrder->fresh()->load('lineItems', 'priceComparisons', 'vendor'),
        ]);
    }
}