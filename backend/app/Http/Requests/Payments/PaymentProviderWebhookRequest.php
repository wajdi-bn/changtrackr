<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentProviderWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', 'max:80'],
            'operation' => ['required', Rule::in(['authorize', 'capture', 'release', 'charge'])],
            'status' => ['required', Rule::in(['authorized', 'captured', 'released', 'paid', 'declined', 'failed'])],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'provider_transaction_id' => ['nullable', 'string', 'max:255'],
            'authorization_id' => ['nullable', 'string', 'max:255'],
            'amount_millimes' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'idempotency_key' => ['required', 'uuid'],
            'failure_code' => ['nullable', 'string', 'max:80'],
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
