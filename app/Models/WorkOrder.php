<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'type',
        'customer_id',
        'customer_name',
        'customer_phone',
        'address',
        'region',
        'odp_id',
        'assigned_lead',
        'assigned_tech_id',
        'assigned_tech_name',
        'ticket_id',
        'status',
        'scheduled_date',
        'package_plan',
        'required_materials',
        'used_materials',
        'photos',
        'sop_verified_by_lead',
        'noc_activated',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'required_materials' => 'array',
            'used_materials' => 'array',
            'photos' => 'array',
            'sop_verified_by_lead' => 'boolean',
            'noc_activated' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}
