<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceMutationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transactionDate' => optional($this->transaction_date)->format('Y-m-d'),
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (int) $this->amount,
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'createdById' => $this->created_by_id,
            'createdByName' => optional($this->creator)->name,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
