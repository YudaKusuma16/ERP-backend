<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'date',
        'source_type',
        'requestor_id',
        'department_id',
        'notes',
        'status',
        'pr_id',
        'decline_reason',
        'approved_by_dept_head',
        'approved_by_pihak2',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function requestor()
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function lineItems()
    {
        return $this->hasMany(MrLineItem::class, 'mr_id');
    }

    public function purchaseRequisition()
    {
        return $this->hasOne(PurchaseRequisition::class, 'source_id')->where('source_type', 'mr');
    }

    public function approvedByDeptHead()
    {
        return $this->belongsTo(User::class, 'approved_by_dept_head');
    }

    public function approvedByPihak2()
    {
        return $this->belongsTo(User::class, 'approved_by_pihak2');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'mr');
    }

    public function isFlowA(): bool
    {
        return in_array($this->source_type, ['internal', 'asset', 'customer']);
    }

    public function isFlowB(): bool
    {
        return $this->source_type === 'project_internal';
    }

    public function requiresPihak2Approval(): bool
    {
        return in_array($this->source_type, ['internal', 'asset', 'customer']);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBySourceType($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    public function scopeByDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}