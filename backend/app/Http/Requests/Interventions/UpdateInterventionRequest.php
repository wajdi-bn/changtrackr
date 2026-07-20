<?php

namespace App\Http\Requests\Interventions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_technician_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'required', Rule::in(['assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved', 'cancelled'])],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'estimated_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'final_status' => ['sometimes', 'nullable', 'string', 'max:160'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'parts' => ['sometimes', 'nullable', 'array', 'max:30'],
            'parts.*' => ['string', 'max:120'],
        ];
    }
}
