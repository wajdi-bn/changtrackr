<?php

namespace App\Http\Requests\DemoRequests;

use Illuminate\Foundation\Http\FormRequest;

class RejectDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rejection_reason' => trim((string) $this->input('rejection_reason')),
        ]);
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:10', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
