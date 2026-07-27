<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\AcceptAccountInvitationRequest;
use App\Http\Requests\Invitations\InspectAccountInvitationRequest;
use App\Services\AccountInvitationService;
use Illuminate\Http\JsonResponse;

class AccountInvitationController extends Controller
{
    public function inspect(
        InspectAccountInvitationRequest $request,
        AccountInvitationService $invitations,
    ): JsonResponse {
        $attributes = $request->validated();
        $invitation = $invitations->inspect($attributes['email'], $attributes['token']);

        if (! $invitation || ! $invitation->isUsable()) {
            return response()->json(['valid' => false]);
        }

        return response()->json([
            'valid' => true,
            'invitation' => [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization' => $invitation->organization->name,
                'expires_at' => $invitation->expires_at->toISOString(),
            ],
        ]);
    }

    public function accept(
        AcceptAccountInvitationRequest $request,
        AccountInvitationService $invitations,
    ): JsonResponse {
        $attributes = $request->validated();
        $invitations->accept(
            $attributes['email'],
            $attributes['token'],
            $attributes['password'],
            [
                'phone' => $attributes['phone'] ?? null,
                'job_title' => $attributes['job_title'] ?? null,
            ],
            $request->file('organization_logo'),
        );

        return response()->json([
            'message' => 'Your account is active. You can now sign in with your new password.',
        ]);
    }
}
