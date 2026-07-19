<?php

namespace App\Http\Requests\Ocpp;

use Illuminate\Foundation\Http\FormRequest;

class ClaimOcppCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'station_identity' => ['required', 'string', 'max:80'],
            'connection_id' => ['required', 'uuid'],
        ];
    }
}
