<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rrv extends Model
{
    use SoftDeletes;

    protected $table = 'rrvs';

    protected $fillable = [
        'number',
        'date',
        'sr_id',
        'dn_id',
        'vendor_id',
        'replacement_item_detail',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class, 'sr_id');
    }

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'dn_id');
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'rrv');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}