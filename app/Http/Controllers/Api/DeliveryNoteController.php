<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryInstruction;
use App\Models\DeliveryNote;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryNoteController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DeliveryNote::with([
            'deliveryInstruction.materialRequest',
            'deliveryInstruction.serviceRequest',
            'deliveryInstruction.purchaseRequisition.sourceSr',
            'creator',
        ]);

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $dns = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($dns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'di_id' => 'required|exists:delivery_instructions,id',
            'driver' => 'nullable|string|max:255',
            'vehicle' => 'nullable|string|max:255',
        ]);

        $di = DeliveryInstruction::find($validated['di_id']);
        if (!$di || !in_array($di->status, ['issued'])) {
            return response()->json(['message' => 'DI must be issued to create DN.'], 422);
        }

        return DB::transaction(function () use ($validated, $request) {
            $dn = DeliveryNote::create([
                'number' => $this->docNumbering->generate('dn'),
                'date' => now()->toDateString(),
                'di_id' => $validated['di_id'],
                'driver' => $validated['driver'] ?? null,
                'vehicle' => $validated['vehicle'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('dn', $dn->id, $request->user()->id, 'created', 'draft', 'DN created from DI ' . $validated['di_id']);

            return response()->json([
                'message' => 'Delivery Note created successfully.',
                'delivery_note' => $dn->load('deliveryInstruction', 'creator'),
            ], 201);
        });
    }

    public function show(DeliveryNote $deliveryNote): JsonResponse
    {
        return response()->json([
            'delivery_note' => $deliveryNote->load([
                'deliveryInstruction.materialRequest.requestor',
                'deliveryInstruction.materialRequest.lineItems.item',
                'deliveryInstruction.serviceRequest.requestor',
                'deliveryInstruction.serviceRequest.lineItems',
                'deliveryInstruction.purchaseRequisition.sourceSr',
                'deliveryInstruction.purchaseRequisition.lineItems',
                'creator',
                'approvalLogs.actor',
            ]),
        ]);
    }

    public function update(Request $request, DeliveryNote $deliveryNote): JsonResponse
    {
        if ($deliveryNote->status !== 'draft') {
            return response()->json(['message' => 'Only draft DN can be updated.'], 422);
        }

        $validated = $request->validate([
            'driver' => 'nullable|string|max:255',
            'vehicle' => 'nullable|string|max:255',
        ]);

        $deliveryNote->update($validated);
        return response()->json(['message' => 'DN updated.', 'delivery_note' => $deliveryNote->fresh()]);
    }

    public function dispatch(DeliveryNote $deliveryNote): JsonResponse
    {
        if ($deliveryNote->status !== 'draft') {
            return response()->json(['message' => 'DN must be in draft status to dispatch.'], 422);
        }

        $deliveryNote->update(['status' => 'dispatched']);
        $this->auditTrail->log('dn', $deliveryNote->id, request()->user()->id, 'draft', 'dispatched', 'DN dispatched');

        return response()->json(['message' => 'Delivery Note dispatched.', 'delivery_note' => $deliveryNote->fresh()]);
    }
}