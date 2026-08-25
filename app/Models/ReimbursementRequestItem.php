<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementRequestItem extends Model
{
    protected $fillable = [
        'reimbursement_request_id',
        'item_name',
        'quantity',
        'unit',
        'unit_amount',
        'subtotal',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReimbursementRequest::class, 'reimbursement_request_id');
    }
}
