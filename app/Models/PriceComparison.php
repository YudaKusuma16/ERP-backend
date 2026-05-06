<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceComparison extends Model
{
    protected $fillable = [
        'po_id',
        'vendor_name',
        'quoted_price',
        'notes',
    ];

    protected $casts = [
        'quoted_price' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }
}