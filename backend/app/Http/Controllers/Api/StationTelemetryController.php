<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OcppMeterSample;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StationTelemetryController extends Controller
{
    public function __invoke(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $filters = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([1, 7, 30])],
        ]);
        $days = (int) ($filters['days'] ?? 7);
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();
        $canViewFinancials = $request->user()?->hasAnyRole(['super_admin', 'admin', 'operator']) ?? false;

        $sessions = $station->chargingSessions()
            ->whereBetween('started_at', [$from, $to]);
        $dailyValues = (clone $sessions)
            ->selectRaw(
                'DATE(started_at) as day, COUNT(*) as sessions, COALESCE(SUM(energy_kwh), 0) as energy_kwh, COALESCE(SUM(CASE WHEN payment_status = ? THEN total_millimes ELSE 0 END), 0) as revenue_millimes',
                ['paid'],
            )
            ->groupByRaw('DATE(started_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $daily = collect(CarbonPeriod::create($from, $to))
            ->map(function ($date) use ($dailyValues, $canViewFinancials): array {
                $day = $date->format('Y-m-d');
                $value = $dailyValues->get($day);

                return [
                    'date' => $day,
                    'sessions' => (int) ($value?->sessions ?? 0),
                    'energy_kwh' => round((float) ($value?->energy_kwh ?? 0), 3),
                    'revenue_millimes' => $canViewFinancials
                        ? (int) ($value?->revenue_millimes ?? 0)
                        : null,
                ];
            })
            ->values();

        $power = $this->powerSeries($station, $from, $to);
        $lastPowerPoint = $power->last();

        return response()->json(['data' => [
            'window' => [
                'days' => $days,
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
                'timezone' => (string) config('app.timezone'),
            ],
            'summary' => [
                'sessions' => $daily->sum('sessions'),
                'energy_kwh' => round((float) $daily->sum('energy_kwh'), 3),
                'revenue_millimes' => $canViewFinancials ? (int) $daily->sum('revenue_millimes') : null,
                'power_points' => $power->count(),
                'latest_power_kw' => $lastPowerPoint['power_kw'] ?? null,
                'last_sample_at' => $lastPowerPoint['sampled_at'] ?? null,
            ],
            'daily' => $daily,
            'power' => $power,
            'sources' => [
                'daily' => 'charging_sessions',
                'power' => 'ocpp_meter_values',
                'financials_visible' => $canViewFinancials,
            ],
        ]]);
    }

    /** @return Collection<int, array{sampled_at: string, power_kw: float}> */
    private function powerSeries(Station $station, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $samples = OcppMeterSample::query()
            ->where('station_id', $station->id)
            ->where('measurand', 'Power.Active.Import')
            ->whereBetween('sampled_at', [$from, $to])
            ->orderByDesc('sampled_at')
            ->limit(720)
            ->get()
            ->sortBy('sampled_at');

        return $samples
            ->groupBy(fn (OcppMeterSample $sample): string => $sample->sampled_at->utc()->toISOString())
            ->map(function (Collection $atTimestamp, string $sampledAt): array {
                $aggregate = $atTimestamp->first(
                    fn (OcppMeterSample $sample): bool => $sample->phase === null || $sample->phase === '',
                );
                $powerKw = $aggregate instanceof OcppMeterSample
                    ? $this->toKilowatts($aggregate)
                    : $atTimestamp->sum(fn (OcppMeterSample $sample): float => $this->toKilowatts($sample));

                return [
                    'sampled_at' => $sampledAt,
                    'power_kw' => round($powerKw, 3),
                ];
            })
            ->values()
            ->take(-180)
            ->values();
    }

    private function toKilowatts(OcppMeterSample $sample): float
    {
        return match (strtolower($sample->unit)) {
            'mw' => $sample->value * 1000,
            'kw' => $sample->value,
            default => $sample->value / 1000,
        };
    }
}
