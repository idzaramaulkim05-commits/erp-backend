<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'item_code',
        'item_name',
        'quantity',
        'unit',
        'unit_price',
        'total_amount',
        'reason',
        'requested_by',
        'requested_at',
        'status',
        'finance_approval',
        'management_approval',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'total_amount' => 'integer',
            'requested_at' => 'datetime',
            'received_at' => 'datetime',
            'finance_approval' => 'array',
            'management_approval' => 'array',
        ];
    }
}
