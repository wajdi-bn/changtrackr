<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppStationProvisioningService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StationCommissioningService
{
    public function __construct(
        private readonly OrganizationEntitlementService $entitlements,
        private readonly OcppStationProvisioningService $provisioning,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{station: Station, commissioning: array<string, mixed>}
     */
    public function create(User $actor, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $attributes): array {
            $organizationId = $actor->hasRole('super_admin')
                ? (int) $attributes['organization_id']
                : (int) $actor->organization_id;
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $this->entitlements->assertCanCreateStation($organization);

            $target = (string) $attributes['commissioning_target'];
            $station = Station::query()->create([
                ...Arr::only($attributes, [
                    'name', 'reference', 'ocpp_identity', 'location_name', 'city', 'address',
                    'latitude', 'longitude', 'max_power_kw', 'model', 'manufacturer',
                    'ocpp_version', 'model_image',
                ]),
                'organization_id' => $organization->id,
                'status' => 'offline',
                'ocpp_commissioning_target' => $target,
            ]);

            foreach ($attributes['connectors'] as $connector) {
                $station->connectors()->create([
                    ...Arr::only($connector, [
                        'external_id', 'ocpp_connector_id', 'type', 'current_type', 'max_power_kw',
                    ]),
                    'status' => $target === 'external' ? 'unavailable' : 'offline',
                    'last_status_at' => now(),
                ]);
            }

            $secret = null;
            if ($target === 'external') {
                $secret = $this->provisioning->generateSecret();
                $station = $this->provisioning->provision($station, $secret, 'external');
            }

            $station = $station->fresh()->load(['organization', 'connectors'])->loadCount('connectors');
            $this->audit->record(
                $actor,
                'station.commissioned',
                $station,
                "Commissioned station {$station->reference} with {$station->connectors->count()} connectors.",
                [
                    'commissioning_target' => $target,
                    'ocpp_identity' => $station->ocpp_identity,
                    'connector_count' => $station->connectors->count(),
                ],
            );

            return [
                'station' => $station,
                'commissioning' => $this->provisioning->instructions($station, $secret),
            ];
        });
    }

    /**
     * @return array{station: Station, commissioning: array<string, mixed>}
     */
    public function rotateExternalCredentials(User $actor, Station $station): array
    {
        $secret = $this->provisioning->generateSecret();
        $station = $this->provisioning->provision($station, $secret, 'external');
        $station = $station->fresh()->load(['organization', 'connectors'])->loadCount('connectors');

        $this->audit->record(
            $actor,
            'station.ocpp_credentials_rotated',
            $station,
            "Rotated OCPP credentials for station {$station->reference}.",
            ['ocpp_identity' => $station->ocpp_identity],
        );

        return [
            'station' => $station,
            'commissioning' => $this->provisioning->instructions($station, $secret),
        ];
    }
}
