<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoLineItem extends Model
{
    protected $fillable = [
        'po_id',
        'item_name',
        'qty',
        'unit',
        'final_price',
        'discount',
        'discount_type',
        'description',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'final_price' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }
}