<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'nik' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'address' => ['required', 'string'],
            'region' => ['required', 'string'],
            'package_plan' => ['required', 'string'],
            'monthly_fee' => ['required', 'integer'],
            'odp_id' => ['required', 'string', 'exists:network_odps,id'],
            'initial_deposit_paid' => ['nullable', 'boolean'],
        ];
    }
}
