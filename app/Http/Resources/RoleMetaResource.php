<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleMetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'role' => $this['role'],
            'roleTitle' => $this['roleTitle'],
            'division' => $this['division'],
            'description' => $this['description'] ?? null,
            'isActive' => (bool) ($this['isActive'] ?? true),
            'sortOrder' => (int) ($this['sortOrder'] ?? 0),
        ];
    }
}
