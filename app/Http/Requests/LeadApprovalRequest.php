<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeadApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sop_checklist' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
