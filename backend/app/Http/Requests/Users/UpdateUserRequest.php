<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:40'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'team' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'pending'])],
            'role' => ['sometimes', 'required', Rule::in(['super_admin', 'admin', 'operator', 'technician', 'client'])],
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
