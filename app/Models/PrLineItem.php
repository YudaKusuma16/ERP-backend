<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrLineItem extends Model
{
    protected $fillable = [
        'pr_id',
        'item_name',
        'qty',
        'unit',
        'initial_price',
        'description',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'initial_price' => 'decimal:2',
    ];

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }
}