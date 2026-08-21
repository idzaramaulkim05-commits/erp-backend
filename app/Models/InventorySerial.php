<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySerial extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'sn',
        'status',
        'current_cust_id',
        'assigned_tech',
    ];
}
