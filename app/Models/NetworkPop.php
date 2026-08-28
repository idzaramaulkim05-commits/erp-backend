<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkPop extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'code',
        'region',
        'cluster_code',
        'address',
        'latitude',
        'longitude',
        'pic_name',
        'pic_phone',
        'power_backup_info',
        'rack_capacity',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(PopDevice::class, 'network_pop_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(PopWorkOrder::class, 'network_pop_id');
    }
}
