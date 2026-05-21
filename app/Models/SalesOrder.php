<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use SoftDeletes;

    protected $table = 'sales_orders';

    protected $fillable = [
        'number',
        'date',
        'customer_name',
        'notes',
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

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class, 'so_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'so');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
