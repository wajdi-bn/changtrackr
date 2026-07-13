<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['simulated_card', 'simulated_edinar', 'simulated_d17'])],
            'simulation_outcome' => ['nullable', Rule::in(['success', 'declined'])],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
