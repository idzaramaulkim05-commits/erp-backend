<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallationMaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serviceRegistrationId' => $this->service_registration_id,
            'workOrderId' => $this->work_order_id,
            'ticketId' => $this->ticket_id,
            'customerName' => $this->customer_name,
            'requestedBy' => $this->requested_by,
            'requestPurpose' => $this->request_purpose,
            'status' => $this->status,
            'items' => $this->items ?? [],
            'approvalNotes' => $this->approval_notes,
            'approvedBy' => $this->approved_by,
            'approvedAt' => optional($this->approved_at)->format('Y-m-d H:i:s'),
            'deliveredBy' => $this->delivered_by,
            'deliveredAt' => optional($this->delivered_at)->format('Y-m-d H:i:s'),
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
