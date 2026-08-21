<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nik' => $this->nik,
            'phone' => $this->phone,
            'address' => $this->address,
            'region' => $this->region,
            'packagePlan' => $this->package_plan,
            'monthlyFee' => (int) $this->monthly_fee,
            'odpId' => $this->odp_id,
            'odpPortCandidate' => $this->odp_port_candidate !== null ? (int) $this->odp_port_candidate : null,
            'status' => $this->status,
            'financeStatus' => $this->finance_status,
            'financeNotes' => $this->finance_notes,
            'financeApprovedBy' => $this->finance_approved_by,
            'financeApprovedAt' => optional($this->finance_approved_at)->format('Y-m-d H:i:s'),
            'nocStatus' => $this->noc_status,
            'nocNotes' => $this->noc_notes,
            'nocApprovedBy' => $this->noc_approved_by,
            'nocApprovedAt' => optional($this->noc_approved_at)->format('Y-m-d H:i:s'),
            'pppoeUsername' => $this->pppoe_username,
            'pppoePassword' => $this->pppoe_password,
            'generatedAt' => optional($this->generated_at)->format('Y-m-d H:i:s'),
            'customerId' => $this->customer_id,
            'workOrderId' => $this->work_order_id,
            'requestedBy' => optional($this->requestedBy)->name,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
