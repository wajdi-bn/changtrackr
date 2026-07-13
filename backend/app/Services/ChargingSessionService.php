<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChargingSessionService
{
    /** @param array{station_id:int, connector_id:int} $attributes */
    public function start(User $client, array $attributes): ChargingSession
    {
        return DB::transaction(function () use ($client, $attributes): ChargingSession {
            $station = Station::query()->lockForUpdate()->findOrFail($attributes['station_id']);
            $connector = Connector::query()->lockForUpdate()->findOrFail($attributes['connector_id']);

            if ($station->organization_id !== $client->organization_id || $connector->station_id !== $station->id) {
                throw ValidationException::withMessages(['connector_id' => ['The connector is not available in your organization.']]);
            }

            if (! in_array($station->status, ['available', 'charging'], true) || $connector->status !== 'available') {
                throw ValidationException::withMessages(['connector_id' => ['The selected connector is no longer available.']]);
            }

            $hasActiveSession = ChargingSession::query()
                ->where('status', 'charging')
                ->where(fn ($query) => $query->where('client_id', $client->id)->orWhere('connector_id', $connector->id))
                ->exists();

            if ($hasActiveSession) {
                throw ValidationException::withMessages(['session' => ['The client or connector already has an active session.']]);
            }

            $session = ChargingSession::query()->create([
                'organization_id' => $station->organization_id,
                'client_id' => $client->id,
                'station_id' => $station->id,
                'connector_id' => $connector->id,
                'reference' => 'SES-'.Str::upper(Str::random(10)),
                'client_name' => $client->name,
                'station_name' => $station->name,
                'connector_external_id' => $connector->external_id,
                'status' => 'charging',
                'payment_status' => 'unpaid',
                'started_at' => now(),
                'meter_start_kwh' => 1000 + ($connector->id * 10),
                'price_per_kwh_millimes' => config('charging.price_per_kwh_millimes'),
                'session_fee_millimes' => config('charging.session_fee_millimes'),
                'currency' => 'TND',
            ]);

            $connector->update(['status' => 'charging', 'last_status_at' => now(), 'error_code' => null]);
            if ($station->status === 'available') {
                $station->update(['status' => 'charging']);
            }

            return $session->load(['station', 'connector', 'client', 'payment']);
        });
    }

    public function stop(ChargingSession $session): ChargingSession
    {
        return DB::transaction(function () use ($session): ChargingSession {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'charging') {
                throw ValidationException::withMessages(['session' => ['Only an active charging session can be stopped.']]);
            }

            $connector = $session->connector_id
                ? Connector::query()->lockForUpdate()->find($session->connector_id)
                : null;
            $endedAt = now();
            $durationSeconds = max(60, (int) round($session->started_at->diffInSeconds($endedAt)));
            $powerKw = min((float) ($connector?->max_power_kw ?? 60), 60);
            $energyKwh = max(0.5, round(($durationSeconds / 3600) * $powerKw, 3));
            $energyCost = (int) round($energyKwh * $session->price_per_kwh_millimes);
            $totalMillimes = $energyCost + $session->session_fee_millimes;

            $session->update([
                'status' => 'completed',
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
                'meter_stop_kwh' => $session->meter_start_kwh + $energyKwh,
                'energy_kwh' => $energyKwh,
                'total_millimes' => $totalMillimes,
            ]);

            if ($connector) {
                $connector->update(['status' => 'available', 'last_status_at' => now()]);
            }

            $station = Station::query()->lockForUpdate()->find($session->station_id);
            if ($station) {
                $hasOtherActiveSessions = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('status', 'charging')
                    ->whereKeyNot($session->id)
                    ->exists();
                $station->update([
                    'status' => $hasOtherActiveSessions ? 'charging' : 'available',
                    'energy_today_kwh' => $station->energy_today_kwh + $energyKwh,
                    'sessions_today' => $station->sessions_today + 1,
                ]);
            }

            return $session->fresh()->load(['station', 'connector', 'client', 'payment']);
        });
    }
}
