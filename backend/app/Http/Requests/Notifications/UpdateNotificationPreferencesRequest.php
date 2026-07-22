<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email_alerts' => ['sometimes', 'boolean'],
            'email_assignments' => ['sometimes', 'boolean'],
            'email_interventions' => ['sometimes', 'boolean'],
            'email_maintenance' => ['sometimes', 'boolean'],
            'email_sla' => ['sometimes', 'boolean'],
            'email_payments' => ['sometimes', 'boolean'],
        ];
    }
}
