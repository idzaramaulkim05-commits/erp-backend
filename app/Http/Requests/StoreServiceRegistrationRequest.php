<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'nik' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string'],
            'region' => ['required', 'string', 'max:255'],
            'package_plan' => ['required', 'string', 'max:255'],
            'monthly_fee' => ['required', 'integer', 'min:0'],
            'odp_id' => ['required', 'string', 'exists:network_odps,id'],
        ];
    }
}
