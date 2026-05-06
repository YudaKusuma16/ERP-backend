<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'unit',
        'category',
        'asset_code',
        'coa',
        'status',
        'created_by',
        'validated_by',
        'decline_reason',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedBy()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPendingAccounting(): bool
    {
        return $this->status === 'pending_accounting';
    }
}