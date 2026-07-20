<?php

namespace App\Http\Requests\Interventions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterventionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string', 'min:10', 'max:5000'],
            'actions_taken' => ['required', 'string', 'min:10', 'max:5000'],
            'final_outcome' => ['required', Rule::in(['operational', 'operational-monitoring', 'follow-up-required'])],
            'observations' => [
                Rule::requiredIf(fn (): bool => $this->input('final_outcome') === 'follow-up-required'),
                'nullable', 'string', 'max:5000',
            ],
            'parts' => ['present', 'array', 'max:30'],
            'parts.*' => ['string', 'max:120'],
            'safety_checks' => ['required', 'array'],
            'safety_checks.work_area_safe' => ['required', 'accepted'],
            'safety_checks.connector_inspected' => ['required', 'accepted'],
            'safety_checks.station_status_verified' => ['required', 'accepted'],
        ];
    }
}
