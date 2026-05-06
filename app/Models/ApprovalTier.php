<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalTier extends Model
{
    protected $fillable = [
        'document_type',
        'min_value',
        'max_value',
        'tier_count',
        'is_active',
    ];

    protected $casts = [
        'min_value' => 'integer',
        'max_value' => 'integer',
        'tier_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDocumentType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public static function getTierCountForValue(string $documentType, int $value): int
    {
        $tier = static::forDocumentType($documentType)
            ->active()
            ->where('min_value', '<=', $value)
            ->where(function ($q) use ($value) {
                $q->where('max_value', '>=', $value)
                    ->orWhereNull('max_value');
            })
            ->orderBy('tier_count', 'desc')
            ->first();

        return $tier ? $tier->tier_count : 1;
    }
}