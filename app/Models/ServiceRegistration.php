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
        'phone',
        'address',
        'region',
        'package_plan',
        'monthly_fee',
        'odp_id',
        'odp_port_candidate',
        'status',
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
        'requested_by_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'monthly_fee' => 'integer',
            'odp_port_candidate' => 'integer',
            'finance_approved_at' => 'datetime',
            'noc_approved_at' => 'datetime',
            'generated_at' => 'datetime',
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
