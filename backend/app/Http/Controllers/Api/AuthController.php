<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$uP7p6bkzSr1Vy5N8GqDRVeNGAUoSP9C0YPyTmbcIwcivOSfPkn8Ay';

    public function session(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'user' => null,
            ]);
        }

        if (
            $user->status !== 'active'
            || ! $user->hasValidOrganizationAssignment()
            || $user->requiresClientEmailVerification()
        ) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'authenticated' => false,
                'user' => null,
            ]);
        }

        return response()->json([
            'authenticated' => true,
            'user' => new UserResource($user->load('organization')),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()
            ->with('organization')
            ->where('email', $credentials['email'])
            ->first();

        $hasLocalPassword = $user !== null
            && $user->hasLocalPasswordLogin()
            && is_string($user->password);
        $passwordHash = $hasLocalPassword ? $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($credentials['password'], $passwordHash);

        if (! $user || ! $hasLocalPassword || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is not active.',
            ], 403);
        }

        if (! $user->hasValidOrganizationAssignment()) {
            return response()->json([
                'message' => 'This account does not have a valid organization assignment.',
            ], 403);
        }

        if ($user->requiresClientEmailVerification()) {
            return response()->json([
                'message' => 'Please verify your email address before signing in.',
                'code' => 'email_unverified',
            ], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => new UserResource($user->fresh('organization')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('organization'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
