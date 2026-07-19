<?php

namespace App\Http\Requests\Ocpp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOcppCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'connection_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['accepted', 'rejected', 'failed'])],
            'result' => ['sometimes', 'array'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
