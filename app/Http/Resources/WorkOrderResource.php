<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'customerId' => $this->customer_id,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'address' => $this->address,
            'region' => $this->region,
            'odpId' => $this->odp_id,
            'assignedLead' => $this->assigned_lead,
            'assignedTechId' => $this->assigned_tech_id,
            'assignedTechName' => $this->assigned_tech_name,
            'ticketId' => $this->ticket_id,
            'serviceRegistrationId' => $this->service_registration_id,
            'status' => $this->status,
            'scheduledDate' => $this->scheduled_date,
            'packagePlan' => $this->package_plan,
            'requiredMaterials' => $this->required_materials ?? [],
            'usedMaterials' => $this->used_materials ?? [],
            'photos' => $this->photos ?? new \stdClass(),
            'finalVerification' => $this->final_verification ?? new \stdClass(),
            'sopVerifiedByLead' => (bool) $this->sop_verified_by_lead,
            'nocActivated' => (bool) $this->noc_activated,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'completedAt' => optional($this->completed_at)->format('Y-m-d H:i:s'),
        ];
    }
}
