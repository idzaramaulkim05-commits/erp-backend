<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstallationNocVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'optical_dbm_reading' => ['required', 'numeric'],
            'pppoe_session_active' => ['required', 'boolean'],
            'rx_power_threshold_passed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
