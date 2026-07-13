<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:160'],
            'reference' => ['required', 'string', 'max:80', 'unique:stations,reference'],
            'location_name' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'status' => ['required', Rule::in(['available', 'charging', 'faulted', 'offline', 'maintenance'])],
            'max_power_kw' => ['required', 'numeric', 'min:1', 'max:1000'],
            'model' => ['required', 'string', 'max:120'],
            'manufacturer' => ['required', 'string', 'max:120'],
            'ocpp_version' => ['required', Rule::in(['OCPP 1.6J', 'OCPP 2.0.1'])],
            'model_image' => ['nullable', 'string', 'max:255'],
        ];
    }
}
