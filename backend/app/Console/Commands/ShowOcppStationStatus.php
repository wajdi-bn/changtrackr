<?php

namespace App\Console\Commands;

use App\Models\OcppEvent;
use App\Models\OcppTransaction;
use App\Models\Station;
use Illuminate\Console\Command;

class ShowOcppStationStatus extends Command
{
    protected $signature = 'ocpp:status {station : Existing station reference or OCPP identity}';

    protected $description = 'Show the latest OCPP telemetry without exposing station credentials';

    public function handle(): int
    {
        $lookup = (string) $this->argument('station');
        $station = Station::query()
            ->with(['connectors' => fn ($query) => $query->orderBy('ocpp_connector_id')])
            ->where('reference', $lookup)
            ->orWhere('ocpp_identity', $lookup)
            ->first();

        if ($station === null) {
            $this->error("Station [{$lookup}] was not found.");

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Reference', $station->reference],
            ['OCPP identity', $station->ocpp_identity ?? '-'],
            ['Business status', $station->status],
            ['Registration', $station->ocpp_registration_status ?? 'unknown'],
            ['Raw station status', $station->ocpp_status ?? '-'],
            ['Raw error code', $station->ocpp_error_code ?? '-'],
            ['Connected at', $station->ocpp_connected_at?->utc()->toIso8601String() ?? '-'],
            ['Last message', $station->ocpp_last_message_at?->utc()->toIso8601String() ?? '-'],
            ['Last heartbeat', $station->last_heartbeat_at?->utc()->toIso8601String() ?? '-'],
        ]);

        $this->newLine();
        $this->info('Connectors');
        $this->table(
            ['OCPP ID', 'External ID', 'Business status', 'Raw OCPP status', 'Raw error'],
            $station->connectors->map(fn ($connector) => [
                $connector->ocpp_connector_id ?? '-',
                $connector->external_id,
                $connector->status,
                $connector->ocpp_status ?? '-',
                $connector->ocpp_error_code ?? '-',
            ])->all(),
        );

        $this->newLine();
        $this->info('Ingested events');
        $this->table(
            ['Action', 'Processing status', 'Count'],
            OcppEvent::query()
                ->where('station_id', $station->id)
                ->selectRaw('action, processing_status, count(*) as total')
                ->groupBy('action', 'processing_status')
                ->orderBy('action')
                ->get()
                ->map(fn (OcppEvent $event) => [
                    $event->action,
                    $event->processing_status,
                    $event->getAttribute('total'),
                ])->all(),
        );

        $this->newLine();
        $this->info('Latest transactions');
        $this->table(
            ['ID', 'Connector', 'Tag', 'Status', 'Energy', 'Stop reason', 'Started'],
            OcppTransaction::query()
                ->with(['chargingSession', 'connector'])
                ->where('station_id', $station->id)
                ->latest('started_at')
                ->limit(10)
                ->get()
                ->map(fn (OcppTransaction $transaction) => [
                    $transaction->id,
                    $transaction->connector?->external_id ?? $transaction->connector_id ?? '-',
                    $transaction->id_tag_masked,
                    $transaction->status,
                    $transaction->chargingSession
                        ? number_format((float) $transaction->chargingSession->energy_kwh, 3).' kWh'
                        : '-',
                    $transaction->stop_reason ?? $transaction->rejection_reason ?? '-',
                    $transaction->started_at->utc()->toIso8601String(),
                ])->all(),
        );

        return self::SUCCESS;
    }
}
