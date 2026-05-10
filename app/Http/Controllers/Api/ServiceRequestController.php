<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\SrLineItem;
use App\Models\PurchaseRequisition;
use App\Models\PrLineItem;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceRequestController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ServiceRequest::with('requestor', 'department', 'lineItems', 'approvedByDeptHead', 'approvedByPihak2');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('source_type')) {
            $query->bySourceType($request->source_type);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $srs = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($srs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => 'required|in:internal,customer,3rd_party,project',
            'source_doc_ref' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.service_name' => 'required|string',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
            'items.*.est_cost' => 'nullable|numeric',
            'items.*.description' => 'nullable|string',
        ]);

        if (in_array($validated['source_type'], ['3rd_party']) && empty($validated['source_doc_ref'])) {
            return response()->json(['message' => 'Source document reference is required for 3rd party SR.'], 422);
        }

        return DB::transaction(function () use ($validated, $request) {
            $sr = ServiceRequest::create([
                'number' => $this->docNumbering->generate('sr'),
                'date' => now()->toDateString(),
                'source_type' => $validated['source_type'],
                'source_doc_ref' => $validated['source_doc_ref'] ?? null,
                'requestor_id' => $request->user()->id,
                'department_id' => $request->user()->department_id,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_dept_head',
            ]);

            foreach ($validated['items'] as $item) {
                SrLineItem::create([
                    'sr_id' => $sr->id,
                    'service_name' => $item['service_name'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'est_cost' => $item['est_cost'] ?? null,
                    'description' => $item['description'] ?? null,
                ]);
            }

            $this->auditTrail->log('sr', $sr->id, $request->user()->id, 'draft', 'pending_dept_head', 'SR submitted for Department Head approval');

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'sr_pending_approval',
                'SR Pending Approval',
                "SR {$sr->number} requires your approval.",
                'sr',
                $sr->id
            );

            return response()->json([
                'message' => 'Service Request created successfully.',
                'sr' => $sr->load('lineItems', 'requestor', 'department'),
            ], 201);
        });
    }

    public function show(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json([
            'sr' => $serviceRequest->load([
                'lineItems',
                'requestor',
                'department',
                'approvedByDeptHead',
                'approvedByPihak2',
                'approvalLogs.actor',
                'purchaseRequisition.deliveryInstruction.deliveryNote',
                'deliveryInstruction.deliveryNote',
            ]),
        ]);
    }

    public function approveByDeptHead(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        if (!$request->user()->isDeptHead()) {
            return response()->json(['message' => 'Only Department Head can approve at this stage.'], 403);
        }

        if ($serviceRequest->status !== 'pending_dept_head') {
            return response()->json(['message' => 'SR is not pending Department Head approval.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $serviceRequest->update([
                'status' => 'declined',
                'decline_reason' => $validated['reason'],
                'approved_by_dept_head' => $request->user()->id,
            ]);

            $this->auditTrail->log('sr', $serviceRequest->id, $request->user()->id, 'pending_dept_head', 'declined', $validated['reason']);

            $this->notificationService->notify(
                $serviceRequest->requestor_id,
                'sr_declined',
                'SR Declined',
                "SR {$serviceRequest->number} has been declined by Department Head.",
                'sr',
                $serviceRequest->id
            );

            return response()->json(['message' => 'SR declined.', 'sr' => $serviceRequest->fresh()]);
        }

        if ($serviceRequest->isFlow4()) {
            return $this->approveAndGeneratePR($serviceRequest, $request, 'Flow 4 - Project');
        }

        $serviceRequest->update([
            'status' => 'pending_pihak_ii',
            'approved_by_dept_head' => $request->user()->id,
        ]);

        $this->auditTrail->log('sr', $serviceRequest->id, $request->user()->id, 'pending_dept_head', 'pending_pihak_ii', 'Approved by Department Head');

        $pihak2Role = $this->getPihak2Role($serviceRequest->source_type);
        $this->notificationService->notifyUsersWithRole(
            $pihak2Role,
            'sr_pending_approval',
            'SR Pending Validation',
            "SR {$serviceRequest->number} requires your validation.",
            'sr',
            $serviceRequest->id
        );

        return response()->json([
            'message' => 'SR approved by Department Head. Forwarded for validation.',
            'sr' => $serviceRequest->fresh()->load('lineItems', 'requestor', 'department'),
        ]);
    }

    public function flagItems(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        if ($serviceRequest->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'SR is not pending Pihak II validation.'], 422);
        }

        $validated = $request->validate([
            'flagged_items' => 'required|array|min:1',
            'flagged_items.*' => 'required|exists:sr_line_items,id',
        ]);

        SrLineItem::where('sr_id', $serviceRequest->id)->update(['flagged' => false, 'flagged_by' => null]);

        foreach ($validated['flagged_items'] as $lineItemId) {
            SrLineItem::where('id', $lineItemId)
                ->where('sr_id', $serviceRequest->id)
                ->update(['flagged' => true, 'flagged_by' => $request->user()->id]);
        }

        return response()->json([
            'message' => 'Services flagged successfully.',
            'sr' => $serviceRequest->fresh()->load('lineItems'),
        ]);
    }

    public function approveByPihak2(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        if ($serviceRequest->status !== 'pending_pihak_ii') {
            return response()->json(['message' => 'SR is not pending Pihak II approval.'], 422);
        }

        $hasFlaggedItems = $serviceRequest->lineItems()->where('flagged', true)->exists();
        if (!$hasFlaggedItems) {
            return response()->json(['message' => 'Please flag at least one service before approving.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $serviceRequest->update([
                'status' => 'declined',
                'decline_reason' => $validated['reason'],
                'approved_by_pihak2' => $request->user()->id,
            ]);

            $this->auditTrail->log('sr', $serviceRequest->id, $request->user()->id, 'pending_pihak_ii', 'declined', $validated['reason']);

            $this->notificationService->notify(
                $serviceRequest->requestor_id,
                'sr_declined',
                'SR Declined',
                "SR {$serviceRequest->number} has been declined.",
                'sr',
                $serviceRequest->id
            );

            return response()->json(['message' => 'SR declined.', 'sr' => $serviceRequest->fresh()]);
        }

        return $this->approveAndGeneratePR($serviceRequest, $request, 'Flow 1/2/3');
    }

    private function approveAndGeneratePR(ServiceRequest $serviceRequest, Request $request, string $flowLabel): JsonResponse
    {
        return DB::transaction(function () use ($serviceRequest, $request, $flowLabel) {
            $serviceRequest->update([
                'status' => 'approved',
                'approved_by_dept_head' => $serviceRequest->approved_by_dept_head ?? $request->user()->id,
                'approved_by_pihak2' => $request->user()->id,
            ]);

            $fromStatus = 'pending_pihak_ii';
            if ($serviceRequest->isFlow4()) {
                $fromStatus = 'pending_dept_head';
            }
            $this->auditTrail->log('sr', $serviceRequest->id, $request->user()->id, $fromStatus, 'approved', "Approved ({$flowLabel})");

            $pr = PurchaseRequisition::create([
                'number' => $this->docNumbering->generate('pr'),
                'date' => now()->toDateString(),
                'source_type' => 'sr',
                'source_id' => $serviceRequest->id,
                'pr_type' => $serviceRequest->source_type === 'project' ? 'project' : 'non_project',
                'status' => 'auto_created',
                'pihak1_id' => $serviceRequest->requestor_id,
            ]);

            $flaggedItems = $serviceRequest->lineItems()->where('flagged', true)->get();
            if ($flaggedItems->isEmpty()) {
                $flaggedItems = $serviceRequest->lineItems;
            }

            foreach ($flaggedItems as $item) {
                PrLineItem::create([
                    'pr_id' => $pr->id,
                    'item_name' => $item->service_name,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'description' => $item->description,
                ]);
            }

            $serviceRequest->update(['status' => 'pr_created', 'pr_id' => $pr->id]);
            $this->auditTrail->log('sr', $serviceRequest->id, $request->user()->id, 'approved', 'pr_created', 'PR auto-generated: ' . $pr->number);

            $pihak1Role = $pr->pr_type === 'project' ? 'user' : 'pihak_1';
            $this->notificationService->notifyUsersWithRole(
                $pihak1Role,
                'pr_pending_pricing',
                'PR Created - Pricing Required',
                "PR {$pr->number} has been created from SR. Pihak I pricing is required.",
                'pr',
                $pr->id
            );

            $this->notificationService->notify(
                $serviceRequest->requestor_id,
                'sr_approved',
                'SR Approved - PR Generated',
                "SR {$serviceRequest->number} has been approved. PR {$pr->number} has been created.",
                'sr',
                $serviceRequest->id
            );

            return response()->json([
                'message' => "SR approved ({$flowLabel}). PR generated.",
                'sr' => $serviceRequest->fresh()->load('lineItems', 'requestor', 'department'),
                'pr' => $pr->load('lineItems'),
            ]);
        });
    }

    private function getPihak2Role(string $sourceType): string
    {
        return match ($sourceType) {
            'internal' => 'ga',
            'customer' => 'log',
            '3rd_party' => 'log',
            default => 'log',
        };
    }
}