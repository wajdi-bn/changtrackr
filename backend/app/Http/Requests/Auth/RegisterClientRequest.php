<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::query()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail('Registration could not be completed with the provided details.');
                    }
                },
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers(),
            ],
            'terms_accepted' => ['accepted'],
        ];
    }
}
