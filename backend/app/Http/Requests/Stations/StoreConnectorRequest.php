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
        return [
            'external_id' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(['CCS2', 'Type 2', 'CHAdeMO'])],
            'current_type' => ['required', Rule::in(['AC', 'DC'])],
            'max_power_kw' => ['required', 'numeric', 'min:1', 'max:1000'],
            'status' => ['required', Rule::in(['available', 'charging', 'faulted', 'offline', 'maintenance'])],
            'error_code' => ['nullable', 'string', 'max:120'],
        ];
    }
}
