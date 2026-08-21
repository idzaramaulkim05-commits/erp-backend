<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterDivisionTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'from_division',
        'to_division',
        'priority',
        'status',
        'related_customer_id',
        'related_ticket_id',
        'created_at',
        'updated_at',
        'due_date',
        'created_by',
        'assigned_to',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'due_date' => 'datetime',
        ];
    }
}
