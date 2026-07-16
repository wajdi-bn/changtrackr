<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::prohibitedIf(! $this->user()?->hasRole('super_admin')),
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where('status', 'active'),
            ],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['nullable', 'integer', 'exists:connectors,id'],
            'assigned_technician_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'problem_type' => ['required', 'string', 'max:180'],
            'severity' => ['required', Rule::in(['critical', 'warning', 'info'])],
            'description' => ['required', 'string', 'max:3000'],
            'source' => ['nullable', Rule::in(['system', 'ocpp', 'operator', 'technician'])],
            'ocpp_log' => ['nullable', 'string', 'max:5000'],
            'suggested_cause' => ['nullable', 'string', 'max:3000'],
            'recommended_action' => ['nullable', 'string', 'max:3000'],
            'detected_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:now'],
        ];
    }
}
