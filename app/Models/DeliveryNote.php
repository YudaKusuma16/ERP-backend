<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryNote extends Model
{
    use SoftDeletes;

    protected $table = 'delivery_notes';

    protected $fillable = [
        'number',
        'date',
        'di_id',
        'driver',
        'vehicle',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function deliveryInstruction()
    {
        return $this->belongsTo(DeliveryInstruction::class, 'di_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'dn');
    }

    public function rrv()
    {
        return $this->hasOne(Rrv::class, 'dn_id');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}