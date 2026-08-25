<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'shareLocationUrl' => $this->share_location_url,
            'housePhoto' => $this->resolveHousePhotoUrl(),
            'assignedLead' => $this->assigned_lead,
            'assignedTechId' => $this->assigned_tech_id,
            'assignedTechName' => $this->assigned_tech_name,
            'ticketId' => $this->ticket_id,
            'serviceRegistrationId' => $this->service_registration_id,
            'installationMaterialRequestId' => $this->installation_material_request_id,
            'status' => $this->status,
            'scheduledDate' => $this->scheduled_date,
            'packagePlan' => $this->package_plan,
            'installationFeeActual' => $this->installation_fee_actual !== null ? (int) $this->installation_fee_actual : null,
            'installationPaymentMethod' => $this->installation_payment_method,
            'installationPaymentStatus' => $this->installation_payment_status,
            'installationPaymentCustomerPaid' => (bool) $this->installation_payment_customer_paid,
            'installationPaymentConfirmedAt' => optional($this->installation_payment_confirmed_at)->format('Y-m-d H:i:s'),
            'installationPaymentConfirmedBy' => $this->installation_payment_confirmed_by,
            'installationPaymentNotes' => $this->installation_payment_notes,
            'customerBiodataConfirmed' => (bool) $this->customer_biodata_confirmed,
            'routerSn' => $this->router_sn,
            'pppoeRequestStatus' => $this->pppoe_request_status,
            'pppoeRequestedAt' => optional($this->pppoe_requested_at)->format('Y-m-d H:i:s'),
            'pppoeRequestedBy' => $this->pppoe_requested_by,
            'pppoeApprovedAt' => optional($this->pppoe_approved_at)->format('Y-m-d H:i:s'),
            'pppoeApprovedBy' => $this->pppoe_approved_by,
            'requiredMaterials' => $this->required_materials ?? [],
            'usedMaterials' => $this->used_materials ?? [],
            'photos' => $this->resolvePhotoUrls(),
            'surveySnapshot' => $this->survey_snapshot ?? new \stdClass(),
            'activationPayload' => $this->activation_payload ?? new \stdClass(),
            'onuIdentity' => $this->onu_identity ?? new \stdClass(),
            'networkCredentials' => $this->network_credentials ?? new \stdClass(),
            'maintenancePayload' => $this->maintenance_payload ?? new \stdClass(),
            'warehouseReturnStatus' => $this->warehouse_return_status,
            'warehouseReturnRequestId' => $this->warehouse_return_request_id,
            'qcStatus' => $this->qc_status,
            'qcNotes' => $this->qc_notes,
            'returnedToTechAt' => optional($this->returned_to_tech_at)->format('Y-m-d H:i:s'),
            'finalVerification' => $this->final_verification ?? new \stdClass(),
            'sopVerifiedByLead' => (bool) $this->sop_verified_by_lead,
            'nocActivated' => (bool) $this->noc_activated,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'completedAt' => optional($this->completed_at)->format('Y-m-d H:i:s'),
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

    private function resolvePhotoUrls(): array
    {
        $photos = is_array($this->photos) ? $this->photos : [];

        return collect($photos)
            ->map(fn ($value) => $this->resolveStorageUrl($value))
            ->all();
    }

    private function resolveStorageUrl(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (Str::startsWith($value, ['http://', 'https://', '/storage/'])) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
