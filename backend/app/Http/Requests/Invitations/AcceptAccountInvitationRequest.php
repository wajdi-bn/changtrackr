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
        ];
    }
}
