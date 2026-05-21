<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcceptanceLetter;
use App\Models\AlLineItem;
use App\Models\MasterItem;
use App\Models\MaterialRequest;
use App\Models\MrLineItem;
use App\Models\OrderRequestForm;
use App\Models\WorkOrder;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::with('pic', 'creator', 'acceptanceLetter', 'orderRequestForm');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $workOrders = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($workOrders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orf_id' => 'nullable|exists:order_request_forms,id',
            'orf_ref' => 'nullable|string|max:255',
            'job_details' => 'nullable|string',
            'pic_id' => 'nullable|exists:users,id',
            'service_type' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['orf_id'])) {
            $orf = OrderRequestForm::find($validated['orf_id']);
            if (!$orf || $orf->status !== 'approved') {
                return response()->json(['message' => 'Selected ORF must be approved.'], 422);
            }
            $validated['orf_ref'] = $orf->number;
        }

        return DB::transaction(function () use ($validated, $request) {
            $wo = WorkOrder::create([
                'number' => $this->docNumbering->generate('wo'),
                'date' => now()->toDateString(),
                'orf_id' => $validated['orf_id'] ?? null,
                'orf_ref' => $validated['orf_ref'] ?? null,
                'job_details' => $validated['job_details'] ?? null,
                'pic_id' => $validated['pic_id'] ?? null,
                'service_type' => $validated['service_type'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('wo', $wo->id, $request->user()->id, 'created', 'draft', 'Work Order created');

            return response()->json([
                'message' => 'Work Order created successfully.',
                'work_order' => $wo->load('pic', 'creator', 'orderRequestForm'),
            ], 201);
        });
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        return response()->json([
            'work_order' => $workOrder->load('pic', 'creator', 'orderRequestForm', 'materialRequests.lineItems.item', 'acceptanceLetter.lineItems.item', 'approvalLogs.actor'),
        ]);
    }

    public function update(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if (!in_array($workOrder->status, ['draft', 'declined'])) {
            return response()->json(['message' => 'Work Order cannot be edited in current status.'], 422);
        }

        $validated = $request->validate([
            'orf_id' => 'nullable|exists:order_request_forms,id',
            'orf_ref' => 'nullable|string|max:255',
            'job_details' => 'nullable|string',
            'pic_id' => 'nullable|exists:users,id',
            'service_type' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['orf_id'])) {
            $orf = OrderRequestForm::find($validated['orf_id']);
            if (!$orf || $orf->status !== 'approved') {
                return response()->json(['message' => 'Selected ORF must be approved.'], 422);
            }
            $validated['orf_ref'] = $orf->number;
        }

        $workOrder->update($validated);
        return response()->json(['message' => 'Work Order updated.', 'work_order' => $workOrder->fresh()->load('pic', 'creator', 'orderRequestForm')]);
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft Work Orders can be deleted.'], 422);
        }
        $workOrder->delete();
        return response()->json(['message' => 'Work Order deleted.']);
    }

    public function storeMaterialRequest(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if (!$request->user()->department_id) {
            return response()->json(['message' => 'Your user account must have a department to create a Material Request.'], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|exists:master_items,id',
            'items.*.item_name' => 'nullable|string|max:255',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
            'items.*.description' => 'nullable|string',
        ]);

        foreach ($validated['items'] as $line) {
            if (empty($line['item_id']) && empty($line['item_name'])) {
                return response()->json(['message' => 'Each line item must select an item or input item name.'], 422);
            }
        }

        $selectedItemIds = collect($validated['items'])->pluck('item_id')->filter()->values();
        $inactiveItems = MasterItem::whereIn('id', $selectedItemIds)->where('status', '!=', 'active')->exists();
        if ($inactiveItems) {
            return response()->json(['message' => 'All items must have ACTIVE status.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $workOrder) {
            $mr = MaterialRequest::create([
                'number' => $this->docNumbering->generate('mr'),
                'date' => now()->toDateString(),
                'source_type' => 'wo',
                'wo_id' => $workOrder->id,
                'requestor_id' => $request->user()->id,
                'department_id' => $request->user()->department_id,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_dept_head',
            ]);

            foreach ($validated['items'] as $item) {
                $itemModel = null;
                if (!empty($item['item_id'])) {
                    $itemModel = MasterItem::find($item['item_id']);
                }
                MrLineItem::create([
                    'mr_id' => $mr->id,
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $itemModel?->name ?? ($item['item_name'] ?? null),
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'description' => $item['description'] ?? null,
                ]);
            }

            $this->auditTrail->log('mr', $mr->id, $request->user()->id, 'draft', 'pending_dept_head', "MR created from WO {$workOrder->number}");

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'mr_pending_approval',
                'MR Pending Approval',
                "MR {$mr->number} (from WO {$workOrder->number}) requires your approval.",
                'mr',
                $mr->id
            );

            return response()->json([
                'message' => 'Material Request created successfully.',
                'material_request' => $mr->load('lineItems.item', 'requestor', 'department'),
            ], 201);
        });
    }

    public function submitForApproval(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status !== 'draft') {
            return response()->json(['message' => 'Work Order must be in draft status to submit.'], 422);
        }

        return DB::transaction(function () use ($workOrder, $request) {
            $fromStatus = $workOrder->status;
            $workOrder->update(['status' => 'pending_approval']);
            $this->auditTrail->log('wo', $workOrder->id, $request->user()->id, $fromStatus, 'pending_approval', 'Submitted for approval');

            $this->notificationService->notifyUsersWithRole('dept_head', 'wo_pending_approval', 'Work Order Pending Approval', "WO {$workOrder->number} is pending approval.", 'wo', $workOrder->id);

            return response()->json(['message' => 'Work Order submitted for approval.', 'work_order' => $workOrder->fresh()]);
        });
    }

    public function approve(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Work Order must be pending approval.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        return DB::transaction(function () use ($workOrder, $validated, $request) {
            if ($validated['action'] === 'decline') {
                $fromStatus = $workOrder->status;
                $workOrder->update(['status' => 'declined', 'decline_reason' => $validated['reason'] ?? '']);
                $this->auditTrail->log('wo', $workOrder->id, $request->user()->id, $fromStatus, 'declined', $validated['reason'] ?? 'WO declined');
                return response()->json(['message' => 'Work Order declined.', 'work_order' => $workOrder->fresh()]);
            }

            $fromStatus = $workOrder->status;
            $workOrder->update(['status' => 'approved']);
            $this->auditTrail->log('wo', $workOrder->id, $request->user()->id, $fromStatus, 'approved', 'WO approved');

            $al = AcceptanceLetter::create([
                'number' => $this->docNumbering->generate('al'),
                'date' => now()->toDateString(),
                'wo_id' => $workOrder->id,
                'status' => 'auto_created',
                'created_by' => $request->user()->id,
            ]);

            $workOrder->update(['status' => 'al_generated']);
            $this->auditTrail->log('wo', $workOrder->id, $request->user()->id, 'approved', 'al_generated', 'AL generated: ' . $al->number);

            $this->notificationService->notify(
                $workOrder->created_by,
                'wo_approved',
                'Work Order Approved',
                "WO {$workOrder->number} has been approved. AL {$al->number} generated.",
                'wo',
                $workOrder->id
            );

            return response()->json([
                'message' => 'Work Order approved. Acceptance Letter generated.',
                'work_order' => $workOrder->fresh()->load('acceptanceLetter'),
                'acceptance_letter' => $al,
            ]);
        });
    }
}