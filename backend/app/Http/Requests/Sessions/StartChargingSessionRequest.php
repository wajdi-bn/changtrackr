<?php

namespace App\Http\Requests\Sessions;

use Illuminate\Foundation\Http\FormRequest;

class StartChargingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['required', 'integer', 'exists:connectors,id'],
        ];
    }
}
