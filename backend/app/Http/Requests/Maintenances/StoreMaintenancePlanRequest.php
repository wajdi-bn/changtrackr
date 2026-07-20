<?php

namespace App\Http\Requests\Maintenances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenancePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => [Rule::requiredIf(fn () => $this->user()?->hasRole('super_admin') === true), 'nullable', 'integer', 'exists:organizations,id'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['nullable', 'integer', 'exists:connectors,id'],
            'assigned_technician_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'type' => ['required', Rule::in(['preventive', 'corrective'])],
            'priority' => ['required', Rule::in(['critical', 'warning', 'info'])],
            'instructions' => ['required', 'string', 'min:5', 'max:5000'],
            'first_scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'recurrence_frequency' => ['required', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'recurrence_interval' => ['required', 'integer', 'min:1', 'max:52'],
            'recurrence_ends_at' => ['nullable', 'date', 'after_or_equal:first_scheduled_at'],
        ];
    }
}
