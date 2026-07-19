<?php

namespace App\Services\Ocpp;

use App\Data\OcppAuthorizationResult;
use App\Models\OcppIdTag;

class OcppAuthorizationService
{
    public function authorize(string $token, bool $recordUsage = true): OcppAuthorizationResult
    {
        $idTag = OcppIdTag::query()
            ->with(['user.roles'])
            ->where('token_hash', self::hash($token))
            ->first();

        if ($idTag === null) {
            return new OcppAuthorizationResult('Invalid');
        }

        if ($idTag->status !== 'active') {
            return new OcppAuthorizationResult('Blocked', $idTag);
        }

        if ($idTag->expires_at !== null && $idTag->expires_at->isPast()) {
            return new OcppAuthorizationResult('Expired', $idTag);
        }

        $user = $idTag->user;
        if ($user === null || $user->status !== 'active' || ! $user->hasRole('client')) {
            return new OcppAuthorizationResult('Blocked', $idTag, $user);
        }

        if (! $user->hasVerifiedEmail()) {
            return new OcppAuthorizationResult('Blocked', $idTag, $user);
        }

        if ($recordUsage) {
            $idTag->update(['last_used_at' => now()]);
        }

        return new OcppAuthorizationResult('Accepted', $idTag, $user);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function mask(string $token): string
    {
        $length = strlen($token);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', min(8, $length - 4)).substr($token, -4);
    }
}
