<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', $search)
                    ->orWhere('customer_name', 'like', $search);
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($orders);
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

            $this->auditTrail->log('so', $so->id, $request->user()->id, 'created', 'draft', 'Sales Order created');

            return response()->json([
                'message' => 'Sales Order created successfully.',
                'so' => $so->load('creator'),
            ], 201);
        });
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        return response()->json([
            'so' => $salesOrder->load(
                'creator',
                'approvalLogs.actor',
                'materialRequests.lineItems.item',
                'materialRequests.deliveryInstruction.deliveryNote',
            ),
        ]);
    }

    public function update(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if (!in_array($salesOrder->status, ['draft', 'declined'])) {
            return response()->json(['message' => 'Sales Order cannot be edited in current status.'], 422);
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $salesOrder->update($validated);

        return response()->json([
            'message' => 'Sales Order updated.',
            'so' => $salesOrder->fresh()->load('creator'),
        ]);
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft Sales Orders can be deleted.'], 422);
        }

        $salesOrder->delete();

        return response()->json(['message' => 'Sales Order deleted.']);
    }

    public function submit(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Sales Order must be in draft status to submit.'], 422);
        }

        return DB::transaction(function () use ($salesOrder, $request) {
            $fromStatus = $salesOrder->status;
            $salesOrder->update(['status' => 'submitted']);
            $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, $fromStatus, 'submitted', 'SO submitted for approval');

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'so_pending_approval',
                'SO Pending Approval',
                "SO {$salesOrder->number} is pending approval.",
                'so',
                $salesOrder->id
            );

            return response()->json([
                'message' => 'Sales Order submitted for approval.',
                'so' => $salesOrder->fresh()->load('creator'),
            ]);
        });
    }

    public function approve(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'submitted') {
            return response()->json(['message' => 'Sales Order must be submitted to approve or decline.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        return DB::transaction(function () use ($salesOrder, $validated, $request) {
            if ($validated['action'] === 'decline') {
                $fromStatus = $salesOrder->status;
                $salesOrder->update([
                    'status' => 'declined',
                    'decline_reason' => $validated['reason'] ?? '',
                ]);
                $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, $fromStatus, 'declined', $validated['reason'] ?? 'SO declined');

                if ($salesOrder->created_by) {
                    $this->notificationService->notify(
                        $salesOrder->created_by,
                        'so_declined',
                        'SO Declined',
                        "SO {$salesOrder->number} has been declined.",
                        'so',
                        $salesOrder->id
                    );
                }

                return response()->json([
                    'message' => 'Sales Order declined.',
                    'so' => $salesOrder->fresh()->load('creator'),
                ]);
            }

            $fromStatus = $salesOrder->status;
            $salesOrder->update(['status' => 'approved', 'decline_reason' => null]);
            $this->auditTrail->log('so', $salesOrder->id, $request->user()->id, $fromStatus, 'approved', 'SO approved');

            if ($salesOrder->created_by) {
                $this->notificationService->notify(
                    $salesOrder->created_by,
                    'so_approved',
                    'SO Approved',
                    "SO {$salesOrder->number} has been approved.",
                    'so',
                    $salesOrder->id
                );
            }

            return response()->json([
                'message' => 'Sales Order approved.',
                'so' => $salesOrder->fresh()->load('creator'),
            ]);
        });
    }

    public function storeMaterialRequest(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($salesOrder->status !== 'approved') {
            return response()->json(['message' => 'Sales Order must be approved before creating a Material Request.'], 422);
        }

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

            $this->auditTrail->log('mr', $mr->id, $request->user()->id, 'draft', 'pending_dept_head', "MR created from SO {$salesOrder->number}");

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'mr_pending_approval',
                'MR Pending Approval',
                "MR {$mr->number} (from SO {$salesOrder->number}) requires your approval.",
                'mr',
                $mr->id
            );

            return response()->json([
                'message' => 'Material Request created successfully.',
                'material_request' => $mr->load('lineItems.item', 'requestor', 'department'),
            ], 201);
        });
    }
}
