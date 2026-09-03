<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'email',
        'role',
        'role_title',
        'division',
        'avatar',
        'phone',
        'is_online',
        'is_active',
        'last_login_at',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_online' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin' || $this->role === 'admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleMeta = $this->roleMeta;
        if ($roleMeta && is_array($roleMeta->permissions)) {
            return in_array($permission, $roleMeta->permissions, true);
        }

        return false;
    }

    public function getNamaAttribute(): string
    {
        return $this->name ?? '';
    }

    public function getUsernameAttribute(): string
    {
        return $this->email ?? ($this->id ?? '');
    }

    public function roleMeta(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }
}
