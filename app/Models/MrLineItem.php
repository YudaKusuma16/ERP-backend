<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MrLineItem extends Model
{
    protected $fillable = [
        'mr_id',
        'item_id',
        'item_name',
        'qty',
        'unit',
        'description',
        'flagged',
        'flagged_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'flagged' => 'boolean',
    ];

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'mr_id');
    }

    public function item()
    {
        return $this->belongsTo(MasterItem::class, 'item_id');
    }

    public function flaggedByUser()
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}