<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcceptanceLetter extends Model
{
    use SoftDeletes;

    protected $table = 'acceptance_letters';

    protected $fillable = [
        'number',
        'date',
        'wo_id',
        'status',
        'created_by',
        'decline_reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lineItems()
    {
        return $this->hasMany(AlLineItem::class, 'al_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'al');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}