<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'timezone' => ['nullable', 'timezone:all'],
            'near_me_radius_km' => ['sometimes', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ];
    }
}
