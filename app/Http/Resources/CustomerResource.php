<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'pppoeUsername' => $this->pppoe_username,
            'pppoePassword' => $this->pppoe_password,
            'ipAddress' => $this->ip_address,
            'ontBrand' => $this->ont_brand,
            'ontModel' => $this->ont_model,
            'ontSerialNumber' => $this->ont_serial_number,
            'odcId' => $this->odc_id,
            'odpId' => $this->odp_id,
            'odpPort' => (int) $this->odp_port,
            'fiberCoreColor' => $this->fiber_core_color,
            'opticalPowerDbm' => $this->optical_power_dbm !== null ? (float) $this->optical_power_dbm : null,
            'status' => $this->status,
            'billingStatus' => $this->billing_status,
            'billingDueDate' => optional($this->billing_due_date)->format('Y-m-d'),
            'ktpImage' => $this->ktp_image,
            'installedDate' => optional($this->installed_date)->format('Y-m-d'),
            'assignedTechnician' => optional($this->assignedTechnician)->name,
            'lastPaymentDate' => optional($this->last_payment_date)->format('Y-m-d'),
        ];
    }
}
