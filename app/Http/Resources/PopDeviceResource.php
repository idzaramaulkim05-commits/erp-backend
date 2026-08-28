<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PopDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'networkPopId' => $this->network_pop_id,
            'inventoryItemId' => $this->inventory_item_id,
            'category' => $this->category,
            'brand' => $this->brand,
            'model' => $this->model,
            'serialNumber' => $this->serial_number,
            'macAddress' => $this->mac_address,
            'ipManagement' => $this->ip_management,
            'rackPosition' => $this->rack_position,
            'powerSource' => $this->power_source,
            'status' => $this->status,
            'installedAt' => optional($this->installed_at)->format('Y-m-d H:i:s'),
            'installedBy' => $this->installed_by,
            'lastCheckedAt' => optional($this->last_checked_at)->format('Y-m-d H:i:s'),
            'specifications' => $this->specifications ?? [],
            'notes' => $this->notes,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
