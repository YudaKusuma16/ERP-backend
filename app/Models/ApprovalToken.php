<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalToken extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'approver_user_id',
        'token',
        'action',
        'status',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function scopeForDocument($query, string $documentType, int $documentId)
    {
        return $query->where('document_type', $documentType)
            ->where('document_id', $documentId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->status === 'used';
    }

    public function isValid(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    public function markAsUsed(string $action): void
    {
        $this->update([
            'status' => 'used',
            'action' => $action,
            'used_at' => now(),
        ]);
    }

    public static function markExpired(): int
    {
        return static::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public static function invalidatePendingForDocument(string $documentType, int $documentId): int
    {
        return static::where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }
}