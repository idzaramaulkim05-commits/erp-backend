<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkOdpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'odcId' => $this->odc_id,
            'region' => $this->region,
            'totalPorts' => (int) $this->total_ports,
            'usedPorts' => (int) $this->used_ports,
            'splitterRatio' => $this->splitter_ratio,
            'oltHost' => $this->olt_host,
            'ponSlot' => $this->pon_slot,
            'fiberCoreColor' => $this->fiber_core_color,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : 0,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : 0,
            'address' => $this->address,
            'portMappings' => $this->whenLoaded('ports', fn () => $this->ports->sortBy('port_number')->map(fn ($port) => [
                'portNumber' => (int) $port->port_number,
                'customerId' => $port->customer_id,
                'customerName' => $port->customer_name,
                'pppoeUsername' => $port->pppoe_username,
                'opticalPowerDbm' => $port->optical_power_dbm !== null ? (float) $port->optical_power_dbm : null,
                'status' => $port->status,
            ])->values()),
        ];
    }
}
