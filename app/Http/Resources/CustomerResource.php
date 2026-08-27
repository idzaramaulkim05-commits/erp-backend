<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mac = data_get($this->meta, 'macAddress')
            ?? data_get($this->meta, 'mac_address')
            ?? data_get($this->meta, 'mac')
            ?? data_get($this->meta, 'router_mac')
            ?? data_get($this->meta, 'onu_mac');

        if (! $mac) {
            $rawSn = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($this->ont_serial_number ?: $this->id));
            $cleanSeed = strtoupper(substr(str_pad($rawSn, 12, '0', STR_PAD_LEFT), -12));
            $mac = implode(':', str_split($cleanSeed, 2));
        }

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
            'macAddress' => $mac,
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
            'serviceStartedAt' => optional($this->service_started_at)->format('Y-m-d'),
            'serviceActiveUntil' => optional($this->service_active_until)->format('Y-m-d'),
            'ktpImage' => $this->ktp_image,
            'installedDate' => optional($this->installed_date)->format('Y-m-d'),
            'assignedTechnician' => optional($this->assignedTechnician)->name,
            'lastPaymentDate' => optional($this->last_payment_date)->format('Y-m-d'),
        ];
    }
}
