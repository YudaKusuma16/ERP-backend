<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcceptanceLetter;
use App\Models\AlLineItem;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcceptanceLetterController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AcceptanceLetter::with('workOrder.pic', 'creator', 'lineItems.item');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $als = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($als);
    }

    public function show(AcceptanceLetter $acceptanceLetter): JsonResponse
    {
        return response()->json([
            'acceptance_letter' => $acceptanceLetter->load('workOrder.pic', 'creator', 'lineItems.item', 'approvalLogs.actor'),
        ]);
    }

    public function addLineItems(Request $request, AcceptanceLetter $acceptanceLetter): JsonResponse
    {
        if (!in_array($acceptanceLetter->status, ['auto_created', 'pending_approval'])) {
            return response()->json(['message' => 'Cannot add line items in current status.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|exists:master_items,id',
            'items.*.item_name' => 'required|string',
            'items.*.item_status' => 'nullable|in:terpasang,ex_remote,tidak_jadi',
            'items.*.location' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $acceptanceLetter) {
            foreach ($validated['items'] as $item) {
                AlLineItem::create([
                    'al_id' => $acceptanceLetter->id,
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'item_status' => $item['item_status'] ?? 'terpasang',
                    'location' => $item['location'] ?? null,
                ]);
            }

            if ($acceptanceLetter->status === 'auto_created') {
                $acceptanceLetter->update(['status' => 'pending_approval']);
            }

            return response()->json([
                'message' => 'Line items added.',
                'acceptance_letter' => $acceptanceLetter->fresh()->load('lineItems.item'),
            ]);
        });
    }

    public function updateLineItems(Request $request, AcceptanceLetter $acceptanceLetter): JsonResponse
    {
        if ($acceptanceLetter->status !== 'in_progress') {
            return response()->json(['message' => 'Line items can only be updated when AL is in progress.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:al_line_items,id',
            'items.*.item_status' => 'required|in:terpasang,ex_remote,tidak_jadi',
            'items.*.location' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $acceptanceLetter) {
            foreach ($validated['items'] as $itemData) {
                AlLineItem::where('id', $itemData['id'])
                    ->where('al_id', $acceptanceLetter->id)
                    ->update([
                        'item_status' => $itemData['item_status'],
                        'location' => $itemData['location'] ?? null,
                    ]);
            }

            return response()->json([
                'message' => 'Line items updated.',
                'acceptance_letter' => $acceptanceLetter->fresh()->load('lineItems.item'),
            ]);
        });
    }

    public function approve(Request $request, AcceptanceLetter $acceptanceLetter): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($acceptanceLetter->status === 'pending_approval') {
            if ($validated['action'] === 'decline') {
                $acceptanceLetter->update(['status' => 'declined', 'decline_reason' => $validated['reason'] ?? '']);
                $this->auditTrail->log('al', $acceptanceLetter->id, $request->user()->id, 'pending_approval', 'declined', $validated['reason'] ?? 'AL declined');
                return response()->json(['message' => 'Acceptance Letter declined.', 'acceptance_letter' => $acceptanceLetter->fresh()]);
            }

            $acceptanceLetter->update(['status' => 'approved']);
            $this->auditTrail->log('al', $acceptanceLetter->id, $request->user()->id, 'pending_approval', 'approved', 'AL approved');
            return response()->json(['message' => 'Acceptance Letter approved.', 'acceptance_letter' => $acceptanceLetter->fresh()]);
        }

        if ($acceptanceLetter->status === 'approved') {
            $acceptanceLetter->update(['status' => 'in_progress']);
            $this->auditTrail->log('al', $acceptanceLetter->id, $request->user()->id, 'approved', 'in_progress', 'AL moved to in progress');
            return response()->json(['message' => 'Acceptance Letter moved to in progress.', 'acceptance_letter' => $acceptanceLetter->fresh()]);
        }

        if ($acceptanceLetter->status === 'in_progress') {
            $acceptanceLetter->update(['status' => 'completed']);
            $this->auditTrail->log('al', $acceptanceLetter->id, $request->user()->id, 'in_progress', 'completed', 'AL completed');

            $this->notificationService->notify(
                $acceptanceLetter->workOrder->created_by,
                'al_completed',
                'Acceptance Letter Completed',
                "AL {$acceptanceLetter->number} has been completed.",
                'al',
                $acceptanceLetter->id
            );

            return response()->json(['message' => 'Acceptance Letter completed.', 'acceptance_letter' => $acceptanceLetter->fresh()]);
        }

        return response()->json(['message' => 'Invalid status for this action.'], 422);
    }
}