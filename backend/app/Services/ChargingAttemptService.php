<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Events\ChargingAttemptChanged;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppCommandService;
use App\Services\Ocpp\VirtualOcppIdTagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChargingAttemptService
{
    public function __construct(
        private readonly PaymentGateway $payments,
        private readonly VirtualOcppIdTagService $idTags,
        private readonly OcppCommandService $commands,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function start(User $client, array $attributes): ChargingAttempt
    {
        $existing = ChargingAttempt::query()
            ->where('user_id', $client->id)
            ->where('payment_idempotency_key', $attributes['idempotency_key'])
            ->first();
        if ($existing !== null) {
            return $this->load($existing);
        }

        $attempt = DB::transaction(function () use ($client, $attributes): ChargingAttempt {
            $station = Station::query()->with('organization')->lockForUpdate()->findOrFail($attributes['station_id']);
            $connector = Connector::query()->lockForUpdate()->findOrFail($attributes['connector_id']);
            $this->assertCanStart($client, $station, $connector);

            return ChargingAttempt::query()->create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $station->organization_id,
                'user_id' => $client->id,
                'station_id' => $station->id,
                'connector_id' => $connector->id,
                'status' => 'payment_pending',
                'payment_provider' => $this->payments->name(),
                'payment_method' => $attributes['method'],
                'payment_status' => 'pending',
                'preauthorized_amount_millimes' => max(1, (int) config('payments.preauthorization_amount_millimes', 30000)),
                'currency' => 'TND',
                'payment_idempotency_key' => $attributes['idempotency_key'],
                'capture_idempotency_key' => (string) Str::uuid(),
                'simulation_outcome' => $attributes['simulation_outcome'] ?? 'success',
                'limit_energy_kwh' => $attributes['limit_energy_kwh'] ?? null,
                'limit_amount_millimes' => isset($attributes['limit_amount_tnd'])
                    ? (int) round(((float) $attributes['limit_amount_tnd']) * 1000)
                    : null,
                'limit_duration_minutes' => $attributes['limit_duration_minutes'] ?? null,
                'expires_at' => now()->addMinutes(max(5, (int) config('payments.authorization_ttl_minutes', 15))),
            ]);
        });

        $result = $this->payments->authorize(new PaymentCharge(
            paymentReference: 'ATT-'.$attempt->uuid,
            amountMillimes: $attempt->preauthorized_amount_millimes,
            currency: $attempt->currency,
            method: $attempt->payment_method,
            idempotencyKey: $attempt->payment_idempotency_key,
            simulationOutcome: $attempt->simulation_outcome,
        ));

        if (! $result->successful) {
            $attempt->update([
                'status' => 'failed',
                'payment_status' => 'failed',
                'failure_code' => 'payment_declined',
                'failure_message' => $result->failureReason,
                'completed_at' => now(),
            ]);
            event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));

            return $this->load($attempt);
        }

        $idTag = $this->idTags->forClient($client);
        $attempt->update([
            'ocpp_id_tag_id' => $idTag->id,
            'status' => 'authorized',
            'payment_status' => 'authorized',
            'provider_authorization_id' => $result->transactionId,
            'authorized_at' => now(),
        ]);
        event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));
        $this->commands->queueRemoteStart($attempt->fresh(), $idTag->token_ciphertext);

        return $this->load($attempt->fresh());
    }

    public function load(ChargingAttempt $attempt): ChargingAttempt
    {
        return $attempt->load(['organization', 'station', 'connector', 'chargingSession', 'commands' => fn ($query) => $query->latest('id')]);
    }

    private function assertCanStart(User $client, Station $station, Connector $connector): void
    {
        if (! $station->isOcppManaged()) {
            throw ValidationException::withMessages(['station_id' => ['This station does not support remote OCPP charging.']]);
        }
        if ($station->organization?->status !== 'active'
            || ! in_array($station->status, ['available', 'charging'], true)
            || $station->availability_override !== null
            || ! $station->hasFreshOcppConnection()) {
            throw ValidationException::withMessages(['station_id' => ['The station is not currently available for remote charging.']]);
        }
        if ($connector->station_id !== $station->id
            || ! in_array($connector->status, ['available', 'charging'], true)
            || ! in_array($connector->ocpp_status, ['Available', 'Preparing'], true)) {
            throw ValidationException::withMessages(['connector_id' => ['The connector is no longer ready for remote charging.']]);
        }

        $attemptExists = ChargingAttempt::query()
            ->whereIn('status', ['payment_pending', 'authorized', 'command_queued', 'command_sent', 'awaiting_station', 'charging'])
            ->where(fn ($query) => $query->where('user_id', $client->id)->orWhere('connector_id', $connector->id))
            ->exists();
        $sessionExists = ChargingSession::query()
            ->whereIn('status', ['pending', 'charging', 'stopping'])
            ->where(fn ($query) => $query->where('client_id', $client->id)->orWhere('connector_id', $connector->id))
            ->exists();

        if ($attemptExists || $sessionExists) {
            throw ValidationException::withMessages(['session' => ['The client or connector already has an active charging workflow.']]);
        }
    }
}
