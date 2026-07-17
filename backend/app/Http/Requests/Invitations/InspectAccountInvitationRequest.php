<?php

namespace App\Http\Requests\Invitations;

use Illuminate\Foundation\Http\FormRequest;

class InspectAccountInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'min:40', 'max:255'],
        ];
    }
}
