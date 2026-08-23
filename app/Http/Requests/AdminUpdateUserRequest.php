<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId, 'id')],
            'role' => ['required', 'string', Rule::exists('roles', 'key')->where('is_active', true)],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
