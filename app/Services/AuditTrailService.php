<?php

namespace App\Services;

use App\Models\ApprovalLog;

class AuditTrailService
{
    public function log(
        string $documentType,
        int $documentId,
        int $actorId,
        string $fromStatus,
        string $toStatus,
        ?string $comment = null
    ): ApprovalLog {
        return ApprovalLog::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'actor_id' => $actorId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
        ]);
    }

    public function getDocumentTrail(string $documentType, int $documentId)
    {
        return ApprovalLog::with('actor')
            ->forDocument($documentType, $documentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}