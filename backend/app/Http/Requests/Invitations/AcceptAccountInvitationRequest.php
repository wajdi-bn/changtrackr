<?php

namespace App\Http\Requests\Invitations;

use Illuminate\Validation\Rules\Password;

class AcceptAccountInvitationRequest extends InspectAccountInvitationRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'organization_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
