<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'description' => ['required', 'string'],
            'from_division' => ['required', 'string'],
            'to_division' => ['required', 'string'],
            'priority' => ['required', 'string'],
            'due_date' => ['required', 'date'],
            'assigned_to' => ['nullable', 'string'],
            'related_customer_id' => ['nullable', 'string'],
            'related_ticket_id' => ['nullable', 'string'],
        ];
    }
}
