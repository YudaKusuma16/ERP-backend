<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryInstruction;
use App\Models\MaterialRequest;
use App\Models\ServiceRequest;
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
        $query = DeliveryInstruction::with('materialRequest', 'serviceRequest', 'creator', 'deliveryNote');

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
            'mr_id' => 'nullable|exists:material_requests,id',
            'sr_id' => 'nullable|exists:service_requests,id',
            'warehouse_id' => 'nullable|string|max:255',
        ]);

        if (empty($validated['mr_id']) && empty($validated['sr_id'])) {
            return response()->json(['message' => 'Material Request or Service Request is required.'], 422);
        }

        if (!empty($validated['mr_id']) && !empty($validated['sr_id'])) {
            return response()->json(['message' => 'Provide only one source: MR or SR.'], 422);
        }

        if (!empty($validated['sr_id'])) {
            return $this->storeFromServiceRequest($validated, $request);
        }

        return $this->storeFromMaterialRequest($validated, $request);
    }

    private function storeFromMaterialRequest(array $validated, Request $request): JsonResponse
    {
        $mr = MaterialRequest::find($validated['mr_id']);
        $allowedStatuses = $mr?->isAssetDeliveryFlow()
            ? ['approved']
            : ['approved', 'pr_created'];

        if (!$mr || !in_array($mr->status, $allowedStatuses, true)) {
            return response()->json(['message' => 'MR must be approved to create DI.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $mr) {
            $di = DeliveryInstruction::create([
                'number' => $this->docNumbering->generate('di'),
                'date' => now()->toDateString(),
                'mr_id' => $mr->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('di', $di->id, $request->user()->id, 'created', 'draft', 'DI created from MR ' . $mr->number);

            return response()->json([
                'message' => 'Delivery Instruction created successfully.',
                'delivery_instruction' => $di->load('materialRequest', 'creator'),
            ], 201);
        });
    }

    private function storeFromServiceRequest(array $validated, Request $request): JsonResponse
    {
        $sr = ServiceRequest::find($validated['sr_id']);

        if (!$sr || !$sr->isVendorRepairFlow()) {
            return response()->json(['message' => 'DI from SR is only for 3rd Party (vendor repair) service requests.'], 422);
        }

        if ($sr->status !== 'approved') {
            return response()->json(['message' => 'Service Request must be approved to create DI.'], 422);
        }

        if ($sr->deliveryInstruction()->exists()) {
            return response()->json(['message' => 'This Service Request already has a Delivery Instruction.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $sr) {
            $di = DeliveryInstruction::create([
                'number' => $this->docNumbering->generate('di'),
                'date' => now()->toDateString(),
                'sr_id' => $sr->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('di', $di->id, $request->user()->id, 'created', 'draft', 'DI created from SR ' . $sr->number);

            return response()->json([
                'message' => 'Delivery Instruction created successfully.',
                'delivery_instruction' => $di->load('serviceRequest', 'creator'),
            ], 201);
        });
    }

    public function show(DeliveryInstruction $deliveryInstruction): JsonResponse
    {
        return response()->json([
            'delivery_instruction' => $deliveryInstruction->load(
                'materialRequest.requestor',
                'serviceRequest.requestor',
                'creator',
                'deliveryNote',
                'approvalLogs.actor'
            ),
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

        $deliveryInstruction->update(['status' => 'issued']);
        $this->auditTrail->log('di', $deliveryInstruction->id, request()->user()->id, 'draft', 'issued', 'DI issued');

        return response()->json(['message' => 'Delivery Instruction issued.', 'delivery_instruction' => $deliveryInstruction->fresh()]);
    }
}
