<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'nik',
        'phone',
        'address',
        'region',
        'package_plan',
        'monthly_fee',
        'pppoe_username',
        'pppoe_password',
        'ip_address',
        'ont_brand',
        'ont_model',
        'ont_serial_number',
        'odc_id',
        'odp_id',
        'odp_port',
        'fiber_core_color',
        'optical_power_dbm',
        'status',
        'billing_status',
        'billing_due_date',
        'service_started_at',
        'service_active_until',
        'ktp_image',
        'installed_date',
        'assigned_technician_id',
        'last_payment_date',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'monthly_fee' => 'integer',
            'optical_power_dbm' => 'float',
            'billing_due_date' => 'date',
            'service_started_at' => 'date',
            'service_active_until' => 'date',
            'installed_date' => 'date',
            'last_payment_date' => 'date',
            'meta' => 'array',
        ];
    }

    public function assignedTechnician()
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function billingRecords()
    {
        return $this->hasMany(BillingRecord::class);
    }
}
