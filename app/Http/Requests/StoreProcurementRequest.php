<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProcurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string'],
            'item_name' => ['required', 'string'],
            'quantity' => ['required', 'integer'],
            'unit' => ['required', 'string'],
            'unit_price' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ];
    }
}
