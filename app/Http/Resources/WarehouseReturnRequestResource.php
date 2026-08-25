<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workOrderId' => $this->work_order_id,
            'ticketId' => $this->ticket_id,
            'customerId' => $this->customer_id,
            'customerName' => $this->customer_name,
            'submittedBy' => $this->submitted_by,
            'returnType' => $this->return_type ?? 'replacement',
            'status' => $this->status,
            'items' => $this->items ?? [],
            'qcNotes' => $this->qc_notes,
            'receivedBy' => $this->received_by,
            'receivedAt' => optional($this->received_at)->format('Y-m-d H:i:s'),
            'closedAt' => optional($this->closed_at)->format('Y-m-d H:i:s'),
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
