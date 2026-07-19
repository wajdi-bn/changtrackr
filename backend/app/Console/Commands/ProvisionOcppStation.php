<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ProvisionOcppStation extends Command
{
    protected $signature = 'ocpp:provision-station
        {station : Existing station reference or OCPP identity}
        {--identity= : Stable OCPP identity; defaults to the station reference}
        {--secret= : Station Basic Auth secret; defaults to OCPP_SIMULATOR_STATION_SECRET}';

    protected $description = 'Provision an OCPP 1.6 station identity and hashed Basic Auth secret';

    public function handle(AvailabilityProjectionService $availabilityProjector): int
    {
        $lookup = (string) $this->argument('station');
        $station = Station::query()
            ->where('reference', $lookup)
            ->orWhere('ocpp_identity', $lookup)
            ->first();

        if ($station === null) {
            $this->error("Station [{$lookup}] was not found.");

            return self::FAILURE;
        }

        if ($station->ocpp_version !== 'OCPP 1.6J') {
            $this->error('Only OCPP 1.6J stations can be provisioned by the current Gateway.');

            return self::FAILURE;
        }

        $identity = (string) ($this->option('identity') ?: $station->ocpp_identity ?: $station->reference);
        $secret = (string) ($this->option('secret') ?: config('ocpp.simulator.station_secret'));

        if (strlen($secret) < 32) {
            $this->error('The station secret must contain at least 32 characters.');

            return self::FAILURE;
        }

        if (Station::query()->where('ocpp_identity', $identity)->whereKeyNot($station->id)->exists()) {
            $this->error("The OCPP identity [{$identity}] is already assigned.");

            return self::FAILURE;
        }

        $station->update([
            'ocpp_identity' => $identity,
            'ocpp_auth_secret_hash' => Hash::make($secret),
            'ocpp_registration_status' => 'unknown',
            'availability_monitoring_started_at' => now(),
        ]);

        $availabilityProjector->project($station->id);

        $this->info("Station [{$station->reference}] provisioned as [{$identity}].");

        return self::SUCCESS;
    }
}
