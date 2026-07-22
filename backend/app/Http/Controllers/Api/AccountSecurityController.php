<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountSecurityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load('socialAccounts');

        return response()->json(['data' => [
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'password_login_enabled' => $user->hasLocalPasswordLogin(),
            'sign_in_providers' => $user->socialAccounts
                ->pluck('provider')
                ->unique()
                ->values(),
        ]]);
    }

    public function changePassword(ChangePasswordRequest $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasLocalPasswordLogin(), 403, 'Password management is not available for this account.');

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $request->validated('password')])->save();
        $audit->record($user, 'account.password_changed', $user, 'Changed their account password.');

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
