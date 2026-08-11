<?php

namespace App\Services\Ocpp;

use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OcppStationProvisioningService
{
    public function __construct(private readonly AvailabilityProjectionService $availabilityProjector) {}

    public function generateSecret(): string
    {
        return Str::lower(Str::random(48));
    }

    public function provision(Station $station, string $secret, string $target = 'external'): Station
    {
        if ($station->ocpp_version !== 'OCPP 1.6J') {
            throw ValidationException::withMessages([
                'ocpp_version' => ['Only OCPP 1.6J stations can be provisioned by the current gateway.'],
            ]);
        }

        if (strlen($secret) < 32) {
            throw ValidationException::withMessages([
                'secret' => ['The station secret must contain at least 32 characters.'],
            ]);
        }

        if (! in_array($target, ['external', 'simulator'], true)) {
            throw ValidationException::withMessages([
                'commissioning_target' => ['The provisioning target must be external or simulator.'],
            ]);
        }

        $station->update([
            'ocpp_auth_secret_hash' => Hash::make($secret),
            'ocpp_commissioning_target' => $target,
            'ocpp_registration_status' => 'unknown',
            'availability_monitoring_started_at' => now(),
        ]);

        return $this->availabilityProjector->project($station->id)['station'];
    }

    /** @return array<string, mixed> */
    public function instructions(Station $station, ?string $secret = null): array
    {
        $baseUrl = rtrim((string) config('ocpp.gateway.public_url', 'ws://localhost:9000/ocpp'), '/');
        $identity = (string) $station->ocpp_identity;

        return [
            'status' => $station->commissioningStatus(),
            'target' => $station->ocpp_commissioning_target,
            'gateway_url' => $baseUrl,
            'connection_url' => $baseUrl.'/'.rawurlencode($identity),
            'identity' => $identity,
            'username' => $identity,
            'secret' => $secret,
            'secret_visible_once' => $secret !== null,
            'simulator_profile' => $station->ocpp_simulator_profile,
            'provisioning_status' => $station->ocpp_provisioning_status,
            'provisioning_error' => $station->ocpp_provisioning_error,
        ];
    }
}
