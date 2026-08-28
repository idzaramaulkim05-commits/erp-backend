<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkPopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'region' => $this->region,
            'clusterCode' => $this->cluster_code,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'picName' => $this->pic_name,
            'picPhone' => $this->pic_phone,
            'powerBackupInfo' => $this->power_backup_info,
            'rackCapacity' => $this->rack_capacity,
            'status' => $this->status,
            'notes' => $this->notes,
            'devicesCount' => $this->whenCounted('devices', $this->devices_count, $this->devices?->count() ?? 0),
            'activeDevicesCount' => $this->devices?->where('status', 'active')->count() ?? 0,
            'devices' => PopDeviceResource::collection($this->whenLoaded('devices')),
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
