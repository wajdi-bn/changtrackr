<?php

namespace App\Services\Ocpp;

use App\Jobs\ExecuteOcppSimulatorAction;
use App\Models\Connector;
use App\Models\OcppSimulatorAction;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OcppSimulatorActionService
{
    /** @param array{action: string, connector_id?: int|null} $payload */
    public function queue(Station $station, User $user, array $payload): OcppSimulatorAction
    {
        $action = DB::transaction(function () use ($station, $user, $payload): OcppSimulatorAction {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            $this->ensureSimulatorStation($station);

            $connector = null;
            if (isset($payload['connector_id'])) {
                $connector = Connector::query()
                    ->where('station_id', $station->id)
                    ->where('ocpp_connector_id', $payload['connector_id'])
                    ->first();
                if ($connector === null) {
                    throw ValidationException::withMessages([
                        'connector_id' => ['This OCPP connector does not belong to the selected station.'],
                    ]);
                }
            }

            $hasPendingAction = OcppSimulatorAction::query()
                ->where('station_id', $station->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->exists();
            if ($hasPendingAction) {
                throw ValidationException::withMessages([
                    'action' => ['Another simulator action is still running for this station.'],
                ]);
            }

            return OcppSimulatorAction::query()->create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $station->organization_id,
                'station_id' => $station->id,
                'connector_id' => $connector?->id,
                'requested_by_id' => $user->id,
                'action' => $payload['action'],
                'status' => 'queued',
                'request_payload' => $connector === null
                    ? []
                    : ['connector_id' => $connector->ocpp_connector_id],
                'queued_at' => now(),
            ]);
        });

        ExecuteOcppSimulatorAction::dispatch($action->id);

        return $action;
    }

    public function ensureSimulatorStation(Station $station): void
    {
        if ($station->ocpp_commissioning_target !== 'simulator' || $station->ocpp_identity === null) {
            throw ValidationException::withMessages([
                'station' => ['The simulator console is only available for simulator-backed stations.'],
            ]);
        }
    }
}
