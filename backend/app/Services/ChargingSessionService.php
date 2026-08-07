<?php

namespace App\Services;

use App\Events\ChargingAttemptChanged;
use App\Events\ChargingSessionChanged;
use App\Exceptions\ActiveChargingSessionConflictException;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppTransaction;
use App\Models\PlanSubscription;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChargingSessionService
{
    private const ACTIVE_CLIENT_INDEX = 'charging_sessions_one_active_per_client_unique';

    private const ACTIVE_STATUSES = ['pending', 'charging', 'stopping'];

    public function __construct(private readonly TariffResolver $tariffResolver) {}

    /** @param array{station_id:int, connector_id:int} $attributes */
    public function start(User $client, array $attributes): ChargingSession
    {
        return DB::transaction(function () use ($client, $attributes): ChargingSession {
            $station = Station::query()->lockForUpdate()->findOrFail($attributes['station_id']);
            $connector = Connector::query()->lockForUpdate()->findOrFail($attributes['connector_id']);

            if ($connector->station_id !== $station->id) {
                throw ValidationException::withMessages(['connector_id' => ['The connector does not belong to the selected station.']]);
            }
            if (! $station->organization()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['station_id' => ['The station organization is not active.']]);
            }

            if ($station->isOcppManaged()) {
                throw ValidationException::withMessages([
                    'station_id' => ['This station requires an OCPP-confirmed start. Use its authorized idTag until remote start is enabled.'],
                ]);
            }

            if (! in_array($station->status, ['available', 'charging'], true) || $connector->status !== 'available') {
                throw ValidationException::withMessages(['connector_id' => ['The selected connector is no longer available.']]);
            }

            $this->assertNoActiveSession($client, $connector);

            $tariff = $this->tariffResolver->resolve($station, $connector);
            $subscription = PlanSubscription::query()
                ->where('user_id', $client->id)
                ->where('organization_id', $station->organization_id)
                ->current()
                ->whereHas('chargingPlan', fn ($query) => $query->where('status', 'active'))
                ->with('chargingPlan')
                ->latest('id')
                ->first();

            $session = $this->createSession([
                'organization_id' => $station->organization_id,
                'client_id' => $client->id,
                'station_id' => $station->id,
                'connector_id' => $connector->id,
                'tariff_id' => $tariff->id,
                'charging_plan_id' => $subscription?->charging_plan_id,
                'reference' => 'SES-'.Str::upper(Str::random(10)),
                'source' => 'simulated',
                'client_name' => $client->name,
                'station_name' => $station->name,
                'connector_external_id' => $connector->external_id,
                'status' => 'charging',
                'payment_status' => 'unpaid',
                'tariff_name' => $tariff->name,
                'charging_plan_name' => $subscription?->chargingPlan?->name,
                'discount_basis_points' => $subscription?->discount_basis_points ?? 0,
                'started_at' => now(),
                'meter_start_kwh' => 1000 + ($connector->id * 10),
                'price_per_kwh_millimes' => $tariff->pricePerKwhMillimes,
                'session_fee_millimes' => $tariff->sessionFeeMillimes,
                'idle_fee_per_minute_millimes' => $tariff->idleFeePerMinuteMillimes,
                'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
                'currency' => $tariff->currency,
            ]);

            if (! $station->isOcppManaged()) {
                $connector->update(['status' => 'charging', 'last_status_at' => now(), 'error_code' => null]);
                if ($station->status === 'available') {
                    $station->update(['status' => 'charging']);
                }
            }

            return $session->load(['organization', 'station', 'connector', 'client', 'payment']);
        });
    }

    public function stop(ChargingSession $session): ChargingSession
    {
        return DB::transaction(function () use ($session): ChargingSession {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->source === 'ocpp') {
                throw ValidationException::withMessages([
                    'session' => ['This OCPP session must be stopped through a confirmed remote command or by the station.'],
                ]);
            }
            if ($session->status !== 'charging') {
                throw ValidationException::withMessages(['session' => ['Only an active charging session can be stopped.']]);
            }

            $station = Station::query()->lockForUpdate()->find($session->station_id);
            $connector = $session->connector_id
                ? Connector::query()->lockForUpdate()->find($session->connector_id)
                : null;
            $endedAt = now();
            $durationSeconds = max(60, (int) round($session->started_at->diffInSeconds($endedAt)));
            $powerKw = min((float) ($connector?->max_power_kw ?? 60), 60);
            $energyKwh = max(0.5, round(($durationSeconds / 3600) * $powerKw, 3));
            $energyGross = (int) round($energyKwh * $session->price_per_kwh_millimes);
            $discount = (int) round($energyGross * $session->discount_basis_points / 10000);
            $energyCost = $energyGross - $discount;
            $totalMillimes = max(
                $energyCost + $session->session_fee_millimes,
                $session->minimum_charge_millimes,
            );

            $session->update([
                'status' => 'completed',
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
                'meter_stop_kwh' => $session->meter_start_kwh + $energyKwh,
                'energy_kwh' => $energyKwh,
                'discount_millimes' => $discount,
                'total_millimes' => $totalMillimes,
            ]);

            if ($connector && ! $station?->isOcppManaged()) {
                $connector->update(['status' => 'available', 'last_status_at' => now()]);
            }

            if ($station && ! $station->isOcppManaged()) {
                $hasOtherActiveSessions = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('status', 'charging')
                    ->whereKeyNot($session->id)
                    ->exists();
                $station->update(['status' => $hasOtherActiveSessions ? 'charging' : 'available']);
            }

            return $session->fresh()->load(['organization', 'station', 'connector', 'client', 'payment']);
        });
    }

    public function startFromOcpp(
        User $client,
        Station $station,
        Connector $connector,
        OcppTransaction $transaction,
    ): ChargingSession {
        return DB::transaction(function () use ($client, $station, $connector, $transaction): ChargingSession {
            $this->assertNoActiveSession($client, $connector);

            $tariff = $this->tariffResolver->resolve($station, $connector);
            $subscription = $this->currentSubscription($client, $station);
            $meterStartKwh = $transaction->meter_start_wh / 1000;
            $attempt = ChargingAttempt::query()
                ->where('user_id', $client->id)
                ->where('connector_id', $connector->id)
                ->where('ocpp_id_tag_id', $transaction->ocpp_id_tag_id)
                ->whereIn('status', ['authorized', 'command_queued', 'command_sent', 'awaiting_station'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $session = $this->createSession([
                'organization_id' => $station->organization_id,
                'client_id' => $client->id,
                'station_id' => $station->id,
                'connector_id' => $connector->id,
                'tariff_id' => $tariff->id,
                'charging_plan_id' => $subscription?->charging_plan_id,
                'ocpp_transaction_id' => $transaction->id,
                'reference' => 'SES-'.Str::upper(Str::random(10)),
                'source' => 'ocpp',
                'client_name' => $client->name,
                'station_name' => $station->name,
                'connector_external_id' => $connector->external_id,
                'status' => 'charging',
                'lifecycle_reason' => 'start_transaction_confirmed',
                'payment_status' => $attempt?->payment_status === 'authorized' ? 'authorized' : 'unpaid',
                'tariff_name' => $tariff->name,
                'charging_plan_name' => $subscription?->chargingPlan?->name,
                'discount_basis_points' => $subscription?->discount_basis_points ?? 0,
                'started_at' => $transaction->started_at,
                'meter_start_kwh' => $meterStartKwh,
                'meter_stop_kwh' => null,
                'energy_kwh' => 0,
                'limit_energy_kwh' => $attempt?->limit_energy_kwh,
                'limit_amount_millimes' => $attempt?->limit_amount_millimes ?? $attempt?->preauthorized_amount_millimes,
                'limit_duration_minutes' => $attempt?->limit_duration_minutes,
                'price_per_kwh_millimes' => $tariff->pricePerKwhMillimes,
                'session_fee_millimes' => $tariff->sessionFeeMillimes,
                'idle_fee_per_minute_millimes' => $tariff->idleFeePerMinuteMillimes,
                'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
                'currency' => $tariff->currency,
            ])->load(['organization', 'station', 'connector', 'client', 'payment']);

            if ($attempt !== null) {
                $attempt->update([
                    'charging_session_id' => $session->id,
                    'status' => 'charging',
                    'started_at' => $transaction->started_at,
                ]);
                event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));
            }

            event(ChargingSessionChanged::fromSession($session));

            return $session;
        });
    }

    public function updateFromOcppMeter(
        OcppTransaction $transaction,
        int $meterWh,
        CarbonInterface $sampledAt,
        ?float $powerKw = null,
        ?float $stateOfChargePercent = null,
    ): ?ChargingSession {
        return DB::transaction(function () use ($transaction, $meterWh, $sampledAt, $powerKw, $stateOfChargePercent): ?ChargingSession {
            $session = ChargingSession::query()
                ->where('ocpp_transaction_id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return $session;
            }

            if ($session->status === 'interrupted'
                && $session->lifecycle_reason === 'ocpp_connection_lost_awaiting_reconciliation') {
                $session->update([
                    'status' => 'charging',
                    'lifecycle_reason' => 'ocpp_telemetry_recovered',
                    'ended_at' => null,
                ]);
            }

            if (! in_array($session->status, ['charging', 'stopping'], true)) {
                return $session;
            }

            $energyKwh = max(0, ($meterWh - $transaction->meter_start_wh) / 1000);
            $pricing = $this->calculatePricing($session, $energyKwh);
            $session->update([
                'duration_seconds' => max(0, (int) round($session->started_at->diffInSeconds($sampledAt))),
                'energy_kwh' => round($energyKwh, 3),
                'discount_millimes' => $pricing['discount_millimes'],
                'total_millimes' => $pricing['total_millimes'],
                'last_meter_value_at' => $sampledAt,
                ...($powerKw !== null ? ['current_power_kw' => round(max(0, $powerKw), 3)] : []),
                ...($stateOfChargePercent !== null ? ['state_of_charge_percent' => min(100, max(0, $stateOfChargePercent))] : []),
            ]);

            $session = $session->fresh()->load(['organization', 'station', 'connector', 'client', 'payment']);
            event(ChargingSessionChanged::fromSession($session));

            return $session;
        });
    }

    public function finishFromOcpp(
        OcppTransaction $transaction,
        string $terminalStatus,
        string $reason,
    ): ChargingSession {
        return DB::transaction(function () use ($transaction, $terminalStatus, $reason): ChargingSession {
            $session = ChargingSession::query()
                ->where('ocpp_transaction_id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $awaitingReconciliation = $session->status === 'interrupted'
                && $session->lifecycle_reason === 'ocpp_connection_lost_awaiting_reconciliation';

            if (! $awaitingReconciliation
                && in_array($session->status, ['completed', 'interrupted', 'failed', 'cancelled'], true)) {
                return $session->load(['organization', 'station', 'connector', 'client', 'payment']);
            }

            $meterStopWh = max($transaction->meter_start_wh, (int) $transaction->meter_stop_wh);
            $energyKwh = max(0, ($meterStopWh - $transaction->meter_start_wh) / 1000);
            $pricing = $this->calculatePricing($session, $energyKwh);
            $endedAt = $transaction->stopped_at ?? now();
            $session->update([
                'status' => $terminalStatus,
                'lifecycle_reason' => 'stop_transaction_'.$this->normalizeOcppReason($reason),
                'ended_at' => $endedAt,
                'duration_seconds' => max(0, (int) round($session->started_at->diffInSeconds($endedAt))),
                'meter_stop_kwh' => $meterStopWh / 1000,
                'last_meter_value_at' => $transaction->last_meter_value_at,
                'energy_kwh' => round($energyKwh, 3),
                'discount_millimes' => $pricing['discount_millimes'],
                'total_millimes' => $pricing['total_millimes'],
                'current_power_kw' => 0,
            ]);

            $session = $session->fresh()->load(['organization', 'station', 'connector', 'client', 'payment']);
            event(ChargingSessionChanged::fromSession($session));

            return $session;
        });
    }

    public function interruptOcppForConnectivity(Station $station, string $reason): void
    {
        OcppTransaction::query()
            ->where('station_id', $station->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get()
            ->each(function (OcppTransaction $transaction) use ($reason): void {
                $transaction->update([
                    'status' => 'awaiting_reconciliation',
                    'stop_reason' => $reason,
                ]);

                $session = ChargingSession::query()
                    ->where('ocpp_transaction_id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if ($session === null || ! in_array($session->status, ['charging', 'stopping'], true)) {
                    return;
                }

                $endedAt = now()->utc();
                $session->update([
                    'status' => 'interrupted',
                    'lifecycle_reason' => 'ocpp_connection_lost_awaiting_reconciliation',
                    'ended_at' => $endedAt,
                    'duration_seconds' => max(0, (int) round($session->started_at->diffInSeconds($endedAt))),
                ]);
                event(ChargingSessionChanged::fromSession($session->fresh()));
            });
    }

    public function reconcileInterruptedFromLastMeter(ChargingSession $session): ?ChargingSession
    {
        return DB::transaction(function () use ($session): ?ChargingSession {
            $session = ChargingSession::query()->lockForUpdate()->find($session->id);
            if ($session === null || $session->source !== 'ocpp' || $session->ocpp_transaction_id === null) {
                return null;
            }
            if ($session->status === 'completed' && $session->meter_stop_kwh !== null) {
                return $session->load(['organization', 'station', 'connector', 'client', 'payment']);
            }
            if ($session->status !== 'interrupted') {
                return null;
            }

            $transaction = OcppTransaction::query()->lockForUpdate()->find($session->ocpp_transaction_id);
            if ($transaction === null
                || $transaction->last_meter_wh === null
                || $transaction->last_meter_value_at === null
                || $transaction->last_meter_wh < $transaction->meter_start_wh) {
                return null;
            }

            $endedAt = $session->ended_at ?? now()->utc();
            $meterStopWh = $transaction->last_meter_wh;
            $energyKwh = max(0, ($meterStopWh - $transaction->meter_start_wh) / 1000);
            $pricing = $this->calculatePricing($session, $energyKwh);

            $transaction->update([
                'status' => 'reconciled',
                'meter_stop_wh' => $meterStopWh,
                'stopped_at' => $endedAt,
                'stop_reason' => 'ConnectivityTimeout',
            ]);
            $session->update([
                'lifecycle_reason' => 'ocpp_connection_lost_reconciled_from_last_meter',
                'meter_stop_kwh' => $meterStopWh / 1000,
                'last_meter_value_at' => $transaction->last_meter_value_at,
                'energy_kwh' => round($energyKwh, 3),
                'discount_millimes' => $pricing['discount_millimes'],
                'total_millimes' => $pricing['total_millimes'],
                'current_power_kw' => 0,
            ]);

            $session = $session->fresh()->load(['organization', 'station', 'connector', 'client', 'payment']);
            event(ChargingSessionChanged::fromSession($session));

            return $session;
        });
    }

    private function currentSubscription(User $client, Station $station): ?PlanSubscription
    {
        return PlanSubscription::query()
            ->where('user_id', $client->id)
            ->where('organization_id', $station->organization_id)
            ->current()
            ->whereHas('chargingPlan', fn ($query) => $query->where('status', 'active'))
            ->with('chargingPlan')
            ->latest('id')
            ->first();
    }

    private function assertNoActiveSession(User $client, Connector $connector): void
    {
        if (ChargingSession::query()
            ->where('client_id', $client->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists()) {
            throw new ActiveChargingSessionConflictException;
        }

        if (ChargingSession::query()
            ->where('connector_id', $connector->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists()) {
            throw ValidationException::withMessages([
                'connector_id' => ['The selected connector already has an active session.'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function createSession(array $attributes): ChargingSession
    {
        try {
            return ChargingSession::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isActiveClientConstraintViolation($exception)) {
                throw $exception;
            }

            throw new ActiveChargingSessionConflictException($exception);
        }
    }

    private function isActiveClientConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, self::ACTIVE_CLIENT_INDEX)
            || (DB::getDriverName() === 'sqlite'
                && str_contains($message, 'charging_sessions.client_id'));
    }

    /** @return array{discount_millimes: int, total_millimes: int} */
    private function calculatePricing(ChargingSession $session, float $energyKwh): array
    {
        $energyGross = (int) round($energyKwh * $session->price_per_kwh_millimes);
        $discount = (int) round($energyGross * $session->discount_basis_points / 10000);

        return [
            'discount_millimes' => $discount,
            'total_millimes' => max(
                $energyGross - $discount + $session->session_fee_millimes,
                $session->minimum_charge_millimes,
            ),
        ];
    }

    private function normalizeOcppReason(string $reason): string
    {
        return match ($reason) {
            'EVDisconnected' => 'ev_disconnected',
            'EmergencyStop' => 'emergency_stop',
            'HardReset' => 'hard_reset',
            'PowerLoss' => 'power_loss',
            'SoftReset' => 'soft_reset',
            'UnlockCommand' => 'unlock_command',
            'DeAuthorized' => 'deauthorized',
            default => Str::snake($reason),
        };
    }
}
