<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceivingDocument;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReceivingDocumentController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ReceivingDocument::with('preReceivingDocument.purchaseOrder.vendor', 'lineItems');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $rds = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($rds);
    }

    public function show(ReceivingDocument $receivingDocument): JsonResponse
    {
        return response()->json([
            'rd' => $receivingDocument->load('preReceivingDocument.lines', 'preReceivingDocument.purchaseOrder.vendor', 'lineItems', 'approvalLogs.actor'),
        ]);
    }

    public function inputSerialNumbers(Request $request, ReceivingDocument $receivingDocument): JsonResponse
    {
        if (!in_array($receivingDocument->status, ['pending_input', 'validating'])) {
            return response()->json(['message' => 'RD must be in pending_input or validating status.'], 422);
        }

        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.id' => 'required|exists:rd_line_items,id',
            'lines.*.serial_number' => 'nullable|string',
            'lines.*.location' => 'nullable|string',
            'lines.*.condition_notes' => 'nullable|string',
        ]);

        if ($receivingDocument->status === 'pending_input') {
            $receivingDocument->update(['status' => 'validating']);
            $this->auditTrail->log('rd', $receivingDocument->id, $request->user()->id, 'pending_input', 'validating', 'Serial number input started');
        }

        foreach ($validated['lines'] as $lineData) {
            $line = \App\Models\RdLineItem::where('id', $lineData['id'])
                ->where('rd_id', $receivingDocument->id)
                ->first();

            if ($line) {
                $line->update(array_filter([
                    'serial_number' => $lineData['serial_number'] ?? null,
                    'location' => $lineData['location'] ?? null,
                    'condition_notes' => $lineData['condition_notes'] ?? null,
                ], fn($v) => !is_null($v)));
            }
        }

        return response()->json([
            'message' => 'Serial numbers saved. RD is in validating status.',
            'rd' => $receivingDocument->fresh()->load('lineItems'),
        ]);
    }

    public function approve(Request $request, ReceivingDocument $receivingDocument): JsonResponse
    {
        if ($receivingDocument->status !== 'validating') {
            return response()->json(['message' => 'RD must be in validating status to approve.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        if ($validated['action'] === 'decline') {
            $fromStatus = $receivingDocument->status;
            $receivingDocument->update(['status' => 'declined']);
            $this->auditTrail->log('rd', $receivingDocument->id, $request->user()->id, $fromStatus, 'declined', $validated['reason'] ?? 'RD declined');
            return response()->json(['message' => 'RD declined.', 'rd' => $receivingDocument->fresh()]);
        }

        $receivingDocument->update(['status' => 'approved']);
        $this->auditTrail->log('rd', $receivingDocument->id, $request->user()->id, 'validating', 'approved', 'RD approved');

        foreach ($receivingDocument->lineItems as $line) {
            if ($line->serial_number) {
                $tagNumber = 'TAG-' . now()->format('Ym') . '-' . str_pad($line->id, 4, '0', STR_PAD_LEFT);
                $line->update(['tag_number' => $tagNumber]);
            }
        }

        $receivingDocument->update(['status' => 'asset_tagged']);
        $this->auditTrail->log('rd', $receivingDocument->id, $request->user()->id, 'approved', 'asset_tagged', 'Tag numbers generated');

        $this->notificationService->notify(
            $receivingDocument->preReceivingDocument->pihak1_id ?? $receivingDocument->preReceivingDocument->purchaseOrder->created_by,
            'rd_approved',
            'Receiving Document Approved',
            "RD {$receivingDocument->number} has been approved and asset tag numbers generated.",
            'rd',
            $receivingDocument->id
        );

        return response()->json([
            'message' => 'RD approved. Asset tag numbers generated.',
            'rd' => $receivingDocument->fresh()->load('lineItems', 'preReceivingDocument.purchaseOrder.vendor'),
        ]);
    }
}