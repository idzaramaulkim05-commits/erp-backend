<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkOdpPort extends Model
{
    protected $fillable = [
        'network_odp_id',
        'port_number',
        'customer_id',
        'customer_name',
        'pppoe_username',
        'optical_power_dbm',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'port_number' => 'integer',
            'optical_power_dbm' => 'float',
        ];
    }
}
