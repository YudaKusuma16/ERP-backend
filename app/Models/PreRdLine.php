<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreRdLine extends Model
{
    protected $table = 'pre_rd_lines';

    protected $fillable = [
        'pre_rd_id',
        'po_line_id',
        'mr_line_id',
        'sr_line_id',
        'item_name',
        'ordered_qty',
        'received_qty',
        'received_unit',
        'notes',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:2',
        'received_qty' => 'decimal:2',
    ];

    public function preReceivingDocument()
    {
        return $this->belongsTo(PreReceivingDocument::class, 'pre_rd_id');
    }

    public function poLineItem()
    {
        return $this->belongsTo(PoLineItem::class, 'po_line_id');
    }

    public function mrLineItem()
    {
        return $this->belongsTo(MrLineItem::class, 'mr_line_id');
    }

    public function srLineItem()
    {
        return $this->belongsTo(SrLineItem::class, 'sr_line_id');
    }
}