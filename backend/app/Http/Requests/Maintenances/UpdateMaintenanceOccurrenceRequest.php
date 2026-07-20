<?php

namespace App\Http\Requests\Maintenances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_technician_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'estimated_duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:1440'],
            'priority' => ['sometimes', 'required', Rule::in(['critical', 'warning', 'info'])],
            'problem' => ['sometimes', 'required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
