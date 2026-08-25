<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallationMaterialRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'service_registration_id',
        'work_order_id',
        'ticket_id',
        'customer_name',
        'requested_by',
        'request_purpose',
        'status',
        'items',
        'approval_notes',
        'approved_by',
        'approved_at',
        'delivered_by',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'approved_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
