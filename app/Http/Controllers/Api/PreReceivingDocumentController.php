<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PoLineItem;
use App\Models\PreReceivingDocument;
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

    public function index(Request $request): JsonResponse
    {
        $query = PreReceivingDocument::with('purchaseOrder.vendor', 'pihak1', 'lines', 'receivingDocument');

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
        $validated = $request->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'lines' => 'required|array|min:1',
            'lines.*.po_line_id' => 'required|exists:po_line_items,id',
            'lines.*.received_qty' => 'required|numeric|min:0.01',
            'lines.*.received_unit' => 'required|string',
            'lines.*.notes' => 'nullable|string',
        ]);

        $po = \App\Models\PurchaseOrder::find($validated['po_id']);
        if (!$po || !in_array($po->status, ['approved', 'open'])) {
            return response()->json(['message' => 'PO must be approved or open to create Pre RD.'], 422);
        }

        return DB::transaction(function () use ($validated, $request, $po) {
            $pihak1Role = $po->pr_type === 'project' ? 'user' : 'pihak_1';

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
            'pre_rd' => $preReceivingDocument->load('lines.poLineItem', 'purchaseOrder.vendor', 'purchaseOrder.lineItems', 'pihak1', 'receivingDocument.lineItems', 'approvalLogs.actor'),
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
                    ], fn($v) => !is_null($v)));
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

            foreach ($preReceivingDocument->lines as $line) {
                RdLineItem::create([
                    'rd_id' => $rd->id,
                    'item_name' => $line->item_name,
                ]);
            }

            $preReceivingDocument->update(['status' => 'rd_generated']);
            $this->auditTrail->log('pre_rd', $preReceivingDocument->id, $request->user()->id, 'confirmed', 'rd_generated', 'RD generated: ' . $rd->number);

            $po = $preReceivingDocument->purchaseOrder;
            $allReceived = true;
            foreach ($po->lineItems as $poLine) {
                $totalReceived = PreRdLine::where('po_line_id', $poLine->id)
                    ->join('pre_receiving_documents', 'pre_rd_lines.pre_rd_id', '=', 'pre_receiving_documents.id')
                    ->where('pre_receiving_documents.status', 'rd_generated')
                    ->sum('received_qty');
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
                'pre_rd' => $preReceivingDocument->fresh()->load('lines', 'purchaseOrder'),
                'rd' => $rd->load('lineItems'),
            ]);
        });
    }
}