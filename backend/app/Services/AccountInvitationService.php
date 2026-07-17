<?php

namespace App\Services;

use App\Models\AccountInvitation;
use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountInvitationService
{
    /** @return array{invitation: AccountInvitation, user: User, token: string} */
    public function invite(
        Organization $organization,
        User $inviter,
        string $name,
        string $email,
        string $role,
        ?DemoRequest $demoRequest = null,
    ): array {
        $email = mb_strtolower(trim($email));

        if (! in_array($role, User::ORGANIZATION_ROLES, true)) {
            throw ValidationException::withMessages(['role' => ['Only organization roles can be invited.']]);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['An account already exists with this email address.']]);
        }

        $token = Str::random(80);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'password' => Str::random(64),
            'status' => 'pending',
            'team' => $this->defaultTeam($role),
        ]);
        $user->assignRole($role);

        $invitation = AccountInvitation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'demo_request_id' => $demoRequest?->id,
            'invited_by_id' => $inviter->id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addHours((int) config('demo.invitation_expiration_hours', 48)),
        ]);

        return compact('invitation', 'user', 'token');
    }

    public function inspect(string $email, string $token): ?AccountInvitation
    {
        $invitation = $this->queryInvitation($email, $token)
            ->with(['organization', 'user'])
            ->first();

        if (! $invitation) {
            return null;
        }

        if ($invitation->status === 'pending' && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
        }

        return $invitation->fresh(['organization', 'user']);
    }

    /** @return array{invitation: AccountInvitation, user: User, token: string} */
    public function reissueForDemo(DemoRequest $demoRequest, User $inviter): array
    {
        return DB::transaction(function () use ($demoRequest, $inviter): array {
            $previous = AccountInvitation::query()
                ->where('demo_request_id', $demoRequest->id)
                ->with(['organization', 'user'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($previous?->status === 'pending' && $previous->expires_at->isPast()) {
                $previous->update(['status' => 'expired']);
            }

            if (
                ! $previous
                || $demoRequest->status !== 'provisioned'
                || $previous->user->status !== 'pending'
                || $previous->organization->status !== 'active'
                || ! in_array($previous->status, ['revoked', 'expired'], true)
            ) {
                throw ValidationException::withMessages([
                    'invitation' => ['This administrator invitation cannot be reissued.'],
                ]);
            }

            $token = Str::random(80);
            $invitation = AccountInvitation::query()->create([
                'organization_id' => $previous->organization_id,
                'user_id' => $previous->user_id,
                'demo_request_id' => $demoRequest->id,
                'invited_by_id' => $inviter->id,
                'name' => $previous->name,
                'email' => $previous->email,
                'role' => $previous->role,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addHours((int) config('demo.invitation_expiration_hours', 48)),
            ]);

            return ['invitation' => $invitation, 'user' => $previous->user, 'token' => $token];
        });
    }

    public function revokeForDemo(DemoRequest $demoRequest): AccountInvitation
    {
        return DB::transaction(function () use ($demoRequest): AccountInvitation {
            $invitation = AccountInvitation::query()
                ->where('demo_request_id', $demoRequest->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $invitation || $demoRequest->status !== 'provisioned' || $invitation->status !== 'pending') {
                throw ValidationException::withMessages([
                    'invitation' => ['Only a pending administrator invitation can be revoked.'],
                ]);
            }

            $invitation->update(['status' => 'revoked', 'revoked_at' => now()]);

            return $invitation->fresh();
        });
    }

    public function accept(string $email, string $token, string $password): User
    {
        $candidate = $this->queryInvitation($email, $token)->first();
        if ($candidate?->status === 'pending' && $candidate->expires_at->isPast()) {
            $candidate->update(['status' => 'expired']);

            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid, expired, or has already been used.'],
            ]);
        }

        return DB::transaction(function () use ($email, $token, $password): User {
            $invitation = $this->queryInvitation($email, $token)
                ->with(['organization', 'user'])
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid, expired, or has already been used.'],
                ]);
            }

            if ($invitation->organization->status !== 'active' || $invitation->user->status !== 'pending') {
                throw ValidationException::withMessages([
                    'token' => ['This invitation can no longer activate an account.'],
                ]);
            }

            $invitation->user->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
                'status' => 'active',
            ])->save();

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            return $invitation->user->fresh(['organization', 'roles']);
        });
    }

    private function queryInvitation(string $email, string $token)
    {
        return AccountInvitation::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('token_hash', hash('sha256', $token));
    }

    private function defaultTeam(string $role): string
    {
        return match ($role) {
            'admin' => 'Management',
            'operator' => 'Network Operations',
            'technician' => 'Field Maintenance',
        };
    }
}
