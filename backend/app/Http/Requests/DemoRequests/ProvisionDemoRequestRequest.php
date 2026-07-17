<?php

namespace App\Http\Requests\DemoRequests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'organization_name' => trim((string) $this->input('organization_name')),
            'admin_name' => trim((string) $this->input('admin_name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:160'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'trial_days' => ['required', 'integer', 'min:7', 'max:90'],
        ];
    }
}
