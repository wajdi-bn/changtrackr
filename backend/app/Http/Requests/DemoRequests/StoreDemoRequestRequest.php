<?php

namespace App\Http\Requests\DemoRequests;

use App\Models\DemoRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => trim((string) $this->input('full_name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'company_name' => trim((string) $this->input('company_name')),
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'message' => trim((string) $this->input('message')),
        ]);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'topic' => ['required', Rule::in(DemoRequest::TOPICS)],
            'estimated_stations' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
            'consent_accepted' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }
}
