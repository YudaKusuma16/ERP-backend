<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlLineItem extends Model
{
    protected $table = 'al_line_items';

    protected $fillable = [
        'al_id',
        'item_id',
        'item_name',
        'item_status',
        'location',
    ];

    public function acceptanceLetter()
    {
        return $this->belongsTo(AcceptanceLetter::class, 'al_id');
    }

    public function item()
    {
        return $this->belongsTo(MasterItem::class, 'item_id');
    }
}