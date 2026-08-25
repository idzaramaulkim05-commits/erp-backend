<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string'],
            'requires_replacement_request' => ['nullable', 'boolean'],
            'replacement_items' => ['nullable', 'array'],
            'replacement_items.*.item_name' => ['required_with:replacement_items', 'string'],
            'replacement_items.*.quantity' => ['required_with:replacement_items', 'integer', 'min:1'],
            'replacement_items.*.unit' => ['required_with:replacement_items', 'string'],
        ];
    }
}
