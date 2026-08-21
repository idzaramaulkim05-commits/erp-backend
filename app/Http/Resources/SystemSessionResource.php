<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roleTitle' => $this->role_title,
            'division' => $this->division,
            'isOnline' => (bool) $this->is_online,
            'isActive' => (bool) $this->is_active,
            'lastLoginAt' => optional($this->last_login_at)->format('Y-m-d H:i:s'),
        ];
    }
}
