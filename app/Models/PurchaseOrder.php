<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'date',
        'pr_id',
        'vendor_id',
        'pr_type',
        'total_value',
        'discount_value',
        'discount_type',
        'term_of_payment',
        'tier_count',
        'current_tier',
        'status',
        'created_by',
        'decline_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'total_value' => 'decimal:2',
        'discount_value' => 'decimal:2',
    ];

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function lineItems()
    {
        return $this->hasMany(PoLineItem::class, 'po_id');
    }

    public function priceComparisons()
    {
        return $this->hasMany(PriceComparison::class, 'po_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'document_id')->where('document_type', 'po');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function calculateTotalValue(): float
    {
        $total = 0;
        foreach ($this->lineItems as $item) {
            $lineTotal = $item->qty * $item->final_price;
            if ($item->discount_type === 'percentage') {
                $lineTotal -= ($lineTotal * $item->discount / 100);
            } else {
                $lineTotal -= $item->discount;
            }
            $total += max(0, $lineTotal);
        }

        if ($this->discount_type === 'percentage') {
            $total -= ($total * $this->discount_value / 100);
        } else {
            $total -= $this->discount_value;
        }

        return max(0, $total);
    }
}