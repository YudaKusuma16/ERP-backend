<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryInstruction extends Model
{
    use SoftDeletes;

    protected $table = 'delivery_instructions';

    protected $fillable = [
        'number',
        'date',
        'mr_id',
        'warehouse_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'mr_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class, 'di_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'di');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}