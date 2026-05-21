<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreReceivingDocument extends Model
{
    use SoftDeletes;

    protected $table = 'pre_receiving_documents';

    protected $fillable = [
        'number',
        'date',
        'po_id',
        'mr_id',
        'sr_id',
        'dn_id',
        'pihak1_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'mr_id');
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class, 'sr_id');
    }

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'dn_id');
    }

    public function isFromMaterialRequest(): bool
    {
        return $this->mr_id !== null;
    }

    public function isFromServiceRequest(): bool
    {
        return $this->sr_id !== null;
    }

    public function pihak1()
    {
        return $this->belongsTo(User::class, 'pihak1_id');
    }

    public function lines()
    {
        return $this->hasMany(PreRdLine::class, 'pre_rd_id');
    }

    public function receivingDocument()
    {
        return $this->hasOne(ReceivingDocument::class, 'pre_rd_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'pre_rd');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
