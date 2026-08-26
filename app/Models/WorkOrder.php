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
        'share_location_url',
        'house_photo',
        'assigned_lead',
        'assigned_tech_id',
        'assigned_tech_name',
        'ticket_id',
        'service_registration_id',
        'installation_material_request_id',
        'status',
        'scheduled_date',
        'package_plan',
        'installation_fee_actual',
        'installation_payment_method',
        'installation_payment_status',
        'installation_payment_customer_paid',
        'installation_payment_confirmed_at',
        'installation_payment_confirmed_by',
        'installation_payment_notes',
        'customer_biodata_confirmed',
        'router_sn',
        'pppoe_request_status',
        'pppoe_requested_at',
        'pppoe_requested_by',
        'pppoe_approved_at',
        'pppoe_approved_by',
        'required_materials',
        'used_materials',
        'photos',
        'survey_snapshot',
        'activation_payload',
        'onu_identity',
        'network_credentials',
        'maintenance_payload',
        'warehouse_return_status',
        'warehouse_return_request_id',
        'qc_status',
        'qc_notes',
        'returned_to_tech_at',
        'final_verification',
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
            'survey_snapshot' => 'array',
            'activation_payload' => 'array',
            'onu_identity' => 'array',
            'network_credentials' => 'array',
            'maintenance_payload' => 'array',
            'installation_fee_actual' => 'integer',
            'installation_payment_customer_paid' => 'boolean',
            'installation_payment_confirmed_at' => 'datetime',
            'customer_biodata_confirmed' => 'boolean',
            'pppoe_requested_at' => 'datetime',
            'pppoe_approved_at' => 'datetime',
            'returned_to_tech_at' => 'datetime',
            'final_verification' => 'array',
            'sop_verified_by_lead' => 'boolean',
            'noc_activated' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function serviceRegistration()
    {
        return $this->belongsTo(ServiceRegistration::class, 'service_registration_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
