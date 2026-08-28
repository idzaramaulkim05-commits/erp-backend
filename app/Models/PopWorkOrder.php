<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PopWorkOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'network_pop_id',
        'action_type',
        'title',
        'description',
        'priority',
        'status',
        'target_device_id',
        'target_device_info',
        'new_device_payload',
        'materials_from_warehouse',
        'assigned_lead_name',
        'assigned_tech_id',
        'assigned_tech_name',
        'scheduled_date',
        'field_report',
        'noc_instruction',
        'noc_qc_result',
        'warehouse_return_status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_device_info' => 'array',
            'new_device_payload' => 'array',
            'materials_from_warehouse' => 'array',
            'field_report' => 'array',
            'noc_instruction' => 'array',
            'noc_qc_result' => 'array',
        ];
    }

    public function pop(): BelongsTo
    {
        return $this->belongsTo(NetworkPop::class, 'network_pop_id');
    }

    public function targetDevice(): BelongsTo
    {
        return $this->belongsTo(PopDevice::class, 'target_device_id');
    }

    public function assignedTech(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_tech_id');
    }
}
