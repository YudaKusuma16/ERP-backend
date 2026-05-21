<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderRequestForm extends Model
{
    use SoftDeletes;

    protected $table = 'order_request_forms';

    protected $fillable = [
        'number',
        'date',
        'customer_name',
        'request_details',
        'status',
        'created_by',
        'decline_reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'orf_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'orf');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
