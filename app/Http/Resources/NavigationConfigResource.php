<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NavigationConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'role' => $this['role'],
            'heads' => NavigationHeadResource::collection($this['heads']),
            'modules' => AppNavigationModuleResource::collection($this['modules']),
            'allowedModuleKeys' => $this['allowedModuleKeys'] ?? [],
        ];
    }
}
