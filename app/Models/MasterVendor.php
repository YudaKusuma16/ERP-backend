<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterVendor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'address',
        'phone',
        'email',
        'tax_id',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'payment_terms',
        'status',
        'created_by',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
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
}