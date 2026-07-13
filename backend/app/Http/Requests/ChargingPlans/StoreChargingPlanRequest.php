<?php

namespace App\Http\Requests\ChargingPlans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChargingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => [Rule::requiredIf(fn () => $this->user()?->hasRole('super_admin')), 'nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'monthly_fee_millimes' => ['required', 'integer', 'min:0', 'max:100000000'],
            'discount_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'audience' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'member_count' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
