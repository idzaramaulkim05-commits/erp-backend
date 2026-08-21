<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'final_optical_power_dbm' => ['required', 'numeric'],
            'patch_cord_replaced' => ['nullable', 'boolean'],
            'drop_cable_length_meters' => ['nullable', 'integer'],
            'modem_replaced' => ['nullable', 'boolean'],
            'new_ont_serial_number' => ['nullable', 'string'],
            'photo_ktp' => ['nullable', 'string'],
            'photo_optical_power_meter' => ['nullable', 'string'],
            'photo_modem_installation' => ['nullable', 'string'],
            'signature' => ['nullable', 'string'],
            'used_materials' => ['nullable', 'array'],
        ];
    }
}
