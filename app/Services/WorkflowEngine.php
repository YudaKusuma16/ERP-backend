<?php

namespace App\Services;

use App\Models\ApprovalLog;
use Illuminate\Database\Eloquent\Model;

class WorkflowEngine
{
    public function transition(
        Model $document,
        string $toStatus,
        int $actorId,
        ?string $comment = null,
        ?string $documentType = null
    ): Model {
        $fromStatus = $document->status;

        if (!$this->isValidTransition($documentType ?? get_class($document), $fromStatus, $toStatus)) {
            throw new \InvalidArgumentException("Invalid status transition from '{$fromStatus}' to '{$toStatus}'");
        }

        $document->update(['status' => $toStatus]);

        if ($documentType) {
            ApprovalLog::log($documentType, $document->id, $actorId, $fromStatus, $toStatus, $comment);
        }

        return $document->fresh();
    }

    private function isValidTransition(string $documentType, string $from, string $to): bool
    {
        $transitions = config('workflows.transitions', []);

        $typeTransitions = $transitions[$documentType] ?? [];

        if (empty($typeTransitions)) {
            return true;
        }

        return isset($typeTransitions[$from]) && in_array($to, $typeTransitions[$from]);
    }
}