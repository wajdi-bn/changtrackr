<?php

namespace App\Services\Ocpp;

use App\Models\Station;
use Illuminate\Support\Facades\Hash;

class OcppStationAuthenticationService
{
    public function authenticate(string $identity, string $username, string $password): ?Station
    {
        if (! hash_equals($identity, $username)) {
            return null;
        }

        $station = Station::query()
            ->where('ocpp_identity', $identity)
            ->where('ocpp_version', 'OCPP 1.6J')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->first();

        if ($station === null
            || ! is_string($station->ocpp_auth_secret_hash)
            || ! Hash::check($password, $station->ocpp_auth_secret_hash)) {
            return null;
        }

        return $station;
    }
}
