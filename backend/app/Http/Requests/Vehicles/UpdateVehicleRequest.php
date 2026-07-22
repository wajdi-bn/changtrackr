<?php

namespace App\Http\Requests\Vehicles;

class UpdateVehicleRequest extends StoreVehicleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules, string $field) => $field === 'connector_types.*'
                ? $rules
                : array_merge(['sometimes'], array_values(array_filter($rules, fn ($rule) => $rule !== 'required'))))
            ->all();
    }
}
