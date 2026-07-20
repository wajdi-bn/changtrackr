<?php

namespace App\Http\Requests\Sessions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartChargingAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['required', 'integer', 'exists:connectors,id'],
            'method' => ['required', Rule::in(['simulated_card', 'simulated_edinar', 'simulated_d17'])],
            'simulation_outcome' => ['sometimes', Rule::in(['success', 'declined', 'timeout', 'provider_error'])],
            'idempotency_key' => ['required', 'uuid'],
            'limit_energy_kwh' => ['nullable', 'numeric', 'min:0.1', 'max:200', 'prohibits:limit_amount_tnd,limit_duration_minutes'],
            'limit_amount_tnd' => ['nullable', 'numeric', 'min:1', 'max:30', 'prohibits:limit_energy_kwh,limit_duration_minutes'],
            'limit_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440', 'prohibits:limit_energy_kwh,limit_amount_tnd'],
        ];
    }
}
