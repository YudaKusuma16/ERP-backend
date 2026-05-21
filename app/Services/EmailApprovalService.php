<?php

namespace App\Services;

use App\Mail\ApprovalRequestMail;
use App\Models\ApprovalToken;
use App\Models\User;
use App\Models\MaterialRequest;
use App\Models\ServiceRequest;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailApprovalService
{
    public function __construct(
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {
    }

    public function sendApprovalEmail(
        string $documentType,
        int $documentId,
        string $documentNumber,
        User $approver,
        ?string $requesterName = null,
        ?string $department = null,
        ?float $totalAmount = null,
        ?int $currentTier = null,
        ?int $totalTiers = null,
        ?string $notes = null,
        ?array $lineItems = null
    ): array {
        $rawToken = Str::random(64);
        $hashedToken = hash('sha256', $rawToken);

        $tokenRecord = ApprovalToken::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'approver_user_id' => $approver->id,
            'token' => $hashedToken,
            'status' => 'pending',
            'expires_at' => now()->addHours(48),
        ]);

        if ($lineItems === null) {
            $lineItems = $this->getLineItems($documentType, $documentId);
        }

        if ($notes === null) {
            $notes = $this->getDocumentNotes($documentType, $documentId);
        }

        $baseUrl = config('app.frontend_url', config('app.url'));

        $emailData = [
            'approver_name' => $approver->name,
            'approver_id' => $approver->id,
            'document_type' => $documentType,
            'document_type_label' => $this->getDocumentTypeLabel($documentType),
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'requester_name' => $requesterName ?? '-',
            'department' => $department ?? '-',
            'total_amount' => $totalAmount,
            'current_tier' => $currentTier,
            'total_tiers' => $totalTiers,
            'notes' => $notes,
            'line_items' => $lineItems,
            'approval_url' => $baseUrl . '/approval/verify?token=' . $rawToken . '&action=approve',
            'reject_url' => $baseUrl . '/approval/verify?token=' . $rawToken . '&action=reject',
            'expires_at' => $tokenRecord->expires_at->format('d M Y H:i'),
        ];

        Mail::to($approver->email)->queue(new ApprovalRequestMail($emailData));

        return [
            'token_id' => $tokenRecord->id,
            'success' => true,
            'message' => 'Email approval sent to ' . $approver->email,
        ];
    }

    public function sendApprovalEmailsToRole(
        string $roleCode,
        string $documentType,
        int $documentId,
        string $documentNumber,
        ?string $requesterName = null,
        ?string $department = null,
        ?float $totalAmount = null,
        ?int $currentTier = null,
        ?int $totalTiers = null,
        ?string $notes = null,
        ?array $lineItems = null
    ): array {
        $users = User::whereHas('roles', fn($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();

        if ($lineItems === null) {
            $lineItems = $this->getLineItems($documentType, $documentId);
        }

        if ($notes === null) {
            $notes = $this->getDocumentNotes($documentType, $documentId);
        }

        $results = [];
        foreach ($users as $user) {
            $results[] = $this->sendApprovalEmail(
                documentType: $documentType,
                documentId: $documentId,
                documentNumber: $documentNumber,
                approver: $user,
                requesterName: $requesterName,
                department: $department,
                totalAmount: $totalAmount,
                currentTier: $currentTier,
                totalTiers: $totalTiers,
                notes: $notes,
                lineItems: $lineItems,
            );
        }

        return $results;
    }

    public function verifyToken(string $rawToken, string $action = 'approve'): array
    {
        $hashedToken = hash('sha256', $rawToken);

        $tokenRecord = ApprovalToken::where('token', $hashedToken)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if (!$tokenRecord) {
            return [
                'valid' => false,
                'message' => 'Token tidak valid atau sudah kadaluarsa. Silakan login ke sistem ERP untuk melakukan approval.',
            ];
        }

        $document = $this->getDocument($tokenRecord->document_type, $tokenRecord->document_id);

        if (!$document) {
            return [
                'valid' => false,
                'message' => 'Dokumen tidak ditemukan.',
            ];
        }

        $statusCheck = $this->validateDocumentStatus($tokenRecord->document_type, $document);

        if (!$statusCheck['valid']) {
            return [
                'valid' => false,
                'message' => $statusCheck['message'],
            ];
        }

        $roleCheck = $this->validateApproverRole($tokenRecord->document_type, $document, $tokenRecord->approver_user_id);

        if (!$roleCheck['valid']) {
            return [
                'valid' => false,
                'message' => $roleCheck['message'],
            ];
        }

        $tokenRecord->markAsUsed($action);

        ApprovalToken::invalidatePendingForDocument(
            $tokenRecord->document_type,
            $tokenRecord->document_id
        );

        return [
            'valid' => true,
            'document_type' => $tokenRecord->document_type,
            'document_id' => $tokenRecord->document_id,
            'approver_id' => $tokenRecord->approver_user_id,
            'action' => $action,
        ];
    }

    public function processEmailApproval(array $tokenResult): array
    {
        $documentType = $tokenResult['document_type'];
        $documentId = $tokenResult['document_id'];
        $approverId = $tokenResult['approver_id'];
        $action = $tokenResult['action'];

        switch ($documentType) {
            case 'mr':
                return $this->processMaterialRequest($documentId, $approverId, $action);
            case 'sr':
                return $this->processServiceRequest($documentId, $approverId, $action);
            case 'pr':
                return $this->processPurchaseRequisition($documentId, $approverId, $action);
            case 'po':
                return $this->processPurchaseOrder($documentId, $approverId, $action);
            default:
                return [
                    'success' => false,
                    'message' => 'Tipe dokumen tidak didukung.',
                ];
        }
    }

    private function processMaterialRequest(int $documentId, int $approverId, string $action): array
    {
        $mr = MaterialRequest::find($documentId);
        if (!$mr) {
            return ['success' => false, 'message' => 'Material Request tidak ditemukan.'];
        }

        $approver = User::find($approverId);

        if ($mr->status === 'pending_dept_head') {
            if ($action === 'reject') {
                $mr->update([
                    'status' => 'declined',
                    'decline_reason' => 'Ditolak via email',
                    'approved_by_dept_head' => $approverId,
                ]);
                $this->auditTrail->log('mr', $mr->id, $approverId, 'pending_dept_head', 'declined', 'Ditolak via email oleh Dept Head');

                $this->notificationService->notify(
                    $mr->requestor_id,
                    'mr_declined',
                    'MR Ditolak',
                    "MR {$mr->number} telah ditolak oleh Department Head via email.",
                    'mr',
                    $mr->id
                );

                return [
                    'success' => true,
                    'message' => 'Material Request telah ditolak.',
                    'document_status' => 'declined',
                    'document_number' => $mr->number,
                ];
            }

            return $this->approveMrByDeptHead($mr, $approverId);
        }

        if ($mr->status === 'pending_pihak_ii') {
            if ($action === 'reject') {
                $mr->update([
                    'status' => 'declined',
                    'decline_reason' => 'Ditolak via email',
                    'approved_by_pihak2' => $approverId,
                ]);
                $this->auditTrail->log('mr', $mr->id, $approverId, 'pending_pihak_ii', 'declined', 'Ditolak via email oleh Pihak II');

                $this->notificationService->notify(
                    $mr->requestor_id,
                    'mr_declined',
                    'MR Ditolak',
                    "MR {$mr->number} telah ditolak oleh Pihak II via email.",
                    'mr',
                    $mr->id
                );

                return [
                    'success' => true,
                    'message' => 'Material Request telah ditolak.',
                    'document_status' => 'declined',
                    'document_number' => $mr->number,
                ];
            }

            return $this->approveMrByPihak2($mr, $approverId);
        }

        return [
            'success' => false,
            'message' => "MR berada di status {$mr->status} yang tidak dapat diproses via email.",
        ];
    }

    private function approveMrByDeptHead(MaterialRequest $mr, int $approverId): array
    {
        if ($mr->isFlowB()) {
            return $this->approveMrFlowB($mr, $approverId, false);
        }

        if ($mr->skipsPurchaseRequisition()) {
            return $this->approveMrOutbound($mr, $approverId, false);
        }

        $mr->update([
            'status' => 'pending_pihak_ii',
            'approved_by_dept_head' => $approverId,
        ]);

        $this->auditTrail->log('mr', $mr->id, $approverId, 'pending_dept_head', 'pending_pihak_ii', 'Approved by Dept Head via email');

        $pihak2Role = match ($mr->source_type) {
            'internal', 'asset' => 'ga',
            default => 'log',
        };

        $this->sendApprovalEmailsToRole(
            roleCode: $pihak2Role,
            documentType: 'mr',
            documentId: $mr->id,
            documentNumber: $mr->number,
            requesterName: $mr->requestor?->name,
            department: $mr->department?->name,
        );

        $this->notificationService->notifyUsersWithRole(
            $pihak2Role,
            'mr_pending_approval',
            'MR Pending Pihak II Approval',
            "MR {$mr->number} memerlukan validasi dan flagging Anda.",
            'mr',
            $mr->id
        );

        return [
            'success' => true,
            'message' => 'MR disetujui oleh Dept Head. Diteruskan ke Pihak II.',
            'document_status' => 'pending_pihak_ii',
            'document_number' => $mr->number,
        ];
    }

    private function approveMrByPihak2(MaterialRequest $mr, int $approverId): array
    {
        if (!$mr->skipsPurchaseRequisition()) {
            $hasFlaggedItems = $mr->lineItems()->where('flagged', true)->exists();
            if (!$hasFlaggedItems) {
                $mr->lineItems()->update(['flagged' => true, 'flagged_by' => $approverId]);
            }
        }

        return $this->approveMrFlowA($mr, $approverId, true);
    }

    private function approveMrFlowA(MaterialRequest $mr, int $approverId, bool $isPihak2): array
    {
        if ($mr->skipsPurchaseRequisition()) {
            return $this->approveMrOutbound($mr, $approverId, $isPihak2);
        }

        $mr->update([
            'status' => 'approved',
            'approved_by_dept_head' => $mr->approved_by_dept_head ?? $approverId,
            'approved_by_pihak2' => $isPihak2 ? $approverId : null,
        ]);

        $fromStatus = $isPihak2 ? 'pending_pihak_ii' : 'pending_dept_head';
        $this->auditTrail->log('mr', $mr->id, $approverId, $fromStatus, 'approved', $isPihak2 ? 'Approved by Pihak II via email' : 'Approved by Dept Head via email');

        $this->notificationService->notify(
            $mr->requestor_id,
            'mr_approved',
            'MR Approved',
            "MR {$mr->number} telah disetujui via email.",
            'mr',
            $mr->id
        );

        return [
            'success' => true,
            'message' => 'Material Request telah disetujui. Silakan login ke sistem untuk melanjutkan proses PR.',
            'document_status' => 'approved',
            'document_number' => $mr->number,
        ];
    }

    private function approveMrFlowB(MaterialRequest $mr, int $approverId, bool $isPihak2): array
    {
        $mr->update([
            'status' => 'approved',
            'approved_by_dept_head' => $approverId,
        ]);

        $this->auditTrail->log('mr', $mr->id, $approverId, 'pending_dept_head', 'approved', 'Approved by Dept Head via email (Flow B)');

        $this->notificationService->notify(
            $mr->requestor_id,
            'mr_approved',
            'MR Approved',
            "MR {$mr->number} telah disetujui via email.",
            'mr',
            $mr->id
        );

        return [
            'success' => true,
            'message' => 'Material Request telah disetujui. Silakan login ke sistem untuk melanjutkan proses PR.',
            'document_status' => 'approved',
            'document_number' => $mr->number,
        ];
    }

    private function approveMrOutbound(MaterialRequest $mr, int $approverId, bool $isPihak2): array
    {
        $updateData = ['status' => 'approved'];

        if ($isPihak2) {
            $updateData['approved_by_pihak2'] = $approverId;
        } else {
            $updateData['approved_by_dept_head'] = $approverId;
        }

        $mr->update($updateData);

        $fromStatus = $isPihak2 ? 'pending_pihak_ii' : 'pending_dept_head';
        $this->auditTrail->log('mr', $mr->id, $approverId, $fromStatus, 'approved', 'Outbound MR approved via email');

        $this->notificationService->notify(
            $mr->requestor_id,
            'mr_approved',
            'MR Approved',
            "MR {$mr->number} telah disetujui via email.",
            'mr',
            $mr->id
        );

        return [
            'success' => true,
            'message' => 'Material Request (outbound) telah disetujui via email.',
            'document_status' => 'approved',
            'document_number' => $mr->number,
        ];
    }

    private function processServiceRequest(int $documentId, int $approverId, string $action): array
    {
        $sr = ServiceRequest::find($documentId);
        if (!$sr) {
            return ['success' => false, 'message' => 'Service Request tidak ditemukan.'];
        }

        if ($sr->status === 'pending_dept_head') {
            if ($action === 'reject') {
                $sr->update([
                    'status' => 'declined',
                    'decline_reason' => 'Ditolak via email',
                    'approved_by_dept_head' => $approverId,
                ]);
                $this->auditTrail->log('sr', $sr->id, $approverId, 'pending_dept_head', 'declined', 'Ditolak via email oleh Dept Head');

                $this->notificationService->notify(
                    $sr->requestor_id,
                    'sr_declined',
                    'SR Ditolak',
                    "SR {$sr->number} telah ditolak oleh Department Head via email.",
                    'sr',
                    $sr->id
                );

                return [
                    'success' => true,
                    'message' => 'Service Request telah ditolak.',
                    'document_status' => 'declined',
                    'document_number' => $sr->number,
                ];
            }

            return $this->approveSrByDeptHead($sr, $approverId);
        }

        if ($sr->status === 'pending_pihak_ii') {
            if ($action === 'reject') {
                $sr->update([
                    'status' => 'declined',
                    'decline_reason' => 'Ditolak via email',
                    'approved_by_pihak2' => $approverId,
                ]);
                $this->auditTrail->log('sr', $sr->id, $approverId, 'pending_pihak_ii', 'declined', 'Ditolak via email oleh Pihak II');

                $this->notificationService->notify(
                    $sr->requestor_id,
                    'sr_declined',
                    'SR Ditolak',
                    "SR {$sr->number} telah ditolak oleh Pihak II via email.",
                    'sr',
                    $sr->id
                );

                return [
                    'success' => true,
                    'message' => 'Service Request telah ditolak.',
                    'document_status' => 'declined',
                    'document_number' => $sr->number,
                ];
            }

            return $this->approveSrByPihak2($sr, $approverId);
        }

        return [
            'success' => false,
            'message' => "SR berada di status {$sr->status} yang tidak dapat diproses via email.",
        ];
    }

    private function approveSrByDeptHead(ServiceRequest $sr, int $approverId): array
    {
        $sr->update([
            'status' => 'pending_pihak_ii',
            'approved_by_dept_head' => $approverId,
        ]);

        $this->auditTrail->log('sr', $sr->id, $approverId, 'pending_dept_head', 'pending_pihak_ii', 'Approved by Dept Head via email');

        $pihak2Role = match ($sr->source_type) {
            'internal' => 'ga',
            default => 'log',
        };

        $this->sendApprovalEmailsToRole(
            roleCode: $pihak2Role,
            documentType: 'sr',
            documentId: $sr->id,
            documentNumber: $sr->number,
            requesterName: $sr->requestor?->name,
            department: $sr->department?->name,
        );

        $this->notificationService->notifyUsersWithRole(
            $pihak2Role,
            'sr_pending_approval',
            'SR Pending Validation',
            "SR {$sr->number} memerlukan validasi Anda.",
            'sr',
            $sr->id
        );

        return [
            'success' => true,
            'message' => 'SR disetujui oleh Dept Head. Diteruskan ke Pihak II.',
            'document_status' => 'pending_pihak_ii',
            'document_number' => $sr->number,
        ];
    }

    private function approveSrByPihak2(ServiceRequest $sr, int $approverId): array
    {
        $sr->update([
            'status' => 'approved',
            'approved_by_pihak2' => $approverId,
        ]);

        $this->auditTrail->log('sr', $sr->id, $approverId, 'pending_pihak_ii', 'approved', 'Approved by Pihak II via email');

        $this->notificationService->notify(
            $sr->requestor_id,
            'sr_approved',
            'SR Approved',
            "SR {$sr->number} telah disetujui oleh Pihak II via email. Silakan login ke sistem untuk melanjutkan.",
            'sr',
            $sr->id
        );

        return [
            'success' => true,
            'message' => 'Service Request telah disetujui. Silakan login ke sistem untuk melanjutkan proses PR.',
            'document_status' => 'approved',
            'document_number' => $sr->number,
        ];
    }

    private function processPurchaseRequisition(int $documentId, int $approverId, string $action): array
    {
        $pr = PurchaseRequisition::find($documentId);
        if (!$pr) {
            return ['success' => false, 'message' => 'Purchase Requisition tidak ditemukan.'];
        }

        if ($pr->status !== 'pending_pihak_ii') {
            return [
                'success' => false,
                'message' => "PR berada di status {$pr->status} yang tidak dapat diproses via email.",
            ];
        }

        if ((int) $pr->pihak1_id === $approverId) {
            return [
                'success' => false,
                'message' => 'User yang menginput pricing tidak dapat menyetujui PR ini.',
            ];
        }

        if ($action === 'reject') {
            $pr->update(['status' => 'declined']);
            $this->auditTrail->log('pr', $pr->id, $approverId, 'pending_pihak_ii', 'declined', 'Ditolak via email oleh Pihak II');

            $source = $pr->source_type === 'mr' ? $pr->sourceMr : $pr->sourceSr;
            if ($source) {
                $this->notificationService->notify(
                    $source->requestor_id ?? $pr->pihak1_id,
                    'pr_declined',
                    'PR Ditolak',
                    "PR {$pr->number} telah ditolak oleh Pihak II via email.",
                    'pr',
                    $pr->id
                );
            }

            return [
                'success' => true,
                'message' => 'Purchase Requisition telah ditolak.',
                'document_status' => 'declined',
                'document_number' => $pr->number,
            ];
        }

        $newTier = $pr->current_tier + 1;

        if ($newTier >= $pr->tier_count) {
            $pr->update([
                'status' => 'forwarded_to_p3',
                'current_tier' => $newTier,
            ]);
            $this->auditTrail->log('pr', $pr->id, $approverId, 'pending_pihak_ii', 'forwarded_to_p3', 'PR fully approved via email (all tiers)');

            $this->notificationService->notifyUsersWithRole(
                'purchasing',
                'pr_approved',
                'PR Approved - Ready for PO',
                "PR {$pr->number} telah disetujui penuh via email. Siap untuk pembuatan PO.",
                'pr',
                $pr->id
            );

            return [
                'success' => true,
                'message' => 'Purchase Requisition telah disetujui penuh.',
                'document_status' => 'forwarded_to_p3',
                'document_number' => $pr->number,
            ];
        }

        $pr->update(['current_tier' => $newTier]);
        $this->auditTrail->log('pr', $pr->id, $approverId, 'pending_pihak_ii', 'pending_pihak_ii', "Pihak II Tier {$newTier}/{$pr->tier_count} approved via email");

        $remaining = $pr->tier_count - $newTier;
        $this->sendApprovalEmailsToRole(
            roleCode: 'pihak_2',
            documentType: 'pr',
            documentId: $pr->id,
            documentNumber: $pr->number,
            requesterName: $pr->pihak1?->name,
            totalAmount: $pr->total_value,
            currentTier: $newTier + 1,
            totalTiers: $pr->tier_count,
            notes: "Tier {$newTier} disetujui. Sisa {$remaining} tier lagi.",
        );

        $this->notificationService->notifyUsersWithRole(
            'pihak_2',
            'pr_pending_approval',
            "PR Pending Tier " . ($newTier + 1) . " Approval",
            "PR {$pr->number} memerlukan approval Tier " . ($newTier + 1) . ". Sisa {$remaining} tier.",
            'pr',
            $pr->id
        );

        return [
            'success' => true,
            'message' => "PR Tier {$newTier} disetujui. Masih ada " . ($pr->tier_count - $newTier) . " tier lagi.",
            'document_status' => 'pending_pihak_ii',
            'document_number' => $pr->number,
        ];
    }

    private function processPurchaseOrder(int $documentId, int $approverId, string $action): array
    {
        $po = PurchaseOrder::find($documentId);
        if (!$po) {
            return ['success' => false, 'message' => 'Purchase Order tidak ditemukan.'];
        }

        if ($po->status !== 'pending_pihak_ii') {
            return [
                'success' => false,
                'message' => "PO berada di status {$po->status} yang tidak dapat diproses via email.",
            ];
        }

        if ($action === 'reject') {
            $po->update(['status' => 'declined']);
            $this->auditTrail->log('po', $po->id, $approverId, 'pending_pihak_ii', 'declined', 'Ditolak via email oleh Pihak II');

            $this->notificationService->notify(
                $po->created_by,
                'po_declined',
                'PO Ditolak',
                "PO {$po->number} telah ditolak oleh Pihak II via email.",
                'po',
                $po->id
            );

            return [
                'success' => true,
                'message' => 'Purchase Order telah ditolak.',
                'document_status' => 'declined',
                'document_number' => $po->number,
            ];
        }

        $newTier = $po->current_tier + 1;

        if ($newTier >= $po->tier_count) {
            $po->update(['status' => 'approved', 'current_tier' => $newTier]);
            $this->auditTrail->log('po', $po->id, $approverId, 'pending_pihak_ii', 'approved', 'PO fully approved via email (all tiers)');

            $this->notificationService->notify(
                $po->created_by,
                'po_approved',
                'PO Approved',
                "PO {$po->number} telah disetujui penuh via email.",
                'po',
                $po->id
            );

            return [
                'success' => true,
                'message' => 'Purchase Order telah disetujui penuh.',
                'document_status' => 'approved',
                'document_number' => $po->number,
            ];
        }

        $po->update(['current_tier' => $newTier]);
        $this->auditTrail->log('po', $po->id, $approverId, 'pending_pihak_ii', 'pending_pihak_ii', "Pihak II Tier {$newTier}/{$po->tier_count} approved via email");

        $remaining = $po->tier_count - $newTier;
        $this->sendApprovalEmailsToRole(
            roleCode: 'pihak_2',
            documentType: 'po',
            documentId: $po->id,
            documentNumber: $po->number,
            totalAmount: $po->total_value,
            currentTier: $newTier + 1,
            totalTiers: $po->tier_count,
            notes: "Tier {$newTier} disetujui. Sisa {$remaining} tier lagi.",
        );

        $this->notificationService->notifyUsersWithRole(
            'pihak_2',
            'po_pending_approval',
            "PO Pending Tier " . ($newTier + 1) . " Approval",
            "PO {$po->number} memerlukan approval Tier " . ($newTier + 1) . ". Sisa {$remaining} tier.",
            'po',
            $po->id
        );

        return [
            'success' => true,
            'message' => "PO Tier {$newTier} disetujui. Masih ada " . ($po->tier_count - $newTier) . " tier lagi.",
            'document_status' => 'pending_pihak_ii',
            'document_number' => $po->number,
        ];
    }

    public function getDocument(string $documentType, int $documentId): ?object
    {
        return match ($documentType) {
            'mr' => MaterialRequest::with('requestor', 'department', 'lineItems')->find($documentId),
            'sr' => ServiceRequest::with('requestor', 'department', 'lineItems')->find($documentId),
            'pr' => PurchaseRequisition::with('pihak1', 'lineItems')->find($documentId),
            'po' => PurchaseOrder::with('lineItems')->find($documentId),
            default => null,
        };
    }

    private function validateDocumentStatus(string $documentType, object $document): array
    {
        $validStatuses = match ($documentType) {
            'mr' => ['pending_dept_head', 'pending_pihak_ii'],
            'sr' => ['pending_dept_head', 'pending_pihak_ii'],
            'pr' => ['pending_pihak_ii'],
            'po' => ['pending_pihak_ii'],
            default => [],
        };

        if (!in_array($document->status, $validStatuses)) {
            $label = $this->getDocumentTypeLabel($documentType);
            return [
                'valid' => false,
                'message' => "{$label} sudah tidak dalam status yang dapat di-approve. Status saat ini: {$document->status}. Silakan login ke sistem untuk melihat status terbaru.",
            ];
        }

        return ['valid' => true];
    }

    private function validateApproverRole(string $documentType, object $document, int $approverId): array
    {
        $approver = User::find($approverId);
        if (!$approver || !$approver->is_active) {
            return [
                'valid' => false,
                'message' => 'Approver tidak ditemukan atau tidak aktif.',
            ];
        }

        if ($documentType === 'pr' && (int) $document->pihak1_id === $approverId) {
            return [
                'valid' => false,
                'message' => 'User yang menginput pricing tidak dapat menyetujui PR ini.',
            ];
        }

        $validRoles = match ($documentType) {
            'mr', 'sr' => $document->status === 'pending_dept_head'
            ? ['dept_head']
            : ['ga', 'log', 'dept_head'],
            'pr', 'po' => ['ga', 'log', 'dept_head'],
            default => [],
        };

        $hasRole = $approver->roles()->whereIn('code', $validRoles)->exists();
        if (!$hasRole) {
            return [
                'valid' => false,
                'message' => 'Anda tidak memiliki hak approval untuk dokumen ini.',
            ];
        }

        return ['valid' => true];
    }

    private function getDocumentTypeLabel(string $documentType): string
    {
        return match ($documentType) {
            'mr' => 'Material Request',
            'sr' => 'Service Request',
            'pr' => 'Purchase Requisition',
            'po' => 'Purchase Order',
            default => $documentType,
        };
    }

    private function getDocumentNotes(string $documentType, int $documentId): ?string
    {
        $document = $this->getDocument($documentType, $documentId);

        if (!$document || !isset($document->notes)) {
            return null;
        }

        return $document->notes ?: null;
    }

    private function getLineItems(string $documentType, int $documentId): array
    {
        $document = $this->getDocument($documentType, $documentId);

        if (!$document) {
            return [];
        }

        return match ($documentType) {
            'mr' => $document->lineItems->map(fn($item) => [
                'name' => $item->item?->name ?? $item->item_name ?? '-',
                'qty' => $item->qty,
                'unit' => $item->unit,
                'description' => $item->description,
                'price' => null,
            ])->values()->all(),

            'sr' => $document->lineItems->map(fn($item) => [
                'name' => $item->service_name ?? '-',
                'qty' => $item->qty,
                'unit' => $item->unit,
                'description' => $item->description,
                'price' => $item->est_cost,
            ])->values()->all(),

            'pr' => $document->lineItems->map(fn($item) => [
                'name' => $item->item_name ?? '-',
                'qty' => $item->qty,
                'unit' => $item->unit,
                'description' => $item->description,
                'price' => $item->initial_price ?? null,
            ])->values()->all(),

            'po' => $document->lineItems->map(fn($item) => [
                'name' => $item->item_name ?? '-',
                'qty' => $item->qty,
                'unit' => $item->unit,
                'description' => $item->description,
                'price' => $item->final_price,
            ])->values()->all(),

            default => [],
        };
    }
}