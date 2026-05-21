<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'work_orders';

    protected $fillable = [
        'number',
        'date',
        'orf_id',
        'orf_ref',
        'job_details',
        'pic_id',
        'service_type',
        'status',
        'created_by',
        'decline_reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function orderRequestForm()
    {
        return $this->belongsTo(OrderRequestForm::class, 'orf_id');
    }

    public function pic()
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptanceLetter()
    {
        return $this->hasOne(AcceptanceLetter::class, 'wo_id');
    }

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class, 'wo_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'wo');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
