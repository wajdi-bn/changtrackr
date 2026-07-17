<?php

namespace App\Http\Requests\DemoRequests;

use App\Models\DemoRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(array_diff(DemoRequest::STATUSES, ['provisioned']))],
            'scheduled_at' => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('status') === 'demo_scheduled' && ! $this->filled('scheduled_at')) {
                $validator->errors()->add('scheduled_at', 'A scheduled date is required for a planned demo.');
            }
        }];
    }
}
