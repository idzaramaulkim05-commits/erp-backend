<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReimbursementRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'requested_by_id',
        'requester_role',
        'requester_division',
        'transaction_date',
        'description',
        'total_claim',
        'status',
        'receipt_path',
        'finance_notes',
        'management_notes',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'management_reviewed_by',
        'management_reviewed_at',
        'paid_by',
        'submitted_at',
        'approved_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'total_claim' => 'integer',
            'finance_reviewed_at' => 'datetime',
            'management_reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReimbursementRequestItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
