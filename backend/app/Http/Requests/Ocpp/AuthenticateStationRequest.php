<?php

namespace App\Http\Requests\Ocpp;

use Illuminate\Foundation\Http\FormRequest;

class AuthenticateStationRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:255'],
            'protocol_version' => ['required', 'in:1.6'],
        ];
    }
}
