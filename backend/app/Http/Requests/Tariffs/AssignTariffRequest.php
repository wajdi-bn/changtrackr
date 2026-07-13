<?php

namespace App\Http\Requests\Tariffs;

use Illuminate\Foundation\Http\FormRequest;

class AssignTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'station_id' => ['nullable', 'integer', 'exists:stations,id', 'required_without:connector_id', 'prohibits:connector_id'],
            'connector_id' => ['nullable', 'integer', 'exists:connectors,id', 'required_without:station_id', 'prohibits:station_id'],
        ];
    }
}
