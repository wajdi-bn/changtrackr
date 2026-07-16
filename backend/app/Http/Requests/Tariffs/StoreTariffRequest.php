<?php

namespace App\Http\Requests\Tariffs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'currency' => ['required', Rule::in(['TND'])],
            'price_per_kwh_millimes' => ['required', 'integer', 'min:0', 'max:100000'],
            'session_fee_millimes' => ['required', 'integer', 'min:0', 'max:100000'],
            'idle_fee_per_minute_millimes' => ['required', 'integer', 'min:0', 'max:100000'],
            'minimum_charge_millimes' => ['required', 'integer', 'min:0', 'max:1000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
