<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\Ocpp\OcppStationProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class RegisterOcppSimulatorStation extends Command
{
    protected $signature = 'ocpp:register-simulator-station
        {station : Existing station reference or OCPP identity}
        {--manifest= : Absolute or backend-relative simulator manifest path}';

    protected $description = 'Register an existing station in the local SAP OCPP simulator manifest';

    public function handle(OcppStationProvisioningService $provisioning): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Simulator registration is available only in local and testing environments.');

            return self::FAILURE;
        }

        $lookup = (string) $this->argument('station');
        $station = Station::query()
            ->with('connectors')
            ->where('reference', $lookup)
            ->orWhere('ocpp_identity', $lookup)
            ->first();

        if ($station === null) {
            $this->error("Station [{$lookup}] was not found.");

            return self::FAILURE;
        }

        if ($station->ocpp_version !== 'OCPP 1.6J') {
            $this->error('The SAP simulator profile supports only OCPP 1.6J stations.');

            return self::FAILURE;
        }

        if ($station->connectors->isEmpty()) {
            $this->error('Create at least one connector before registering the station.');

            return self::FAILURE;
        }

        $connectors = $station->connectors->sortBy('ocpp_connector_id')->values();
        $connectorIds = $connectors->pluck('ocpp_connector_id')->map(fn ($id): int => (int) $id)->all();
        if ($connectorIds !== range(1, count($connectorIds))) {
            $this->error('SAP simulator connector IDs must be contiguous and start at 1.');

            return self::FAILURE;
        }

        $secret = (string) config('ocpp.simulator.station_secret');
        if (strlen($secret) < 32) {
            $this->error('OCPP_SIMULATOR_STATION_SECRET must contain at least 32 characters.');

            return self::FAILURE;
        }

        $manifestPath = $this->resolveManifestPath();
        $manifest = $this->readManifest($manifestPath);
        if ($manifest === null) {
            return self::FAILURE;
        }

        $identity = (string) ($station->ocpp_identity ?: $station->reference);
        $entry = [
            'identity' => $identity,
            'manufacturer' => $station->manufacturer,
            'model' => $station->model,
            'maxPowerKw' => (float) $station->max_power_kw,
            'connectorPowersKw' => $connectors->pluck('max_power_kw')->map(fn ($power): float => (float) $power)->all(),
        ];

        $replaced = false;
        foreach ($manifest as $index => $configuredStation) {
            if (($configuredStation['identity'] ?? null) === $identity) {
                $manifest[$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $manifest[] = $entry;
        }

        File::ensureDirectoryExists(dirname($manifestPath));
        File::put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $station->ocpp_identity = $identity;
        $station->save();
        $provisioning->provision($station, $secret, 'simulator');

        $verb = $replaced ? 'Updated' : 'Added';
        $this->info("{$verb} simulator station [{$identity}] with {$connectors->count()} connector(s).");
        $this->newLine();
        $this->line('Restart the simulator to rebuild its generated configuration:');
        $this->line('  npm run ocpp:down');
        $this->line('  npm run ocpp:up');
        $this->line("Then inspect it with: npm run ocpp:status -- {$identity}");

        return self::SUCCESS;
    }

    private function resolveManifestPath(): string
    {
        $option = $this->option('manifest');
        if (is_string($option) && $option !== '') {
            return str_starts_with($option, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $option) === 1
                ? $option
                : base_path($option);
        }

        return base_path('../infra/ocpp/simulator/stations.json');
    }

    /** @return list<array<string, mixed>>|null */
    private function readManifest(string $manifestPath): ?array
    {
        if (! File::exists($manifestPath)) {
            return [];
        }

        try {
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("The simulator manifest is invalid JSON: {$exception->getMessage()}");

            return null;
        }

        if (! is_array($manifest) || ! array_is_list($manifest)) {
            $this->error('The simulator manifest must contain a JSON array.');

            return null;
        }

        return $manifest;
    }
}
