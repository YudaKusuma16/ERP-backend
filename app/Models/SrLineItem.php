<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SrLineItem extends Model
{
    protected $fillable = [
        'sr_id',
        'service_name',
        'qty',
        'unit',
        'est_cost',
        'description',
        'flagged',
        'flagged_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'est_cost' => 'decimal:2',
        'flagged' => 'boolean',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class, 'sr_id');
    }

    public function flaggedByUser()
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}