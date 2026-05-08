<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcceptanceLetter;
use App\Models\AlLineItem;
use App\Models\DeliveryInstruction;
use App\Models\DeliveryNote;
use App\Models\MaterialRequest;
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
        $query = DeliveryNote::with('deliveryInstruction.materialRequest', 'creator');

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
            'delivery_note' => $deliveryNote->load('deliveryInstruction.materialRequest.requestor', 'deliveryInstruction.materialRequest.lineItems.item', 'creator', 'approvalLogs.actor'),
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

        return DB::transaction(function () use ($deliveryNote) {
            $userId = request()->user()->id;

            $deliveryNote->update(['status' => 'dispatched']);
            $this->auditTrail->log('dn', $deliveryNote->id, $userId, 'draft', 'dispatched', 'DN dispatched');

            $deliveryNote->loadMissing('deliveryInstruction.materialRequest.workOrder.acceptanceLetter');
            $wo = $deliveryNote->deliveryInstruction?->materialRequest?->workOrder;
            $al = $wo?->acceptanceLetter;
            $mr = $deliveryNote->deliveryInstruction?->materialRequest;

            // Ensure AL exists and becomes visible after DN dispatched.
            if ($wo && !$al) {
                $al = AcceptanceLetter::create([
                    'number' => $this->docNumbering->generate('al'),
                    'date' => now()->toDateString(),
                    'wo_id' => $wo->id,
                    'status' => 'pending_approval',
                    'created_by' => $userId,
                ]);
                $this->auditTrail->log('al', $al->id, $userId, 'created', 'pending_approval', 'AL created after DN dispatched: ' . $deliveryNote->number);

                if ($wo->status !== 'al_generated') {
                    $woFrom = $wo->status;
                    $wo->update(['status' => 'al_generated']);
                    $this->auditTrail->log('wo', $wo->id, $userId, $woFrom, 'al_generated', 'AL generated after DN dispatched: ' . $al->number);
                }
            } elseif ($al && $al->status === 'auto_created') {
                $fromStatus = $al->status;
                $al->update(['status' => 'pending_approval']);
                $this->auditTrail->log('al', $al->id, $userId, $fromStatus, 'pending_approval', 'AL moved to pending approval after DN dispatched: ' . $deliveryNote->number);
            }

            // Auto-fill AL line items from MR once (so AL doesn't require re-input).
            if ($al && $mr && $al->lineItems()->count() === 0) {
                $mrItems = $mr->lineItems()->with('item')->get();
                foreach ($mrItems as $mri) {
                    $resolvedName = $mri->item?->name ?? $mri->item_name ?? 'N/A';
                    AlLineItem::create([
                        'al_id' => $al->id,
                        'item_id' => $mri->item_id,
                        'item_name' => $resolvedName,
                        'item_status' => 'terpasang',
                        'location' => null,
                    ]);
                }
                $this->auditTrail->log('al', $al->id, $userId, 'pending_approval', 'pending_approval', 'AL line items auto-filled from MR ' . $mr->number);
            }

            return response()->json([
                'message' => 'Delivery Note dispatched.',
                'delivery_note' => $deliveryNote->fresh()->load('deliveryInstruction.materialRequest.workOrder.acceptanceLetter'),
                'acceptance_letter' => $al ? AcceptanceLetter::with('workOrder.pic', 'creator')->find($al->id) : null,
            ]);
        });
    }
}