<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PopDevice extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'network_pop_id',
        'inventory_item_id',
        'category',
        'brand',
        'model',
        'serial_number',
        'mac_address',
        'ip_management',
        'rack_position',
        'power_source',
        'status',
        'installed_at',
        'installed_by',
        'last_checked_at',
        'specifications',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'installed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function pop(): BelongsTo
    {
        return $this->belongsTo(NetworkPop::class, 'network_pop_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
