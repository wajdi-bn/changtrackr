<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailRequest;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (
            ! $request->hasValidSignature()
            || ! $user
            || $user->status !== 'active'
            || ! $user->hasRole('client')
            || $user->organization_id !== null
            || ! hash_equals(sha1($user->getEmailForVerification()), $hash)
        ) {
            return $this->frontendRedirect('/verify-email?status=invalid');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->frontendRedirect('/verify-email?status=verified');
    }

    public function resend(EmailRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (
            $user
            && $user->status === 'active'
            && $user->requiresClientEmailVerification()
            && $user->hasValidOrganizationAssignment()
        ) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If an unverified client account exists, a new verification email has been sent.',
        ]);
    }

    private function frontendRedirect(string $path): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('frontend.url'), '/');

        return redirect()->away($frontendUrl.$path);
    }
}
