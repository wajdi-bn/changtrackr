<?php

namespace App\Http\Requests\Tariffs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => ['sometimes', 'required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'active', 'archived'])],
            'currency' => ['sometimes', 'required', Rule::in(['TND'])],
            'price_per_kwh_millimes' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'session_fee_millimes' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'idle_fee_per_minute_millimes' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'minimum_charge_millimes' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'is_default' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
