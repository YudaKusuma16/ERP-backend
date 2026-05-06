<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RdLineItem extends Model
{
    protected $table = 'rd_line_items';

    protected $fillable = [
        'rd_id',
        'item_name',
        'serial_number',
        'tag_number',
        'location',
        'condition_notes',
    ];

    public function receivingDocument()
    {
        return $this->belongsTo(ReceivingDocument::class, 'rd_id');
    }
}