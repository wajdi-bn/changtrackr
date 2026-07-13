<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $station = $this->route('station');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'reference' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('stations', 'reference')->ignore($station)],
            'location_name' => ['sometimes', 'required', 'string', 'max:160'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'required', Rule::in(['available', 'charging', 'faulted', 'offline', 'maintenance'])],
            'max_power_kw' => ['sometimes', 'required', 'numeric', 'min:1', 'max:1000'],
            'model' => ['sometimes', 'required', 'string', 'max:120'],
            'manufacturer' => ['sometimes', 'required', 'string', 'max:120'],
            'ocpp_version' => ['sometimes', 'required', Rule::in(['OCPP 1.6J', 'OCPP 2.0.1'])],
            'model_image' => ['nullable', 'string', 'max:255'],
        ];
    }
}
