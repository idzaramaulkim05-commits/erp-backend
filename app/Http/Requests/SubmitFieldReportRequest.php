<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class SubmitFieldReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_taken' => ['required', 'string'],
            'field_action_type' => ['nullable', 'string'],
            'device_replacement_applied' => ['nullable', 'boolean'],
            'root_cause' => ['nullable', 'string'],
            'progress_summary' => ['nullable', 'string'],
            'result_summary' => ['nullable', 'string'],
            'final_optical_power_dbm' => ['required', 'numeric'],
            'patch_cord_replaced' => ['nullable', 'boolean'],
            'drop_cable_length_meters' => ['nullable', 'integer'],
            'modem_replaced' => ['nullable', 'boolean'],
            'photo_ktp' => ['nullable'],
            'photo_odp' => ['nullable'],
            'photo_optical_power_meter' => ['nullable'],
            'photo_modem_installation' => ['nullable'],
            'photo_modem_identity' => ['nullable'],
            'photo_installation_result' => ['nullable'],
            'pon_sn' => ['nullable', 'string'],
            'onu_serial_number' => ['nullable', 'string'],
            'mac_address' => ['nullable', 'string'],
            'activation_signature' => ['nullable', 'string'],
            'activation_terms' => ['nullable', 'string'],
            'network_credentials' => ['nullable', 'array'],
            'signature' => ['nullable', 'string'],
            'customer_biodata_confirmed' => ['nullable', 'boolean'],
            'installation_fee_actual' => ['nullable', 'integer', 'min:0'],
            'installation_payment_method' => ['nullable', 'in:tunai,transfer'],
            'installation_payment_customer_paid' => ['nullable', 'boolean'],
            'router_sn' => ['nullable', 'string'],
            'used_materials' => ['nullable', 'array'],
            'return_items' => ['nullable', 'array'],
            'return_items.*.item_name' => ['nullable', 'string', 'required_without:return_items.*.itemName'],
            'return_items.*.itemName' => ['nullable', 'string', 'required_without:return_items.*.item_name'],
            'return_items.*.quantity' => ['required_with:return_items', 'integer', 'min:1'],
            'return_items.*.unit' => ['required_with:return_items', 'string'],
            'return_items.*.return_category' => ['nullable', 'string'],
            'return_items.*.returnCategory' => ['nullable', 'string'],
            'return_items.*.serial_numbers' => ['nullable', 'array'],
            'return_items.*.serial_numbers.*' => ['string'],
            'return_items.*.serialNumbers' => ['nullable', 'array'],
            'return_items.*.serialNumbers.*' => ['string'],
            'device_brand' => ['nullable', 'string'],
            'device_model' => ['nullable', 'string'],
        ];
    }
}
