<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReceivingDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'date',
        'pre_rd_id',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function preReceivingDocument()
    {
        return $this->belongsTo(PreReceivingDocument::class, 'pre_rd_id');
    }

    public function lineItems()
    {
        return $this->hasMany(RdLineItem::class, 'rd_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'rd');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}