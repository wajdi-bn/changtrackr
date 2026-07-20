<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isSuperAdministrator = $this->user()?->hasRole('super_admin') ?? false;
        $role = $this->string('role')->toString();

        return [
            'organization_id' => [
                Rule::prohibitedIf(! $isSuperAdministrator || $role === 'super_admin'),
                Rule::requiredIf($isSuperAdministrator && in_array($role, ['admin', 'operator', 'technician'], true)),
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where('status', 'active'),
            ],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'team' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => [Rule::prohibitedIf(! $isSuperAdministrator), Rule::requiredIf($isSuperAdministrator), Rule::in(['active', 'inactive', 'pending'])],
            'role' => ['required', Rule::in($isSuperAdministrator
                ? ['super_admin', 'admin', 'operator', 'technician']
                : ['operator', 'technician'])],
            'password' => [Rule::prohibitedIf(! $isSuperAdministrator), Rule::requiredIf($isSuperAdministrator), 'string', 'min:8'],
        ];
    }
}
