<?php

namespace App\Services\Ocpp;

use App\Models\OcppIdTag;
use App\Models\User;
use Illuminate\Support\Str;

class VirtualOcppIdTagService
{
    public function forClient(User $client): OcppIdTag
    {
        $existing = $client->ocppIdTags()
            ->where('kind', 'virtual_app')
            ->where('status', 'active')
            ->whereNotNull('token_ciphertext')
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        do {
            $token = 'APP'.Str::upper(Str::random(17));
            $hash = OcppAuthorizationService::hash($token);
        } while (OcppIdTag::query()->where('token_hash', $hash)->exists());

        return $client->ocppIdTags()->create([
            'token_hash' => $hash,
            'token_ciphertext' => $token,
            'masked_token' => OcppAuthorizationService::mask($token),
            'label' => 'ChargeTrackr app',
            'kind' => 'virtual_app',
            'status' => 'active',
        ]);
    }
}
