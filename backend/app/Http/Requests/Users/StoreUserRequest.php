<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->user()?->hasRole('super_admin') && $this->input('role') !== 'super_admin'),
                'nullable',
                'integer',
                'exists:organizations,id',
            ],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'team' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending'])],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'operator', 'technician'])],
            'password' => ['required', 'string', Password::min(8)],
        ];
    }
}
