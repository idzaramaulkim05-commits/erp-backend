<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReimbursementDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $this->merge(['items' => is_array($decoded) ? $decoded : []]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.itemName' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['required', 'string'],
            'items.*.unitAmount' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
