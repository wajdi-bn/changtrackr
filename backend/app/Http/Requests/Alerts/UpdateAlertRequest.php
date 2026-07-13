<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_technician_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'required', Rule::in(['new', 'in-progress', 'resolved'])],
            'severity' => ['sometimes', 'required', Rule::in(['critical', 'warning', 'info'])],
            'description' => ['sometimes', 'required', 'string', 'max:3000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
