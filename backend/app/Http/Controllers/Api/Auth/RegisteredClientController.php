<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterClientRequest;
use App\Models\User;
use App\Services\PlatformSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegisteredClientController extends Controller
{
    public function store(RegisterClientRequest $request, PlatformSettingService $settings): JsonResponse
    {
        abort_unless($settings->boolean('client_registration_enabled'), 403, 'Public client registration is currently disabled.');

        $attributes = $request->validated();

        $user = DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'organization_id' => null,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => 'active',
            ]);
            $user->assignRole('client');

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Account created. Check your email to verify your address.',
            'code' => 'verification_required',
            'email' => $user->email,
        ], 201);
    }
}
