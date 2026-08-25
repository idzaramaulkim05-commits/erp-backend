<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceRegistration extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'nik',
        'gender',
        'phone',
        'address',
        'region',
        'package_plan',
        'monthly_fee',
        'installation_fee',
        'odp_id',
        'entry_source',
        'share_location_url',
        'house_photo',
        'odp_port_candidate',
        'status',
        'validation_status',
        'validation_notes',
        'validated_by',
        'validated_at',
        'survey_status',
        'survey_result',
        'survey_notes',
        'surveyed_by',
        'surveyed_at',
        'finance_status',
        'finance_notes',
        'finance_approved_by',
        'finance_approved_at',
        'noc_status',
        'noc_notes',
        'noc_approved_by',
        'noc_approved_at',
        'pppoe_username',
        'pppoe_password',
        'generated_at',
        'customer_id',
        'work_order_id',
        'installation_material_request_id',
        'survey_data',
        'activation_report',
        'activation_document',
        'requested_by_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'monthly_fee' => 'integer',
            'installation_fee' => 'integer',
            'odp_port_candidate' => 'integer',
            'validated_at' => 'datetime',
            'surveyed_at' => 'datetime',
            'finance_approved_at' => 'datetime',
            'noc_approved_at' => 'datetime',
            'generated_at' => 'datetime',
            'survey_data' => 'array',
            'activation_report' => 'array',
            'activation_document' => 'array',
            'meta' => 'array',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function odp()
    {
        return $this->belongsTo(NetworkOdp::class, 'odp_id');
    }
}
