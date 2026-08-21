<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'networkSummary' => $this['networkSummary'],
            'odps' => NetworkOdpResource::collection($this['odps']),
            'roleDivisionMap' => $this['roleDivisionMap'],
        ];
    }
}
