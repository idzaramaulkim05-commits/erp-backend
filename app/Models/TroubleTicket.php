<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TroubleTicket extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'region',
        'odp_id',
        'category',
        'title',
        'description',
        'priority',
        'status',
        'created_by',
        'assigned_to',
        'assigned_tech_name',
        'can_be_resolved_remotely',
        'noc_diagnostic_notes',
        'field_work_report',
        'lead_tech_approval',
        'noc_final_verification',
        'replacement_context',
    ];

    protected function casts(): array
    {
        return [
            'can_be_resolved_remotely' => 'boolean',
            'field_work_report' => 'array',
            'lead_tech_approval' => 'array',
            'noc_final_verification' => 'array',
            'replacement_context' => 'array',
        ];
    }
}
