<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'brand' => $this->brand,
            'model' => $this->model,
            'stockAvailable' => (int) $this->stock_available,
            'stockInUse' => (int) $this->stock_in_use,
            'stockReserved' => (int) $this->stock_reserved,
            'minThreshold' => (int) $this->min_threshold,
            'unit' => $this->unit,
            'unitPrice' => (int) $this->unit_price,
            'locationRack' => $this->location_rack,
            'serialNumbers' => $this->whenLoaded('serials', fn () => $this->serials->map(fn ($serial) => [
                'sn' => $serial->sn,
                'status' => $serial->status,
                'currentCustId' => $serial->current_cust_id,
                'assignedTech' => $serial->assigned_tech,
            ])->values()),
        ];
    }
}
