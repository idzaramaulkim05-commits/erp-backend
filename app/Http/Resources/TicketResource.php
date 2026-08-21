<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'customerAddress' => $this->customer_address,
            'region' => $this->region,
            'odpId' => $this->odp_id,
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'createdBy' => $this->created_by,
            'assignedTo' => $this->assigned_to,
            'assignedTechName' => $this->assigned_tech_name,
            'canBeResolvedRemotely' => (bool) $this->can_be_resolved_remotely,
            'nocDiagnosticNotes' => $this->noc_diagnostic_notes,
            'fieldWorkReport' => $this->field_work_report,
            'leadTechApproval' => $this->lead_tech_approval,
            'nocFinalVerification' => $this->noc_final_verification,
        ];
    }
}
