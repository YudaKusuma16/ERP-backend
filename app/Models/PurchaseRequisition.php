<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequisition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'date',
        'source_type',
        'source_id',
        'pr_type',
        'total_value',
        'tier_count',
        'current_tier',
        'status',
        'pihak1_id',
    ];

    protected $casts = [
        'date' => 'date',
        'total_value' => 'decimal:2',
    ];

    public function sourceMr()
    {
        return $this->belongsTo(MaterialRequest::class, 'source_id');
    }

    public function sourceSr()
    {
        return $this->belongsTo(ServiceRequest::class, 'source_id');
    }

    public function source()
    {
        if ($this->source_type === 'mr') {
            return MaterialRequest::find($this->source_id);
        }
        return ServiceRequest::find($this->source_id);
    }

    public function lineItems()
    {
        return $this->hasMany(PrLineItem::class, 'pr_id');
    }

    public function pihak1()
    {
        return $this->belongsTo(User::class, 'pihak1_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'pr');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('pr_type', $type);
    }
}