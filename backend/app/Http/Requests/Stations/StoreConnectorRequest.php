<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $station = $this->route('station');
        $connector = $this->route('connector');
        $managed = $station?->isOcppManaged() ?? false;

        return [
            'external_id' => ['required', 'string', 'max:60'],
            'ocpp_connector_id' => [
                'sometimes',
                'integer',
                'min:1',
                'max:65535',
                Rule::unique('connectors', 'ocpp_connector_id')
                    ->where('station_id', $station?->id)
                    ->ignore($connector?->id),
            ],
            'type' => ['required', Rule::in(['CCS2', 'Type 2', 'CHAdeMO'])],
            'current_type' => ['required', Rule::in(['AC', 'DC'])],
            'max_power_kw' => ['required', 'numeric', 'min:1', 'max:1000'],
            'status' => [Rule::prohibitedIf($managed), Rule::requiredIf(! $managed), 'nullable', Rule::in(['available', 'charging', 'faulted', 'offline', 'maintenance', 'reserved', 'unavailable'])],
            'error_code' => [Rule::prohibitedIf($managed), 'nullable', 'string', 'max:120'],
        ];
    }
}
