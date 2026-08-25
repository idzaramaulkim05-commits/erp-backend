<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nik' => $this->nik,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'address' => $this->address,
            'region' => $this->region,
            'packagePlan' => $this->package_plan,
            'monthlyFee' => (int) $this->monthly_fee,
            'installationFee' => (int) $this->installation_fee,
            'odpId' => $this->odp_id,
            'entrySource' => $this->entry_source,
            'shareLocationUrl' => $this->share_location_url,
            'housePhoto' => $this->resolveHousePhotoUrl(),
            'odpPortCandidate' => $this->odp_port_candidate !== null ? (int) $this->odp_port_candidate : null,
            'status' => $this->status,
            'validationStatus' => $this->validation_status,
            'validationNotes' => $this->validation_notes,
            'validatedBy' => $this->validated_by,
            'validatedAt' => optional($this->validated_at)->format('Y-m-d H:i:s'),
            'surveyStatus' => $this->survey_status,
            'surveyResult' => $this->survey_result,
            'surveyNotes' => $this->survey_notes,
            'surveyedBy' => $this->surveyed_by,
            'surveyedAt' => optional($this->surveyed_at)->format('Y-m-d H:i:s'),
            'surveyData' => $this->survey_data ?? new \stdClass(),
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
            'installationMaterialRequestId' => $this->installation_material_request_id,
            'activationReport' => $this->activation_report ?? new \stdClass(),
            'activationDocument' => $this->activation_document ?? new \stdClass(),
            'requestedBy' => optional($this->requestedBy)->name,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveHousePhotoUrl(): ?string
    {
        if (! is_string($this->house_photo) || $this->house_photo === '') {
            return null;
        }

        if (Str::startsWith($this->house_photo, ['http://', 'https://', '/storage/'])) {
            return $this->house_photo;
        }

        return Storage::disk('public')->url($this->house_photo);
    }
}
