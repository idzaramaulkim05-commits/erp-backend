<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'movement_type',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
    ];
}
