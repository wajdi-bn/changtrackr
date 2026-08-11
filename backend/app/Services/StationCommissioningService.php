<?php

namespace App\Services;

use App\Jobs\ProvisionOcppSimulatorStation;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppSimulatorControlClient;
use App\Services\Ocpp\OcppStationProvisioningService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class StationCommissioningService
{
    public function __construct(
        private readonly OrganizationEntitlementService $entitlements,
        private readonly OcppStationProvisioningService $provisioning,
        private readonly OcppSimulatorControlClient $simulator,
        private readonly PlatformAuditService $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function simulatorProfiles(): array
    {
        try {
            return $this->simulator->profiles();
        } catch (Throwable $exception) {
            report($exception);

            throw new ServiceUnavailableHttpException(
                null,
                'The simulator profile catalog is temporarily unavailable.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{station: Station, commissioning: array<string, mixed>}
     */
    public function create(User $actor, array $attributes): array
    {
        $target = (string) $attributes['commissioning_target'];
        $simulatorProfile = $target === 'simulator'
            ? $this->findSimulatorProfile((string) $attributes['simulator_profile'])
            : null;
        $simulatorSecret = $target === 'simulator'
            ? $this->simulatorStationSecret()
            : null;

        $result = DB::transaction(function () use ($actor, $attributes, $target, $simulatorProfile, $simulatorSecret): array {
            $organizationId = $actor->hasRole('super_admin')
                ? (int) $attributes['organization_id']
                : (int) $actor->organization_id;
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $this->entitlements->assertCanCreateStation($organization);

            $stationAttributes = Arr::only($attributes, [
                'name', 'reference', 'ocpp_identity', 'location_name', 'city', 'address',
                'latitude', 'longitude', 'max_power_kw', 'model', 'manufacturer',
                'ocpp_version', 'model_image',
            ]);
            if ($simulatorProfile !== null) {
                $stationAttributes = [
                    ...$stationAttributes,
                    'max_power_kw' => $simulatorProfile['max_power_kw'],
                    'model' => $simulatorProfile['model'],
                    'manufacturer' => $simulatorProfile['manufacturer'],
                    'ocpp_version' => 'OCPP 1.6J',
                    'model_image' => $simulatorProfile['model_image'],
                ];
            }

            $station = Station::query()->create([
                ...$stationAttributes,
                'organization_id' => $organization->id,
                'status' => 'offline',
                'ocpp_commissioning_target' => $target,
                'ocpp_simulator_profile' => $simulatorProfile['key'] ?? null,
                'ocpp_provisioning_status' => $target === 'simulator' ? 'queued' : 'not_required',
            ]);

            $connectors = $simulatorProfile['connectors'] ?? $attributes['connectors'];
            foreach ($connectors as $connector) {
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
            } elseif ($target === 'simulator') {
                $station = $this->provisioning->provision($station, $simulatorSecret, 'simulator');
            }

            $station = $station->fresh()->load(['organization', 'connectors'])->loadCount('connectors');
            $this->audit->record(
                $actor,
                'station.commissioned',
                $station,
                "Commissioned station {$station->reference} with {$station->connectors->count()} connectors.",
                [
                    'commissioning_target' => $target,
                    'simulator_profile' => $simulatorProfile['key'] ?? null,
                    'ocpp_identity' => $station->ocpp_identity,
                    'connector_count' => $station->connectors->count(),
                ],
            );

            return [
                'station' => $station,
                'commissioning' => $this->provisioning->instructions($station, $secret),
            ];
        });

        if ($target === 'simulator') {
            ProvisionOcppSimulatorStation::dispatch($result['station']->id);
        }

        return $result;
    }

    /**
     * @return array{station: Station, commissioning: array<string, mixed>}
     */
    public function retrySimulatorProvisioning(User $actor, Station $station): array
    {
        $station = DB::transaction(function () use ($actor, $station): Station {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            if ($station->ocpp_commissioning_target !== 'simulator' || $station->ocpp_simulator_profile === null) {
                throw ValidationException::withMessages([
                    'station' => ['Only simulator-backed stations can be provisioned from this endpoint.'],
                ]);
            }
            if (! in_array($station->ocpp_provisioning_status, ['failed', 'not_provisioned'], true)) {
                throw ValidationException::withMessages([
                    'station' => ['This station is already provisioned or provisioning is still in progress.'],
                ]);
            }

            $station->update([
                'ocpp_provisioning_status' => 'queued',
                'ocpp_provisioning_error' => null,
            ]);
            $this->audit->record(
                $actor,
                'station.simulator_provisioning_retried',
                $station,
                "Retried simulator provisioning for station {$station->reference}.",
            );

            return $station->fresh()->load(['organization', 'connectors'])->loadCount('connectors');
        });

        ProvisionOcppSimulatorStation::dispatch($station->id);

        return [
            'station' => $station,
            'commissioning' => $this->provisioning->instructions($station),
        ];
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

    /** @return array<string, mixed> */
    private function findSimulatorProfile(string $key): array
    {
        $profile = collect($this->simulatorProfiles())->firstWhere('key', $key);
        if (! is_array($profile)) {
            throw ValidationException::withMessages([
                'simulator_profile' => ['The selected simulator hardware profile is not supported.'],
            ]);
        }

        return $profile;
    }

    private function simulatorStationSecret(): string
    {
        $secret = (string) config('ocpp.simulator.station_secret');
        if (strlen($secret) < 32) {
            throw new ServiceUnavailableHttpException(
                null,
                'Simulator provisioning is not configured on this environment.',
            );
        }

        return $secret;
    }
}
