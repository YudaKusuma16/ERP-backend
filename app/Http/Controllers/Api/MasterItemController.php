<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterItem;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterItemController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MasterItem::with('createdBy', 'validatedBy');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $items = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,consumable,spare_part,other',
            'unit' => 'required|string|max:50',
        ]);

        $item = MasterItem::create([
            ...$validated,
            'status' => 'pending_accounting',
            'created_by' => $request->user()->id,
        ]);

        $item->update([
            'barcode' => 'MI' . str_pad($item->id, 6, '0', STR_PAD_LEFT),
        ]);

        $this->auditTrail->log('master_item', $item->id, $request->user()->id, 'inactive', 'pending_accounting', 'Item submitted for accounting validation');

        $this->notificationService->notifyUsersWithRole(
            'accounting',
            'item_pending_validation',
            'New Item Pending Validation',
            "Item '{$item->name}' is pending accounting validation.",
            'master_item',
            $item->id
        );

        return response()->json([
            'message' => 'Item created and submitted for validation.',
            'item' => $item->load('createdBy'),
        ], 201);
    }

    public function show(MasterItem $masterItem): JsonResponse
    {
        return response()->json([
            'item' => $masterItem->load('createdBy', 'validatedBy'),
        ]);
    }

    public function update(Request $request, MasterItem $masterItem): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:asset,consumable,spare_part,other',
            'unit' => 'sometimes|string|max:50',
        ]);

        if ($masterItem->status === 'active' || $masterItem->status === 'pending_accounting') {
            return response()->json(['message' => 'Cannot edit item in current status.'], 422);
        }

        $masterItem->update($validated);

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => $masterItem->fresh(),
        ]);
    }

    public function validateByAccounting(Request $request, MasterItem $masterItem): JsonResponse
    {
        if (!$request->user()->isAccounting()) {
            return response()->json(['message' => 'Only Accounting role can validate items.'], 403);
        }

        if ($masterItem->status !== 'pending_accounting') {
            return response()->json(['message' => 'Item is not pending validation.'], 422);
        }

        $rules = [
            'action' => 'required|in:approve,decline',
            'asset_code' => 'nullable|string|max:255',
        ];

        if ($request->input('action') === 'approve') {
            $rules['category'] = 'required|string|max:255';
            $rules['coa'] = 'required|string|max:255';
        }

        if ($request->input('action') === 'decline') {
            $rules['decline_reason'] = 'required|string';
        }

        $validated = $request->validate($rules);

        if ($validated['action'] === 'approve') {
            $masterItem->update([
                'status' => 'active',
                'category' => $validated['category'],
                'asset_code' => $validated['asset_code'] ?? null,
                'coa' => $validated['coa'],
                'validated_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('master_item', $masterItem->id, $request->user()->id, 'pending_accounting', 'active', 'Item approved by Accounting');

            $this->notificationService->notify(
                $masterItem->created_by,
                'item_approved',
                'Item Approved',
                "Item '{$masterItem->name}' has been approved by Accounting.",
                'master_item',
                $masterItem->id
            );
        } else {
            $masterItem->update([
                'status' => 'declined',
                'decline_reason' => $validated['decline_reason'],
                'validated_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('master_item', $masterItem->id, $request->user()->id, 'pending_accounting', 'declined', $validated['decline_reason']);

            $this->notificationService->notify(
                $masterItem->created_by,
                'item_declined',
                'Item Declined',
                "Item '{$masterItem->name}' has been declined by Accounting. Reason: {$validated['decline_reason']}",
                'master_item',
                $masterItem->id
            );
        }

        return response()->json([
            'message' => 'Item validation processed.',
            'item' => $masterItem->fresh()->load('createdBy', 'validatedBy'),
        ]);
    }

    public function resubmit(Request $request, MasterItem $masterItem): JsonResponse
    {
        if ($masterItem->status !== 'declined') {
            return response()->json(['message' => 'Only declined items can be resubmitted.'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:asset,consumable,spare_part,other',
            'unit' => 'sometimes|string|max:50',
        ]);

        $masterItem->update([
            ...$validated,
            'status' => 'pending_accounting',
            'decline_reason' => null,
        ]);

        $this->auditTrail->log('master_item', $masterItem->id, $request->user()->id, 'declined', 'pending_accounting', 'Item resubmitted for validation');

        $this->notificationService->notifyUsersWithRole(
            'accounting',
            'item_resubmitted',
            'Item Resubmitted for Validation',
            "Item '{$masterItem->name}' has been resubmitted for validation.",
            'master_item',
            $masterItem->id
        );

        return response()->json([
            'message' => 'Item resubmitted for validation.',
            'item' => $masterItem->fresh()->load('createdBy', 'validatedBy'),
        ]);
    }
}