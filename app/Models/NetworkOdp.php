<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkOdp extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'odc_id',
        'region',
        'total_ports',
        'used_ports',
        'splitter_ratio',
        'olt_host',
        'pon_slot',
        'fiber_core_color',
        'latitude',
        'longitude',
        'address',
    ];

    protected function casts(): array
    {
        return [
            'total_ports' => 'integer',
            'used_ports' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function ports()
    {
        return $this->hasMany(NetworkOdpPort::class, 'network_odp_id');
    }
}
