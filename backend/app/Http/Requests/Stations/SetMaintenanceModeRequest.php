<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;

class SetMaintenanceModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
