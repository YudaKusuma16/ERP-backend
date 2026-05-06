<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalLogController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'document_type' => 'nullable|string',
            'document_id' => 'nullable|integer',
        ]);

        $query = \App\Models\ApprovalLog::with('actor');

        if ($request->has('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        if ($request->has('document_id')) {
            $query->where('document_id', $request->document_id);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json([
            'logs' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total(),
        ]);
    }
}