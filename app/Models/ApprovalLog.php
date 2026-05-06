<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLog extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'actor_id',
        'from_status',
        'to_status',
        'comment',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function log(
        string $documentType,
        int $documentId,
        int $actorId,
        string $fromStatus,
        string $toStatus,
        ?string $comment = null
    ): self {
        return static::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'actor_id' => $actorId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
        ]);
    }

    public function scopeForDocument($query, string $type, int $id)
    {
        return $query->where('document_type', $type)->where('document_id', $id);
    }
}