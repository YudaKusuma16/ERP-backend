<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryInstruction;
use App\Models\DeliveryNote;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryInstructionController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DeliveryInstruction::with('materialRequest', 'creator', 'deliveryNote');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $dis = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($dis);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mr_id' => 'required|exists:material_requests,id',
            'warehouse_id' => 'nullable|string|max:255',
        ]);

        $mr = \App\Models\MaterialRequest::find($validated['mr_id']);
        if (!$mr || !in_array($mr->status, ['approved', 'pr_created'])) {
            return response()->json(['message' => 'MR must be approved to create DI.'], 422);
        }

        return DB::transaction(function () use ($validated, $request) {
            $di = DeliveryInstruction::create([
                'number' => $this->docNumbering->generate('di'),
                'date' => now()->toDateString(),
                'mr_id' => $validated['mr_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('di', $di->id, $request->user()->id, 'created', 'draft', 'DI created from MR ' . $validated['mr_id']);

            return response()->json([
                'message' => 'Delivery Instruction created successfully.',
                'delivery_instruction' => $di->load('materialRequest', 'creator'),
            ], 201);
        });
    }

    public function show(DeliveryInstruction $deliveryInstruction): JsonResponse
    {
        return response()->json([
            'delivery_instruction' => $deliveryInstruction->load('materialRequest.requestor', 'materialRequest.lineItems.item', 'creator', 'deliveryNote', 'approvalLogs.actor'),
        ]);
    }

    public function update(Request $request, DeliveryInstruction $deliveryInstruction): JsonResponse
    {
        if ($deliveryInstruction->status !== 'draft') {
            return response()->json(['message' => 'Only draft DI can be updated.'], 422);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|string|max:255',
        ]);

        $deliveryInstruction->update($validated);
        return response()->json(['message' => 'DI updated.', 'delivery_instruction' => $deliveryInstruction->fresh()]);
    }

    public function issue(DeliveryInstruction $deliveryInstruction): JsonResponse
    {
        if ($deliveryInstruction->status !== 'draft') {
            return response()->json(['message' => 'DI must be in draft status to issue.'], 422);
        }

        return DB::transaction(function () use ($deliveryInstruction) {
            $userId = request()->user()->id;

            $deliveryInstruction->update(['status' => 'issued']);
            $this->auditTrail->log('di', $deliveryInstruction->id, $userId, 'draft', 'issued', 'DI issued');

            $dn = DeliveryNote::where('di_id', $deliveryInstruction->id)->orderBy('id', 'desc')->first();
            if (!$dn) {
                $dn = DeliveryNote::create([
                    'number' => $this->docNumbering->generate('dn'),
                    'date' => now()->toDateString(),
                    'di_id' => $deliveryInstruction->id,
                    'driver' => null,
                    'vehicle' => null,
                    'status' => 'draft',
                    'created_by' => $userId,
                ]);

                $this->auditTrail->log('dn', $dn->id, $userId, 'created', 'draft', 'DN auto-created from issued DI ' . $deliveryInstruction->number);
            }

            return response()->json([
                'message' => 'Delivery Instruction issued. Delivery Note created.',
                'delivery_instruction' => $deliveryInstruction->fresh()->load('deliveryNote'),
                'delivery_note' => $dn->fresh()->load('deliveryInstruction', 'creator'),
            ]);
        });
    }
}