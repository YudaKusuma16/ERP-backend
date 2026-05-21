<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'date',
        'source_type',
        'source_doc_ref',
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
        return $this->hasMany(SrLineItem::class, 'sr_id');
    }

    public function purchaseRequisition()
    {
        return $this->hasOne(PurchaseRequisition::class, 'source_id')->where('source_type', 'sr');
    }

    /** Delivery instruction created when the SR’s PR is fully approved (vendor repair / shipping chain). */
    public function deliveryInstruction()
    {
        return $this->hasOne(DeliveryInstruction::class, 'pr_id', 'pr_id');
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
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'sr');
    }

    public function isFlow1(): bool
    {
        return in_array($this->source_type, ['internal', 'customer']);
    }

    public function isFlow2(): bool
    {
        return $this->source_type === '3rd_party';
    }

    public function isFlow3(): bool
    {
        return false;
    }

    public function isFlow4(): bool
    {
        return $this->source_type === 'project';
    }

    public function isVendorRepairFlow(): bool
    {
        return $this->source_type === '3rd_party';
    }

    public function usesProcurementFlow(): bool
    {
        return !$this->isVendorRepairFlow();
    }

    public function deliveryInstruction()
    {
        return $this->hasOne(DeliveryInstruction::class, 'sr_id');
    }

    public function preReceivingDocuments()
    {
        return $this->hasMany(PreReceivingDocument::class, 'sr_id');
    }

    public function hasRemainingReceivableQuantity(): bool
    {
        $this->loadMissing('lineItems');

        if ($this->lineItems->isEmpty()) {
            return false;
        }

        foreach ($this->lineItems as $line) {
            $totalReceived = PreRdLine::where('sr_line_id', $line->id)
                ->join('pre_receiving_documents', 'pre_rd_lines.pre_rd_id', '=', 'pre_receiving_documents.id')
                ->where('pre_receiving_documents.status', 'rd_generated')
                ->sum('pre_rd_lines.received_qty');

            if ((float) $totalReceived < (float) $line->qty) {
                return true;
            }
        }

        return false;
    }

    public function isReadyForPreReceiving(): bool
    {
        if (!$this->isVendorRepairFlow() || $this->status !== 'approved') {
            return false;
        }

        $di = $this->deliveryInstruction;
        if (!$di || $di->status !== 'issued') {
            return false;
        }

        $dn = $di->deliveryNote;
        if (!$dn || $dn->status !== 'dispatched') {
            return false;
        }

        return $this->hasRemainingReceivableQuantity();
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBySourceType($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }
}