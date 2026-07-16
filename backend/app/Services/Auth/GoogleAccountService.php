<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleOAuthException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleAccountService
{
    public function resolve(SocialiteUser $googleUser): User
    {
        $providerUserId = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $rawUser = $googleUser->getRaw();
        $emailVerified = filter_var(
            $rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        if ($providerUserId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GoogleOAuthException('missing_identity');
        }

        if (! $emailVerified) {
            throw new GoogleOAuthException('email_not_verified');
        }

        return DB::transaction(function () use ($googleUser, $providerUserId, $email): User {
            $socialAccount = SocialAccount::query()
                ->with('user.organization')
                ->where('provider', 'google')
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($socialAccount) {
                $user = $socialAccount->user;
                $this->ensureUserCanLogin($user);

                if ($socialAccount->provider_email !== $email) {
                    $socialAccount->update(['provider_email' => $email]);
                }

                return $user;
            }

            $user = User::query()
                ->with('organization')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($user) {
                $this->ensureUserCanLogin($user);

                $otherGoogleAccount = $user->socialAccounts()
                    ->where('provider', 'google')
                    ->lockForUpdate()
                    ->first();

                if ($otherGoogleAccount && $otherGoogleAccount->provider_user_id !== $providerUserId) {
                    throw new GoogleOAuthException('account_conflict');
                }

                $updates = [];
                if ($user->email_verified_at === null) {
                    $updates['email_verified_at'] = now();
                }
                if (! $user->avatar_url && $googleUser->getAvatar()) {
                    $updates['avatar_url'] = $googleUser->getAvatar();
                }
                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }
            } else {
                $user = User::query()->create([
                    'organization_id' => null,
                    'name' => trim((string) $googleUser->getName()) ?: Str::before($email, '@'),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'avatar_url' => $googleUser->getAvatar(),
                    'status' => 'active',
                    'password' => Str::random(64),
                ]);
                $user->assignRole('client');
            }

            $user->socialAccounts()->create([
                'provider' => 'google',
                'provider_user_id' => $providerUserId,
                'provider_email' => $email,
            ]);

            return $user->load('organization');
        });
    }

    private function ensureUserCanLogin(User $user): void
    {
        if ($user->status !== 'active') {
            throw new GoogleOAuthException('account_inactive');
        }

        if (! $user->hasValidOrganizationAssignment()) {
            throw new GoogleOAuthException('invalid_organization');
        }
    }
}
