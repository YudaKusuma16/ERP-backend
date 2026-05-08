<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryInstruction;
use App\Models\MasterItem;
use App\Models\MaterialRequest;
use App\Models\MrLineItem;
use App\Models\SalesOrder;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with('creator');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', '%' . $search . '%')
                    ->orWhere('customer_name', 'like', '%' . $search . '%');
            });
        }

        $sos = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($sos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $so = SalesOrder::create([
                'number' => $this->docNumbering->generate('so'),
                'date' => now()->toDateString(),
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('so', $so->id, $request->user()->id, 'created', 'draft', 'SO created');

            return response()->json([
                'message' => 'Sales Order created successfully.',
                'so' => $so->load('creator'),
            ], 201);
        });
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        return response()->json([
            'so' => $salesOrder->load('creator'),
        ]);
    }

    public function update(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if (!in_array($salesOrder->status, ['draft', 'declined'])) {
            return response()->json(['message' => 'SO cannot be edited in current status.'], 422);
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $salesOrder->update($validated);
        return response()->json(['message' => 'SO updated.', 'so' => $salesOrder->fresh()->load('creator')]);
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft SOs can be deleted.'], 422);
        }
        $salesOrder->delete();
        return response()->json(['message' => 'SO deleted.']);
    }

    public function submit(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'SO must be in draft status to submit.'], 422);
        }

        $salesOrder->update(['status' => 'submitted']);
        $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, 'draft', 'submitted', 'SO submitted');

        $this->notificationService->notifyUsersWithRole(
            'dept_head',
            'so_submitted',
            'SO Submitted',
            "SO {$salesOrder->number} is submitted for review.",
            'so',
            $salesOrder->id
        );

        return response()->json(['message' => 'SO submitted.', 'so' => $salesOrder->fresh()]);
    }

    public function approve(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'submitted') {
            return response()->json(['message' => 'SO must be submitted to approve/decline.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $salesOrder->update(['status' => 'declined', 'decline_reason' => $validated['reason'] ?? '']);
            $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, 'submitted', 'declined', $validated['reason'] ?? 'SO declined');
            return response()->json(['message' => 'SO declined.', 'so' => $salesOrder->fresh()]);
        }

        $salesOrder->update(['status' => 'approved']);
        $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, 'submitted', 'approved', 'SO approved');

        return response()->json(['message' => 'SO approved.', 'so' => $salesOrder->fresh()]);
    }

    public function createMaterialRequest(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'approved') {
            return response()->json(['message' => 'SO must be approved to create MR.'], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|exists:master_items,id',
            'items.*.item_name' => 'required_without:items.*.item_id|string|max:255',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
            'items.*.description' => 'nullable|string',
        ]);

        $itemIds = collect($validated['items'])->pluck('item_id')->filter()->values();
        $inactiveItems = $itemIds->isNotEmpty()
            ? MasterItem::whereIn('id', $itemIds)->where('status', '!=', 'active')->exists()
            : false;
        if ($inactiveItems) {
            return response()->json(['message' => 'All items must have ACTIVE status.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $salesOrder) {
            $mr = MaterialRequest::create([
                'number' => $this->docNumbering->generate('mr'),
                'date' => now()->toDateString(),
                'source_type' => 'so',
                'so_id' => $salesOrder->id,
                'requestor_id' => $request->user()->id,
                'department_id' => $request->user()->department_id,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_dept_head',
            ]);

            foreach ($validated['items'] as $item) {
                $itemModel = !empty($item['item_id']) ? MasterItem::find($item['item_id']) : null;
                MrLineItem::create([
                    'mr_id' => $mr->id,
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $itemModel?->name ?? ($item['item_name'] ?? null),
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'description' => $item['description'] ?? null,
                ]);
            }

            $this->auditTrail->log('mr', $mr->id, $request->user()->id, 'draft', 'pending_dept_head', 'MR created from SO ' . $salesOrder->number);

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'mr_pending_approval',
                'MR Pending Approval',
                "MR {$mr->number} (source SO {$salesOrder->number}) requires your approval.",
                'mr',
                $mr->id
            );

            // Auto-create DI draft from MR so it appears in Delivery Instructions immediately.
            $di = DeliveryInstruction::create([
                'number' => $this->docNumbering->generate('di'),
                'date' => now()->toDateString(),
                'mr_id' => $mr->id,
                'warehouse_id' => null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $this->auditTrail->log('di', $di->id, $request->user()->id, 'created', 'draft', 'DI created from SO ' . $salesOrder->number);

            return response()->json([
                'message' => 'Material Request created from Sales Order.',
                'mr' => $mr->load('lineItems.item', 'requestor', 'department'),
                'delivery_instruction' => $di->load('materialRequest', 'creator'),
            ], 201);
        });
    }
}

