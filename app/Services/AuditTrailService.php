<?php

namespace App\Services;

use App\Models\ApprovalLog;
use Illuminate\Support\Facades\Log;

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
        // 1. Simpan ke database (perilaku yang sudah ada, tidak berubah)
        $record = ApprovalLog::create([
            'document_type' => $documentType,
            'document_id'   => $documentId,
            'actor_id'      => $actorId,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'comment'       => $comment,
        ]);

        // 2. Tulis juga ke file log harian (channel 'activity')
        $actor = $record->actor ?? \App\Models\User::find($actorId);

        $message = sprintf(
            '[ACTIVITY] User #%d (%s) | %s#%d | %s → %s | %s | IP: %s',
            $actorId,
            $actor?->email ?? 'unknown',
            strtoupper($documentType),
            $documentId,
            $fromStatus,
            $toStatus,
            $comment ?? '-',
            request()->ip() ?? 'cli'
        );

        Log::channel('activity')->info($message, [
            'user_id'       => $actorId,
            'user_name'     => $actor?->name,
            'user_email'    => $actor?->email,
            'document_type' => $documentType,
            'document_id'   => $documentId,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'comment'       => $comment,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);

        return $record;
    }

    public function getDocumentTrail(string $documentType, int $documentId)
    {
        return ApprovalLog::with('actor')
            ->forDocument($documentType, $documentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}