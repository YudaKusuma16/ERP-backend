<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\MrLineItem;
use App\Models\ServiceRequest;
use App\Models\SrLineItem;
use App\Models\PoLineItem;
use App\Models\PreReceivingDocument;
use App\Models\PurchaseOrder;
use App\Models\PreRdLine;
use App\Models\ReceivingDocument;
use App\Models\RdLineItem;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PreReceivingDocumentController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
        private WorkflowEngine $workflow,
    ) {}

    public function availablePurchaseOrders(): JsonResponse
    {
        $orders = PurchaseOrder::availableForPreReceiving()
            ->with('vendor', 'lineItems')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn (PurchaseOrder $po) => $po->hasRemainingReceivableQuantity())
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = PreReceivingDocument::with(
            'purchaseOrder.vendor',
            'materialRequest',
            'serviceRequest',
            'pihak1',
            'lines',
            'receivingDocument'
        );

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $preRds = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($preRds);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->filled('sr_id')) {
            $sr = ServiceRequest::findOrFail($request->input('sr_id'));

            return $this->storeFromServiceRequest($request, $sr);
        }

        if ($request->filled('mr_id')) {
            $mr = MaterialRequest::findOrFail($request->input('mr_id'));

            return $this->storeFromMaterialRequest($request, $mr);
        }

        return $this->storeFromPurchaseOrder($request);
    }

    public function storeFromServiceRequest(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.sr_line_id' => 'required|exists:sr_line_items,id',
            'lines.*.received_qty' => 'required|numeric|min:0.01',
            'lines.*.received_unit' => 'required|string',
            'lines.*.notes' => 'nullable|string',
        ]);

        $sr = $serviceRequest->load(['lineItems', 'deliveryInstruction.deliveryNote']);

        if (!$sr->isVendorRepairFlow()) {
            return response()->json(['message' => 'Pre-RD from SR is only for 3rd Party vendor repair requests.'], 422);
        }

        if (!$sr->isReadyForPreReceiving()) {
            return response()->json([
                'message' => 'SR must be approved with issued DI and dispatched DN before creating Pre-RD.',
            ], 422);
        }

        foreach ($validated['lines'] as $line) {
            $srLine = SrLineItem::where('id', $line['sr_line_id'])->where('sr_id', $sr->id)->first();
            if (!$srLine) {
                return response()->json(['message' => 'Invalid line item for this SR.'], 422);
            }
        }

        return DB::transaction(function () use ($validated, $request, $sr) {
            $preRd = PreReceivingDocument::create([
                'number' => $this->docNumbering->generate('pre_rd'),
                'date' => now()->toDateString(),
                'sr_id' => $sr->id,
                'pihak1_id' => $request->user()->id,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $srLine = SrLineItem::find($line['sr_line_id']);
                PreRdLine::create([
                    'pre_rd_id' => $preRd->id,
                    'sr_line_id' => $line['sr_line_id'],
                    'item_name' => $srLine->service_name ?? 'N/A',
                    'ordered_qty' => $srLine->qty ?? 0,
                    'received_qty' => $line['received_qty'],
                    'received_unit' => $line['received_unit'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->auditTrail->log('pre_rd', $preRd->id, $request->user()->id, 'draft', 'draft', 'Pre RD created from SR ' . $sr->number);

            return response()->json([
                'message' => 'Pre-Receiving Document created successfully.',
                'pre_rd' => $preRd->load('lines', 'serviceRequest', 'pihak1'),
            ], 201);
        });
    }

    public function storeFromMaterialRequest(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.mr_line_id' => 'required|exists:mr_line_items,id',
            'lines.*.received_qty' => 'required|numeric|min:0.01',
            'lines.*.received_unit' => 'required|string',
            'lines.*.notes' => 'nullable|string',
        ]);

        $mr = $materialRequest->load(['lineItems', 'deliveryInstruction.deliveryNote']);

        if (!$mr || !$mr->isAssetDeliveryFlow()) {
            return response()->json(['message' => 'Pre-RD from MR is only for Asset material requests.'], 422);
        }

        if (!$mr->isReadyForPreReceiving()) {
            return response()->json([
                'message' => 'Asset MR must be approved with issued DI and dispatched DN before creating Pre-RD.',
            ], 422);
        }

        foreach ($validated['lines'] as $line) {
            $mrLine = MrLineItem::where('id', $line['mr_line_id'])->where('mr_id', $mr->id)->first();
            if (!$mrLine) {
                return response()->json(['message' => 'Invalid line item for this MR.'], 422);
            }
        }

        return DB::transaction(function () use ($validated, $request, $mr) {
            $preRd = PreReceivingDocument::create([
                'number' => $this->docNumbering->generate('pre_rd'),
                'date' => now()->toDateString(),
                'mr_id' => $mr->id,
                'pihak1_id' => $request->user()->id,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $mrLine = MrLineItem::find($line['mr_line_id']);
                PreRdLine::create([
                    'pre_rd_id' => $preRd->id,
                    'mr_line_id' => $line['mr_line_id'],
                    'item_name' => $mrLine->item->name ?? $mrLine->item_name ?? 'N/A',
                    'ordered_qty' => $mrLine->qty ?? 0,
                    'received_qty' => $line['received_qty'],
                    'received_unit' => $line['received_unit'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->auditTrail->log('pre_rd', $preRd->id, $request->user()->id, 'draft', 'draft', 'Pre RD created from Asset MR ' . $mr->number);

            return response()->json([
                'message' => 'Pre-Receiving Document created successfully.',
                'pre_rd' => $preRd->load('lines', 'materialRequest', 'pihak1'),
            ], 201);
        });
    }

    private function storeFromPurchaseOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.po_line_id' => 'required|exists:po_line_items,id',
            'lines.*.received_qty' => 'required|numeric|min:0.01',
            'lines.*.received_unit' => 'required|string',
            'lines.*.notes' => 'nullable|string',
        ]);

        $po = PurchaseOrder::with('lineItems')->find($validated['po_id']);
        if (!$po || !in_array($po->status, ['approved', 'open', 'partially_closed'], true)) {
            return response()->json(['message' => 'PO must be approved, open, or partially closed to create Pre-RD.'], 422);
        }

        if (!$po->hasRemainingReceivableQuantity()) {
            return response()->json(['message' => 'This PO has no remaining quantity to receive.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $po) {
            $preRd = PreReceivingDocument::create([
                'number' => $this->docNumbering->generate('pre_rd'),
                'date' => now()->toDateString(),
                'po_id' => $validated['po_id'],
                'pihak1_id' => $request->user()->id,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $poLine = PoLineItem::find($line['po_line_id']);
                PreRdLine::create([
                    'pre_rd_id' => $preRd->id,
                    'po_line_id' => $line['po_line_id'],
                    'item_name' => $poLine->item_name ?? 'N/A',
                    'ordered_qty' => $poLine->qty ?? 0,
                    'received_qty' => $line['received_qty'],
                    'received_unit' => $line['received_unit'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->auditTrail->log('pre_rd', $preRd->id, $request->user()->id, 'draft', 'draft', 'Pre RD created from PO ' . $po->number);

            return response()->json([
                'message' => 'Pre-Receiving Document created successfully.',
                'pre_rd' => $preRd->load('lines', 'purchaseOrder.vendor', 'pihak1'),
            ], 201);
        });
    }

    public function show(PreReceivingDocument $preReceivingDocument): JsonResponse
    {
        return response()->json([
            'pre_rd' => $preReceivingDocument->load(
                'lines.poLineItem',
                'lines.mrLineItem',
                'purchaseOrder.vendor',
                'purchaseOrder.lineItems',
                'materialRequest.lineItems.item',
                'materialRequest.deliveryInstruction.deliveryNote',
                'serviceRequest.lineItems',
                'serviceRequest.deliveryInstruction.deliveryNote',
                'lines.srLineItem',
                'pihak1',
                'receivingDocument.lineItems',
                'approvalLogs.actor'
            ),
        ]);
    }

    public function confirm(Request $request, PreReceivingDocument $preReceivingDocument): JsonResponse
    {
        if ($preReceivingDocument->status !== 'draft') {
            return response()->json(['message' => 'Pre RD must be in draft status to confirm.'], 422);
        }

        $validated = $request->validate([
            'lines' => 'sometimes|array',
            'lines.*.id' => 'required|exists:pre_rd_lines,id',
            'lines.*.received_qty' => 'sometimes|numeric|min:0',
            'lines.*.received_unit' => 'sometimes|string',
        ]);

        if ($request->has('lines')) {
            foreach ($validated['lines'] as $lineData) {
                $line = PreRdLine::where('id', $lineData['id'])
                    ->where('pre_rd_id', $preReceivingDocument->id)
                    ->first();
                if ($line) {
                    $line->update(array_filter([
                        'received_qty' => $lineData['received_qty'] ?? null,
                        'received_unit' => $lineData['received_unit'] ?? null,
                    ], fn ($v) => !is_null($v)));
                }
            }
        }

        return DB::transaction(function () use ($preReceivingDocument, $request) {
            $fromStatus = $preReceivingDocument->status;
            $preReceivingDocument->update(['status' => 'confirmed']);
            $this->auditTrail->log('pre_rd', $preReceivingDocument->id, $request->user()->id, $fromStatus, 'confirmed', 'Pre RD confirmed');

            $rd = ReceivingDocument::create([
                'number' => $this->docNumbering->generate('rd'),
                'date' => now()->toDateString(),
                'pre_rd_id' => $preReceivingDocument->id,
                'status' => 'pending_input',
            ]);

            foreach ($preReceivingDocument->fresh()->lines as $line) {
                RdLineItem::create([
                    'rd_id' => $rd->id,
                    'item_name' => $line->item_name,
                ]);
            }

            $preReceivingDocument->update(['status' => 'rd_generated']);
            $this->auditTrail->log('pre_rd', $preReceivingDocument->id, $request->user()->id, 'confirmed', 'rd_generated', 'RD generated: ' . $rd->number);

            if ($preReceivingDocument->isFromMaterialRequest()) {
                $this->finalizeMaterialRequestReceiving($preReceivingDocument, $request);
            } elseif ($preReceivingDocument->isFromServiceRequest()) {
                $this->finalizeServiceRequestReceiving($preReceivingDocument, $request);
            } else {
                $this->finalizePurchaseOrderReceiving($preReceivingDocument, $request);
            }

            $this->notificationService->notify(
                $preReceivingDocument->pihak1_id,
                'rd_generated',
                'Receiving Document Generated',
                "RD {$rd->number} has been generated from Pre RD {$preReceivingDocument->number}.",
                'rd',
                $rd->id
            );

            return response()->json([
                'message' => 'Pre RD confirmed. RD generated successfully.',
                'pre_rd' => $preReceivingDocument->fresh()->load('lines', 'purchaseOrder', 'materialRequest', 'serviceRequest'),
                'rd' => $rd->load('lineItems'),
            ]);
        });
    }

    private function finalizePurchaseOrderReceiving(PreReceivingDocument $preReceivingDocument, Request $request): void
    {
        $po = $preReceivingDocument->purchaseOrder;
        if (!$po) {
            return;
        }

        $po->load('lineItems');
        $allReceived = true;

        foreach ($po->lineItems as $poLine) {
            $totalReceived = PreRdLine::where('po_line_id', $poLine->id)
                ->join('pre_receiving_documents', 'pre_rd_lines.pre_rd_id', '=', 'pre_receiving_documents.id')
                ->where('pre_receiving_documents.status', 'rd_generated')
                ->sum('pre_rd_lines.received_qty');

            if ($totalReceived < $poLine->qty) {
                $allReceived = false;
                break;
            }
        }

        if ($allReceived) {
            $po->update(['status' => 'closed']);
            $this->auditTrail->log('po', $po->id, $request->user()->id, $po->status, 'closed', 'PO closed - all quantities received');
        } else {
            if ($po->status === 'approved') {
                $po->update(['status' => 'open']);
            } elseif ($po->status === 'open') {
                $po->update(['status' => 'partially_closed']);
            }
            $this->auditTrail->log('po', $po->id, $request->user()->id, $po->status, $po->fresh()->status, 'PO partially received');
        }
    }

    private function finalizeMaterialRequestReceiving(PreReceivingDocument $preReceivingDocument, Request $request): void
    {
        $mr = $preReceivingDocument->materialRequest;
        if (!$mr) {
            return;
        }

        $mr->load('lineItems');
        $allReceived = true;

        foreach ($mr->lineItems as $mrLine) {
            $totalReceived = PreRdLine::where('mr_line_id', $mrLine->id)
                ->join('pre_receiving_documents', 'pre_rd_lines.pre_rd_id', '=', 'pre_receiving_documents.id')
                ->where('pre_receiving_documents.status', 'rd_generated')
                ->sum('pre_rd_lines.received_qty');

            if ((float) $totalReceived < (float) $mrLine->qty) {
                $allReceived = false;
                break;
            }
        }

        if ($allReceived) {
            $fromStatus = $mr->status;
            $mr->update(['status' => 'fully_received']);
            $this->auditTrail->log('mr', $mr->id, $request->user()->id, $fromStatus, 'fully_received', 'Asset MR fully received');
        }
    }

    private function finalizeServiceRequestReceiving(PreReceivingDocument $preReceivingDocument, Request $request): void
    {
        $sr = $preReceivingDocument->serviceRequest;
        if (!$sr) {
            return;
        }

        $sr->load('lineItems');
        $allReceived = true;

        foreach ($sr->lineItems as $srLine) {
            $totalReceived = PreRdLine::where('sr_line_id', $srLine->id)
                ->join('pre_receiving_documents', 'pre_rd_lines.pre_rd_id', '=', 'pre_receiving_documents.id')
                ->where('pre_receiving_documents.status', 'rd_generated')
                ->sum('pre_rd_lines.received_qty');

            if ((float) $totalReceived < (float) $srLine->qty) {
                $allReceived = false;
                break;
            }
        }

        if ($allReceived) {
            $fromStatus = $sr->status;
            $sr->update(['status' => 'fully_received']);
            $this->auditTrail->log('sr', $sr->id, $request->user()->id, $fromStatus, 'fully_received', 'Vendor repair SR fully received');
        }
    }
}
