<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseReturnRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'work_order_id',
        'ticket_id',
        'customer_id',
        'customer_name',
        'submitted_by',
        'return_type',
        'status',
        'items',
        'qc_notes',
        'received_by',
        'received_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
