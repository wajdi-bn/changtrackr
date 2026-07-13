<?php

namespace App\Http\Requests\Interventions;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterventionNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['description' => ['required', 'string', 'max:3000']];
    }
}
