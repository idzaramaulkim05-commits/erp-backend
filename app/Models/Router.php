<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use HasFactory;

    protected $table = 'routers';

    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'username',
        'password',
        'type',
        'model',
        'wan_interface',
        'pppoe_interface',
        'is_active',
        'is_default',
        'notes',
    ];

    protected $casts = [
        'port'       => 'integer',
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Get default router.
     */
    public static function getDefaultRouter(): ?self
    {
        return self::where('is_default', true)->first() 
            ?? self::where('is_active', true)->first() 
            ?? self::first();
    }

    /**
     * Get formatted type badge HTML.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match (strtolower($this->type)) {
            'crs' => '<span class="badge badge-crs">CRS Switch</span>',
            'ccr' => '<span class="badge badge-ccr">CCR Router</span>',
            'switch' => '<span class="badge badge-switch">Switch</span>',
            default => '<span class="badge badge-core">Core Router</span>',
        };
    }

    /**
     * Get icon symbol for select options.
     */
    public function getTypeIconAttribute(): string
    {
        return match (strtolower($this->type)) {
            'crs' => '[CRS]',
            'ccr' => '[CCR]',
            'switch' => '[Switch]',
            default => '[Router]',
        };
    }
}
