<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PopWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'networkPopId' => $this->network_pop_id,
            'popName' => $this->pop?->name,
            'popRegion' => $this->pop?->region,
            'actionType' => $this->action_type,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'targetDeviceId' => $this->target_device_id,
            'targetDeviceInfo' => $this->target_device_info,
            'newDevicePayload' => $this->new_device_payload,
            'materialsFromWarehouse' => $this->materials_from_warehouse,
            'assignedLeadName' => $this->assigned_lead_name,
            'assignedTechId' => $this->assigned_tech_id,
            'assignedTechName' => $this->assigned_tech_name,
            'scheduledDate' => $this->scheduled_date,
            'fieldReport' => $this->field_report,
            'nocInstruction' => $this->noc_instruction,
            'nocQcResult' => $this->noc_qc_result,
            'warehouseReturnStatus' => $this->warehouse_return_status,
            'createdBy' => $this->created_by,
            'pop' => NetworkPopResource::make($this->whenLoaded('pop')),
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
