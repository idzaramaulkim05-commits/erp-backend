<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'timestamp' => optional($this->timestamp)->format('Y-m-d H:i:s'),
            'actorName' => $this->actor_name,
            'actorRole' => $this->actor_role,
            'action' => $this->action,
            'target' => $this->target,
            'details' => $this->details,
            'type' => $this->type,
        ];
    }
}
