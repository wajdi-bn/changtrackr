<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_year' => ['nullable', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'license_plate' => ['nullable', 'string', 'max:32'],
            'battery_capacity_kwh' => ['nullable', 'numeric', 'min:1', 'max:250'],
            'max_charging_power_kw' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'connector_types' => ['required', 'array', 'min:1', 'max:3'],
            'connector_types.*' => ['required', 'string', Rule::in(['Type 2', 'CCS2', 'CHAdeMO']), 'distinct'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
