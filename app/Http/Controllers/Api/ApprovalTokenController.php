<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailApprovalService;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use App\Models\ApprovalToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalTokenController extends Controller
{
    public function __construct(
        private EmailApprovalService $emailApprovalService,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
            'action' => 'nullable|in:approve,reject',
        ]);

        $action = $validated['action'] ?? 'approve';

        $result = $this->emailApprovalService->verifyToken($validated['token'], $action);

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        $processResult = $this->emailApprovalService->processEmailApproval($result);

        if (!$processResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $processResult['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $processResult['message'],
            'document_status' => $processResult['document_status'],
            'document_number' => $processResult['document_number'] ?? null,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:mr,sr,pr,po',
            'document_id' => 'required|integer',
            'approver_id' => 'required|integer|exists:users,id',
        ]);

        $approver = User::find($validated['approver_id']);

        if (!$approver || !$approver->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Approver tidak ditemukan atau tidak aktif.',
            ], 404);
        }

        $document = $this->emailApprovalService->getDocument($validated['document_type'], $validated['document_id']);

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen tidak ditemukan.',
            ], 404);
        }

        $documentNumber = $document->number ?? $document->id;

        $requesterName = null;
        $department = null;
        $totalAmount = null;
        $currentTier = null;
        $totalTiers = null;

        if (in_array($validated['document_type'], ['mr', 'sr'])) {
            $requesterName = $document->requestor?->name;
            $department = $document->department?->name;
        }

        if ($validated['document_type'] === 'pr') {
            $requesterName = $document->pihak1?->name;
            $totalAmount = $document->total_value;
            $currentTier = ($document->current_tier ?? 0) + 1;
            $totalTiers = $document->tier_count;
        }

        if ($validated['document_type'] === 'po') {
            $totalAmount = $document->total_value;
            $currentTier = ($document->current_tier ?? 0) + 1;
            $totalTiers = $document->tier_count;
        }

        $result = $this->emailApprovalService->sendApprovalEmail(
            documentType: $validated['document_type'],
            documentId: $validated['document_id'],
            documentNumber: $documentNumber,
            approver: $approver,
            requesterName: $requesterName,
            department: $department,
            totalAmount: $totalAmount,
            currentTier: $currentTier,
            totalTiers: $totalTiers,
        );

        return response()->json($result);
    }

    public function sendToRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:mr,sr,pr,po',
            'document_id' => 'required|integer',
            'role_code' => 'required|string',
        ]);

        $document = $this->emailApprovalService->getDocument($validated['document_type'], $validated['document_id']);

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen tidak ditemukan.',
            ], 404);
        }

        $documentNumber = $document->number ?? $document->id;

        $requesterName = null;
        $department = null;
        $totalAmount = null;
        $currentTier = null;
        $totalTiers = null;

        if (in_array($validated['document_type'], ['mr', 'sr'])) {
            $requesterName = $document->requestor?->name;
            $department = $document->department?->name;
        }

        if ($validated['document_type'] === 'pr') {
            $requesterName = $document->pihak1?->name;
            $totalAmount = $document->total_value;
            $currentTier = ($document->current_tier ?? 0) + 1;
            $totalTiers = $document->tier_count;
        }

        if ($validated['document_type'] === 'po') {
            $totalAmount = $document->total_value;
            $currentTier = ($document->current_tier ?? 0) + 1;
            $totalTiers = $document->tier_count;
        }

        $results = $this->emailApprovalService->sendApprovalEmailsToRole(
            roleCode: $validated['role_code'],
            documentType: $validated['document_type'],
            documentId: $validated['document_id'],
            documentNumber: $documentNumber,
            requesterName: $requesterName,
            department: $department,
            totalAmount: $totalAmount,
            currentTier: $currentTier,
            totalTiers: $totalTiers,
        );

        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return response()->json([
            'success' => true,
            'message' => "Email approval sent to {$successCount} approver(s).",
            'results' => $results,
        ]);
    }
}