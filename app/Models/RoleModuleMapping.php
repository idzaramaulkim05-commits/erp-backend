<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleModuleMapping extends Model
{
    protected $fillable = [
        'role',
        'module_key',
        'is_visible',
        'order_override',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(AppNavigationModule::class, 'module_key', 'module_key');
    }
}
