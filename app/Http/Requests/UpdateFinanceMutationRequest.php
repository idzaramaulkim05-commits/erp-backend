<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFinanceMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date'],
            'type' => ['required', 'in:inflow,outflow'],
            'category' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string'],
            'reference' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ];
    }
}
