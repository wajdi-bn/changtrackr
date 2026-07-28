<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CommissionStationRequest extends FormRequest
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
                Rule::requiredIf($this->user()?->hasRole('super_admin')),
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where('status', 'active'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'reference' => ['required', 'string', 'max:80', 'unique:stations,reference'],
            'ocpp_identity' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9._:-]+$/',
                'unique:stations,ocpp_identity',
            ],
            'location_name' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'max_power_kw' => ['required', 'numeric', 'min:1', 'max:1000'],
            'model' => ['required', 'string', 'max:120'],
            'manufacturer' => ['required', 'string', 'max:120'],
            'ocpp_version' => ['required', Rule::in(['OCPP 1.6J', 'OCPP 2.0.1'])],
            'model_image' => ['nullable', 'string', 'max:255'],
            'commissioning_target' => ['required', Rule::in(['external', 'simulator', 'inventory'])],
            'connectors' => ['required', 'array', 'min:1', 'max:16'],
            'connectors.*.external_id' => ['required', 'string', 'max:60', 'distinct:strict'],
            'connectors.*.ocpp_connector_id' => ['required', 'integer', 'min:1', 'max:65535', 'distinct:strict'],
            'connectors.*.type' => ['required', Rule::in(['CCS2', 'Type 2', 'CHAdeMO'])],
            'connectors.*.current_type' => ['required', Rule::in(['AC', 'DC'])],
            'connectors.*.max_power_kw' => ['required', 'numeric', 'min:1', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->input('commissioning_target');
                if ($target !== 'inventory' && $this->input('ocpp_version') !== 'OCPP 1.6J') {
                    $validator->errors()->add(
                        'ocpp_version',
                        'The current gateway can commission only OCPP 1.6J stations.',
                    );
                }

                if ($target === 'simulator') {
                    $ids = collect($this->input('connectors', []))
                        ->pluck('ocpp_connector_id')
                        ->map(fn ($id): int => (int) $id)
                        ->sort()
                        ->values()
                        ->all();
                    $expected = range(1, count($ids));
                    if ($ids !== $expected) {
                        $validator->errors()->add(
                            'connectors',
                            'SAP simulator connector IDs must be contiguous and start at 1.',
                        );
                    }
                }
            },
        ];
    }
}
