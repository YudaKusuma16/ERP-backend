<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rrv;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RrvController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Rrv::with('serviceRequest', 'deliveryNote', 'vendor', 'creator');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }
        if ($request->has('search')) {
            $query->where('number', 'like', '%' . $request->search . '%');
        }

        $rrvs = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);
        return response()->json($rrvs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sr_id' => 'nullable|exists:service_requests,id',
            'dn_id' => 'nullable|exists:delivery_notes,id',
            'vendor_id' => 'nullable|exists:master_vendors,id',
            'replacement_item_detail' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $rrv = Rrv::create([
                'number' => $this->docNumbering->generate('rrv'),
                'date' => now()->toDateString(),
                'sr_id' => $validated['sr_id'] ?? null,
                'dn_id' => $validated['dn_id'] ?? null,
                'vendor_id' => $validated['vendor_id'] ?? null,
                'replacement_item_detail' => $validated['replacement_item_detail'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('rrv', $rrv->id, $request->user()->id, 'created', 'draft', 'RRV created');

            return response()->json([
                'message' => 'RRV created successfully.',
                'rrv' => $rrv->load('serviceRequest', 'deliveryNote', 'vendor', 'creator'),
            ], 201);
        });
    }

    public function show(Rrv $rrv): JsonResponse
    {
        return response()->json([
            'rrv' => $rrv->load('serviceRequest', 'deliveryNote', 'vendor', 'creator', 'approvalLogs.actor'),
        ]);
    }

    public function update(Request $request, Rrv $rrv): JsonResponse
    {
        if ($rrv->status !== 'draft') {
            return response()->json(['message' => 'Only draft RRV can be updated.'], 422);
        }

        $validated = $request->validate([
            'vendor_id' => 'nullable|exists:master_vendors,id',
            'replacement_item_detail' => 'nullable|string',
        ]);

        $rrv->update($validated);
        return response()->json(['message' => 'RRV updated.', 'rrv' => $rrv->fresh()]);
    }

    public function confirm(Rrv $rrv): JsonResponse
    {
        if ($rrv->status !== 'draft') {
            return response()->json(['message' => 'RRV must be in draft status to confirm.'], 422);
        }

        $rrv->update(['status' => 'confirmed']);
        $this->auditTrail->log('rrv', $rrv->id, request()->user()->id, 'draft', 'confirmed', 'RRV confirmed');

        return response()->json(['message' => 'RRV confirmed.', 'rrv' => $rrv->fresh()]);
    }
}