<?php

namespace App\Http\Requests\DemoRequests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'internal_notes' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
