<?php

namespace App\Services\Ocpp;

use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppSimulatorAction;
use App\Models\Station;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ClientChargingTerminalService
{
    public function __construct(private readonly OcppSimulatorActionService $actions) {}

    /** @param array{action: string, idempotency_key: string} $attributes */
    public function execute(User $client, Station $station, Connector $connector, array $attributes): OcppSimulatorAction
    {
        $existing = OcppSimulatorAction::query()
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->first();
        if ($existing !== null) {
            if ($existing->requested_by_id === $client->id
                && $existing->station_id === $station->id
                && $existing->connector_id === $connector->id
                && $existing->action === $attributes['action']
                && $existing->origin === 'client_terminal') {
                return $existing;
            }

            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used for another terminal action.'],
            ]);
        }

        $this->assertStationAndConnectorAreUsable($station, $connector);

        if ($attributes['action'] === 'plug') {
            $this->assertCanPlug($client, $connector);
        } else {
            $this->assertCanUnplug($client, $connector);
        }

        return $this->actions->queue($station, $client, [
            'action' => $attributes['action'],
            'connector_id' => $connector->ocpp_connector_id,
            'origin' => 'client_terminal',
            'idempotency_key' => $attributes['idempotency_key'],
        ]);
    }

    private function assertStationAndConnectorAreUsable(Station $station, Connector $connector): void
    {
        $this->actions->ensureSimulatorStation($station);
        $station->loadMissing('organization');

        if ($connector->station_id !== $station->id || $connector->ocpp_connector_id === null) {
            throw ValidationException::withMessages([
                'connector' => ['The selected connector does not belong to this virtual station.'],
            ]);
        }

        if ($station->organization?->status !== 'active'
            || $station->availability_override !== null
            || ! $station->hasFreshOcppConnection()) {
            throw ValidationException::withMessages([
                'station' => ['The virtual station terminal is not currently available.'],
            ]);
        }
    }

    private function assertCanPlug(User $client, Connector $connector): void
    {
        if ($connector->status !== 'available' || $connector->ocpp_status !== 'Available') {
            throw ValidationException::withMessages([
                'connector' => ['The selected connector is no longer ready for cable insertion.'],
            ]);
        }

        if ($this->hasActiveWorkflow($client, $connector)) {
            throw ValidationException::withMessages([
                'session' => ['The client or connector already has an active charging workflow.'],
            ]);
        }
    }

    private function assertCanUnplug(User $client, Connector $connector): void
    {
        if ($connector->ocpp_status !== 'Preparing' || $this->hasActiveWorkflow($client, $connector)) {
            throw ValidationException::withMessages([
                'connector' => ['The cable cannot be removed from this workflow state.'],
            ]);
        }

        $ownsRecentPlug = OcppSimulatorAction::query()
            ->where('connector_id', $connector->id)
            ->where('requested_by_id', $client->id)
            ->where('origin', 'client_terminal')
            ->where('action', 'plug')
            ->where('queued_at', '>=', now()->subMinutes(15))
            ->whereIn('status', ['queued', 'running', 'succeeded'])
            ->exists();

        if (! $ownsRecentPlug) {
            throw ValidationException::withMessages([
                'connector' => ['Only the client who initiated this virtual cable connection may cancel it.'],
            ]);
        }
    }

    private function hasActiveWorkflow(User $client, Connector $connector): bool
    {
        return ChargingAttempt::query()
            ->whereIn('status', ['payment_pending', 'authorized', 'command_queued', 'command_sent', 'awaiting_station', 'charging'])
            ->where(fn ($query) => $query->where('user_id', $client->id)->orWhere('connector_id', $connector->id))
            ->exists()
            || ChargingSession::query()
                ->whereIn('status', ['pending', 'charging', 'stopping'])
                ->where(fn ($query) => $query->where('client_id', $client->id)->orWhere('connector_id', $connector->id))
                ->exists();
    }
}
