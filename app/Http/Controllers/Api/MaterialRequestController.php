<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterItem;
use App\Models\MaterialRequest;
use App\Models\MrLineItem;
use App\Models\PurchaseRequisition;
use App\Models\PrLineItem;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialRequestController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaterialRequest::with('requestor', 'department', 'lineItems.item', 'approvedByDeptHead', 'approvedByPihak2');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('source_type')) {
            $query->bySourceType($request->source_type);
        }
        if ($request->has('department_id')) {
            $query->byDepartment($request->department_id);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $mrs = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($mrs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => 'required|in:internal,asset,customer,project_internal',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:master_items,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
            'items.*.description' => 'nullable|string',
        ]);

        $inactiveItems = MasterItem::whereIn('id', collect($validated['items'])->pluck('item_id'))
            ->where('status', '!=', 'active')->exists();
        if ($inactiveItems) {
            return response()->json(['message' => 'All items must have ACTIVE status.'], 422);
        }

        return DB::transaction(function () use ($validated, $request) {
            $mr = MaterialRequest::create([
                'number' => $this->docNumbering->generate('mr'),
                'date' => now()->toDateString(),
                'source_type' => $validated['source_type'],
                'requestor_id' => $request->user()->id,
                'department_id' => $request->user()->department_id,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_dept_head',
            ]);

            foreach ($validated['items'] as $item) {
                MrLineItem::create([
                    'mr_id' => $mr->id,
                    'item_id' => $item['item_id'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'description' => $item['description'] ?? null,
                ]);
            }

            $this->auditTrail->log('mr', $mr->id, $request->user()->id, 'draft', 'pending_dept_head', 'MR submitted for Department Head approval');

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'mr_pending_approval',
                'MR Pending Approval',
                "MR {$mr->number} requires your approval.",
                'mr',
                $mr->id
            );

            return response()->json([
                'message' => 'Material Request created successfully.',
                'mr' => $mr->load('lineItems.item', 'requestor', 'department'),
            ], 201);
        });
    }

    public function show(MaterialRequest $materialRequest): JsonResponse
    {
        return response()->json([
            'mr' => $materialRequest->load('lineItems.item', 'requestor', 'department', 'approvedByDeptHead', 'approvedByPihak2', 'approvalLogs.actor'),
        ]);
    }

    public function approveByDeptHead(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (!$request->user()->isDeptHead()) {
            return response()->json(['message' => 'Only Department Head can approve at this stage.'], 403);
        }

        if ($materialRequest->status !== 'pending_dept_head') {
            return response()->json(['message' => 'MR is not pending Department Head approval.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $this->workflow->transition($materialRequest, 'declined', $request->user()->id, $validated['reason'], 'mr');
            $materialRequest->update(['decline_reason' => $validated['reason']]);

            $this->notificationService->notify(
                $materialRequest->requestor_id,
                'mr_declined',
                'MR Declined',
                "MR {$materialRequest->number} has been declined by Department Head. Reason: {$validated['reason']}",
                'mr',
                $materialRequest->id
            );

            return response()->json(['message' => 'MR declined.', 'mr' => $materialRequest->fresh()->load('lineItems.item', 'requestor')]);
        }

        if ($materialRequest->isFlowB()) {
            return $this->approveAndGeneratePR($materialRequest, $request);
        }

        $materialRequest->update([
            'status' => 'pending_pihak_ii',
            'approved_by_dept_head' => $request->user()->id,
        ]);

        $this->auditTrail->log('mr', $materialRequest->id, $request->user()->id, 'pending_dept_head', 'pending_pihak_ii', 'Approved by Department Head');

        $pihak2Role = $this->getPihak2Role($materialRequest->source_type);
        $this->notificationService->notifyUsersWithRole(
            $pihak2Role,
            'mr_pending_approval',
            'MR Pending Pihak II Approval',
            "MR {$materialRequest->number} requires your validation and flagging.",
            'mr',
            $materialRequest->id
        );

        return response()->json([
            'message' => 'MR approved by Department Head. Forwarded to Pihak II.',
            'mr' => $materialRequest->fresh()->load('lineItems.item', 'requestor', 'department'),
        ]);
    }

    public function flagItems(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if ($materialRequest->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'MR is not pending Pihak II validation.'], 422);
        }

        $validated = $request->validate([
            'flagged_items' => 'required|array|min:1',
            'flagged_items.*' => 'required|exists:mr_line_items,id',
        ]);

        MrLineItem::where('mr_id', $materialRequest->id)->update(['flagged' => false, 'flagged_by' => null]);

        foreach ($validated['flagged_items'] as $lineItemId) {
            MrLineItem::where('id', $lineItemId)
                ->where('mr_id', $materialRequest->id)
                ->update(['flagged' => true, 'flagged_by' => $request->user()->id]);
        }

        return response()->json([
            'message' => 'Items flagged successfully.',
            'mr' => $materialRequest->fresh()->load('lineItems.item'),
        ]);
    }

    public function approveByPihak2(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if ($materialRequest->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'MR is not pending Pihak II approval.'], 422);
        }

        $hasFlaggedItems = $materialRequest->lineItems()->where('flagged', true)->exists();
        if (!$hasFlaggedItems) {
            return response()->json(['message' => 'Please flag at least one item before approving.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $this->workflow->transition($materialRequest, 'declined', $request->user()->id, $validated['reason'], 'mr');
            $materialRequest->update(['decline_reason' => $validated['reason']]);

            $this->notificationService->notify(
                $materialRequest->requestor_id,
                'mr_declined',
                'MR Declined by Pihak II',
                "MR {$materialRequest->number} has been declined. Reason: {$validated['reason']}",
                'mr',
                $materialRequest->id
            );

            return response()->json(['message' => 'MR declined by Pihak II.', 'mr' => $materialRequest->fresh()]);
        }

        return $this->approveAndGeneratePR($materialRequest, $request, true);
    }

    private function approveAndGeneratePR(MaterialRequest $materialRequest, Request $request, bool $isPihak2 = false): JsonResponse
    {
        return DB::transaction(function () use ($materialRequest, $request, $isPihak2) {
            $updateData = [
                'status' => 'approved',
                'approved_by_dept_head' => $materialRequest->approved_by_dept_head ?? $request->user()->id,
            ];

            if ($isPihak2) {
                $updateData['approved_by_pihak2'] = $request->user()->id;
            }

            $materialRequest->update($updateData);

            $fromStatus = $isPihak2 ? 'pending_pihak_ii' : 'pending_dept_head';
            $this->auditTrail->log('mr', $materialRequest->id, $request->user()->id, $fromStatus, 'approved', $isPihak2 ? 'Approved by Pihak II' : 'Approved by Department Head (Flow B - Project Internal)');

            $pr = PurchaseRequisition::create([
                'number' => $this->docNumbering->generate('pr'),
                'date' => now()->toDateString(),
                'source_type' => 'mr',
                'source_id' => $materialRequest->id,
                'pr_type' => $materialRequest->source_type === 'project_internal' ? 'project' : 'non_project',
                'status' => 'auto_created',
                'pihak1_id' => $materialRequest->requestor_id,
            ]);

            $flaggedItems = $materialRequest->lineItems()->where('flagged', true)->get();
            if ($flaggedItems->isEmpty()) {
                $flaggedItems = $materialRequest->lineItems;
            }

            foreach ($flaggedItems as $item) {
                PrLineItem::create([
                    'pr_id' => $pr->id,
                    'item_name' => $item->item->name,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'description' => $item->description,
                ]);
            }

            $materialRequest->update(['status' => 'pr_created', 'pr_id' => $pr->id]);
            $this->auditTrail->log('mr', $materialRequest->id, $request->user()->id, 'approved', 'pr_created', 'PR auto-generated: ' . $pr->number);

            $pihak1Role = $pr->pr_type === 'project' ? 'user' : 'pihak_1';
            $this->notificationService->notifyUsersWithRole(
                $pihak1Role,
                'pr_pending_pricing',
                'PR Created - Pricing Required',
                "PR {$pr->number} has been created. Pihak I pricing is required.",
                'pr',
                $pr->id
            );

            $this->notificationService->notify(
                $materialRequest->requestor_id,
                'mr_approved',
                'MR Approved - PR Generated',
                "MR {$materialRequest->number} has been approved. PR {$pr->number} has been created.",
                'mr',
                $materialRequest->id
            );

            return response()->json([
                'message' => $isPihak2 ? 'MR approved by Pihak II. PR generated.' : 'MR approved. PR generated automatically (Flow B).',
                'mr' => $materialRequest->fresh()->load('lineItems.item', 'requestor', 'department'),
                'pr' => $pr->load('lineItems'),
            ]);
        });
    }

    private function getPihak2Role(string $sourceType): string
    {
        return match ($sourceType) {
            'internal', 'asset' => 'ga',
            'customer' => 'log',
            default => 'log',
        };
    }
}