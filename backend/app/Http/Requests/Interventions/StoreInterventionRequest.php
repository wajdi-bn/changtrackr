<?php

namespace App\Http\Requests\Interventions;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_technician_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'problem' => ['nullable', 'string', 'max:3000'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'parts' => ['nullable', 'array', 'max:30'],
            'parts.*' => ['string', 'max:120'],
        ];
    }
}
