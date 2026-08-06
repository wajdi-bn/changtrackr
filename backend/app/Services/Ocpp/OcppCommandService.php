<?php

namespace App\Services\Ocpp;

use App\Events\ChargingAttemptChanged;
use App\Events\ChargingSessionChanged;
use App\Events\OcppCommandChanged;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Intervention;
use App\Models\OcppCommand;
use App\Models\OcppTransaction;
use App\Models\Station;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OcppCommandService
{
    private const TERMINAL_STATUSES = ['rejected', 'failed', 'timed_out'];

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function queueReset(Station $station, User $requestedBy): OcppCommand
    {
        return DB::transaction(function () use ($station, $requestedBy): OcppCommand {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            $this->ensureStationCanReceiveSupervisionCommands($station);

            return $this->queueSupervisionCommand(
                station: $station,
                requestedBy: $requestedBy,
                action: 'Reset',
                payload: ['type' => 'Soft'],
            );
        });
    }

    public function queueUnlock(Station $station, Connector $connector, User $requestedBy): OcppCommand
    {
        return DB::transaction(function () use ($station, $connector, $requestedBy): OcppCommand {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            $connector = Connector::query()->lockForUpdate()->findOrFail($connector->id);
            $this->ensureStationCanReceiveSupervisionCommands($station);

            if ($connector->station_id !== $station->id) {
                throw ValidationException::withMessages(['connector' => ['This connector does not belong to the selected station.']]);
            }
            if ($connector->ocpp_connector_id === null) {
                throw ValidationException::withMessages(['connector' => ['This connector has no OCPP connector identifier.']]);
            }

            return $this->queueSupervisionCommand(
                station: $station,
                requestedBy: $requestedBy,
                action: 'UnlockConnector',
                payload: ['connectorId' => $connector->ocpp_connector_id],
                connector: $connector,
            );
        });
    }

    public function setMaintenanceMode(
        Station $station,
        User $requestedBy,
        bool $enabled,
        ?Intervention $maintenanceIntervention = null,
    ): ?OcppCommand {
        return DB::transaction(function () use ($station, $requestedBy, $enabled, $maintenanceIntervention): ?OcppCommand {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            if (! $station->isOcppManaged()) {
                throw ValidationException::withMessages(['station' => ['Maintenance synchronization requires an OCPP-managed station.']]);
            }
            if ($station->maintenance_intervention_id !== null
                && $station->maintenance_intervention_id !== $maintenanceIntervention?->id) {
                throw ValidationException::withMessages([
                    'maintenance' => ['This station is controlled by an active maintenance intervention.'],
                ]);
            }

            $station->update(['availability_override' => $enabled ? 'maintenance' : null]);

            if (! $station->hasFreshOcppConnection()) {
                return null;
            }

            return $this->queueSupervisionCommand(
                station: $station,
                requestedBy: $requestedBy,
                action: 'ChangeAvailability',
                payload: [
                    'connectorId' => 0,
                    'type' => $enabled ? 'Inoperative' : 'Operative',
                ],
            );
        });
    }

    public function queueRemoteStart(ChargingAttempt $attempt, string $idTag): OcppCommand
    {
        return DB::transaction(function () use ($attempt, $idTag): OcppCommand {
            $attempt = ChargingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $connector = $attempt->connector()->firstOrFail();
            $command = OcppCommand::query()->create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $attempt->organization_id,
                'user_id' => $attempt->user_id,
                'station_id' => $attempt->station_id,
                'connector_id' => $attempt->connector_id,
                'charging_attempt_id' => $attempt->id,
                'action' => 'RemoteStartTransaction',
                'status' => 'queued',
                'encrypted_payload' => [
                    'idTag' => $idTag,
                    'connectorId' => $connector->ocpp_connector_id,
                ],
                'idempotency_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'expires_at' => now()->addSeconds(max(30, (int) config('ocpp.gateway.command_ttl_seconds', 120))),
            ]);

            $attempt->update([
                'status' => 'command_queued',
                'command_queued_at' => now(),
            ]);
            event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));
            event(OcppCommandChanged::fromCommand($command));

            return $command;
        });
    }

    public function queueRemoteStop(ChargingSession $session, ?User $requestedBy = null, string $reason = 'user_requested'): OcppCommand
    {
        return DB::transaction(function () use ($session, $requestedBy, $reason): OcppCommand {
            $session = ChargingSession::query()
                ->with('ocppTransaction')
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($session->source !== 'ocpp' || $session->ocppTransaction === null) {
                throw ValidationException::withMessages(['session' => ['This session is not controlled through OCPP.']]);
            }
            if (! in_array($session->status, ['charging', 'stopping'], true)) {
                throw ValidationException::withMessages(['session' => ['This session is no longer active.']]);
            }

            $existing = OcppCommand::query()
                ->where('charging_session_id', $session->id)
                ->where('action', 'RemoteStopTransaction')
                ->whereIn('status', ['queued', 'sent', 'accepted', 'confirmed'])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $command = OcppCommand::query()->create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $session->organization_id,
                'user_id' => $requestedBy?->id,
                'station_id' => $session->station_id,
                'connector_id' => $session->connector_id,
                'charging_attempt_id' => $session->chargingAttempt?->id,
                'charging_session_id' => $session->id,
                'ocpp_transaction_id' => $session->ocpp_transaction_id,
                'action' => 'RemoteStopTransaction',
                'status' => 'queued',
                'encrypted_payload' => [
                    'transactionId' => $session->ocpp_transaction_id,
                    'reason' => $reason,
                ],
                'idempotency_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'expires_at' => now()->addSeconds(max(30, (int) config('ocpp.gateway.command_ttl_seconds', 120))),
            ]);

            $session->update(['status' => 'stopping', 'lifecycle_reason' => 'remote_stop_queued']);
            event(ChargingSessionChanged::fromSession($session->fresh()));
            event(OcppCommandChanged::fromCommand($command));

            return $command;
        });
    }

    public function claim(string $stationIdentity, string $connectionId): ?OcppCommand
    {
        return DB::transaction(function () use ($stationIdentity, $connectionId): ?OcppCommand {
            $station = Station::query()->where('ocpp_identity', $stationIdentity)->lockForUpdate()->firstOrFail();
            $command = OcppCommand::query()
                ->where('station_id', $station->id)
                ->where('status', 'queued')
                ->where('expires_at', '>', now())
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            if ($command === null) {
                return null;
            }

            $command->update([
                'status' => 'sent',
                'claimed_by' => $connectionId,
                'sent_at' => now(),
            ]);

            if ($command->chargingAttempt !== null) {
                $command->chargingAttempt->update(['status' => 'command_sent']);
                event(ChargingAttemptChanged::fromAttempt($command->chargingAttempt->fresh()));
            }

            event(OcppCommandChanged::fromCommand($command->fresh()));

            return $command->fresh();
        });
    }

    /** @param array<string, mixed> $result */
    public function complete(OcppCommand $command, string $connectionId, string $status, array $result = [], ?string $message = null): OcppCommand
    {
        $releaseAttempt = null;
        $command = DB::transaction(function () use ($command, $connectionId, $status, $result, $message, &$releaseAttempt): OcppCommand {
            $command = OcppCommand::query()->with(['chargingAttempt', 'chargingSession'])->lockForUpdate()->findOrFail($command->id);
            if ($command->claimed_by !== $connectionId) {
                throw ValidationException::withMessages(['connection_id' => ['This command was claimed by another connection.']]);
            }
            if ($command->status === 'confirmed') {
                return $command;
            }
            if (! in_array($command->status, ['sent', 'accepted'], true)) {
                return $command;
            }

            $command->update([
                'status' => $status,
                'result_payload' => $result,
                'responded_at' => now(),
                'failure_code' => in_array($status, self::TERMINAL_STATUSES, true) ? 'station_'.$status : null,
                'failure_message' => in_array($status, self::TERMINAL_STATUSES, true) ? $message : null,
            ]);

            if ($command->action === 'RemoteStartTransaction' && $command->chargingAttempt !== null) {
                if ($status === 'accepted') {
                    $command->chargingAttempt->update(['status' => 'awaiting_station']);
                } elseif (in_array($status, self::TERMINAL_STATUSES, true)) {
                    $command->chargingAttempt->update([
                        'status' => 'failed',
                        'failure_code' => 'remote_start_'.$status,
                        'failure_message' => $message ?? 'The station rejected the start command.',
                        'completed_at' => now(),
                    ]);
                    $releaseAttempt = $command->chargingAttempt->fresh();
                }
                event(ChargingAttemptChanged::fromAttempt($command->chargingAttempt->fresh()));
            }

            if ($command->action === 'RemoteStopTransaction'
                && in_array($status, self::TERMINAL_STATUSES, true)
                && $command->chargingSession !== null
                && $command->chargingSession->status === 'stopping') {
                $command->chargingSession->update([
                    'status' => 'charging',
                    'lifecycle_reason' => 'remote_stop_'.$status,
                ]);
                event(ChargingSessionChanged::fromSession($command->chargingSession->fresh()));
            }

            event(OcppCommandChanged::fromCommand($command->fresh()));

            return $command->fresh();
        });

        if ($releaseAttempt !== null) {
            $this->releaseAuthorization($releaseAttempt);
        }

        return $command;
    }

    public function confirmStart(OcppTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $command = OcppCommand::query()
                ->where('station_id', $transaction->station_id)
                ->where('connector_id', $transaction->connector_id)
                ->where('action', 'RemoteStartTransaction')
                ->whereIn('status', ['sent', 'accepted'])
                ->whereHas('chargingAttempt.idTag', fn ($query) => $query->where('token_hash', $transaction->id_tag_hash))
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($command === null) {
                return;
            }

            $command->update([
                'status' => 'confirmed',
                'ocpp_transaction_id' => $transaction->id,
                'confirmed_at' => now(),
            ]);
            event(OcppCommandChanged::fromCommand($command->fresh()));
        });
    }

    public function confirmStop(OcppTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $commands = OcppCommand::query()
                ->where('ocpp_transaction_id', $transaction->id)
                ->where('action', 'RemoteStopTransaction')
                ->whereIn('status', ['sent', 'accepted'])
                ->lockForUpdate()
                ->get();

            foreach ($commands as $command) {
                $command->update(['status' => 'confirmed', 'confirmed_at' => now()]);
                event(OcppCommandChanged::fromCommand($command->fresh()));
            }
        });
    }

    public function releaseAuthorization(ChargingAttempt $attempt): void
    {
        $this->payments->releaseAuthorized($attempt);
    }

    public function expireDue(): int
    {
        $expired = OcppCommand::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'sent'])
                    ->orWhere(function ($query): void {
                        $query->where('status', 'accepted')
                            ->whereIn('action', ['RemoteStartTransaction', 'RemoteStopTransaction']);
                    });
            })
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($expired as $command) {
            $attempt = DB::transaction(function () use ($command): ?ChargingAttempt {
                $command = OcppCommand::query()->with(['chargingAttempt', 'chargingSession'])->lockForUpdate()->find($command->id);
                if ($command === null || ! $this->commandCanExpire($command)) {
                    return null;
                }

                $command->update([
                    'status' => 'timed_out',
                    'responded_at' => now(),
                    'failure_code' => 'command_timed_out',
                    'failure_message' => 'The station did not confirm the command before it expired.',
                ]);

                if ($command->action === 'RemoteStartTransaction' && $command->chargingAttempt !== null) {
                    $command->chargingAttempt->update([
                        'status' => 'failed',
                        'failure_code' => 'remote_start_timed_out',
                        'failure_message' => $command->failure_message,
                        'completed_at' => now(),
                    ]);
                    event(ChargingAttemptChanged::fromAttempt($command->chargingAttempt->fresh()));

                    return $command->chargingAttempt->fresh();
                }

                if ($command->action === 'RemoteStopTransaction'
                    && $command->chargingSession !== null
                    && $command->chargingSession->status === 'stopping') {
                    $command->chargingSession->update([
                        'status' => 'charging',
                        'lifecycle_reason' => 'remote_stop_timed_out',
                    ]);
                    event(ChargingSessionChanged::fromSession($command->chargingSession->fresh()));
                }

                event(OcppCommandChanged::fromCommand($command->fresh()));

                return null;
            });

            if ($attempt !== null) {
                $this->releaseAuthorization($attempt);
            }
        }

        return $expired->count();
    }

    /** @param array<string, mixed> $payload */
    private function queueSupervisionCommand(
        Station $station,
        User $requestedBy,
        string $action,
        array $payload,
        ?Connector $connector = null,
    ): OcppCommand {
        $active = OcppCommand::query()
            ->where('station_id', $station->id)
            ->where('action', $action)
            ->whereIn('status', ['queued', 'sent'])
            ->when(
                $connector === null,
                fn ($query) => $query->whereNull('connector_id'),
                fn ($query) => $query->where('connector_id', $connector->id),
            )
            ->lockForUpdate()
            ->get();

        foreach ($active as $command) {
            if ($command->encrypted_payload === $payload) {
                return $command;
            }
        }

        if ($active->isNotEmpty()) {
            throw ValidationException::withMessages([
                'command' => ['A conflicting command is already pending for this target.'],
            ]);
        }

        $command = OcppCommand::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $station->organization_id,
            'user_id' => $requestedBy->id,
            'station_id' => $station->id,
            'connector_id' => $connector?->id,
            'action' => $action,
            'status' => 'queued',
            'encrypted_payload' => $payload,
            'idempotency_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'expires_at' => now()->addSeconds(max(30, (int) config('ocpp.gateway.supervision_command_ttl_seconds', 60))),
        ]);

        event(OcppCommandChanged::fromCommand($command));

        return $command;
    }

    private function ensureStationCanReceiveSupervisionCommands(Station $station): void
    {
        if (! $station->isOcppManaged()) {
            throw ValidationException::withMessages(['station' => ['This station is not managed through OCPP.']]);
        }
        if (! $station->hasFreshOcppConnection()) {
            throw ValidationException::withMessages(['station' => ['The station is offline and cannot receive this command.']]);
        }
    }

    private function commandCanExpire(OcppCommand $command): bool
    {
        return in_array($command->status, ['queued', 'sent'], true)
            || ($command->status === 'accepted'
                && in_array($command->action, ['RemoteStartTransaction', 'RemoteStopTransaction'], true));
    }
}
