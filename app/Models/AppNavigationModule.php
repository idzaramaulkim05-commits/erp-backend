<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppNavigationModule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'module_key';

    protected $fillable = [
        'module_key',
        'label',
        'description',
        'route_target',
        'navigation_head_key',
        'sort_order',
        'quick_action',
        'view_formats',
        'is_active',
        'show_in_navbar',
        'admin_only_dashboard',
    ];

    protected function casts(): array
    {
        return [
            'view_formats' => 'array',
            'is_active' => 'boolean',
            'show_in_navbar' => 'boolean',
            'admin_only_dashboard' => 'boolean',
        ];
    }

    public function navigationHead(): BelongsTo
    {
        return $this->belongsTo(NavigationHead::class, 'navigation_head_key', 'key');
    }

    public function roleMappings(): HasMany
    {
        return $this->hasMany(RoleModuleMapping::class, 'module_key', 'module_key');
    }
}
