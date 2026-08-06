<?php

namespace App\Services\Dashboard;

use App\Models\AvailabilityTransition;
use App\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AvailabilityMetrics
{
    private const OPERATIONAL_STATUSES = ['available', 'charging'];

    public function __construct(private readonly AvailabilityMetricsCache $cache) {}

    /**
     * @param  Collection<int, Station>  $stations
     * @return array{
     *     summary: array{availability_percent: float, monitored_hours: float, unavailable_hours: float},
     *     daily: list<array{date:string, availability_percent:float}>
     * }
     */
    public function report(Collection $stations, Carbon $periodStart, Carbon $periodEnd): array
    {
        if ($stations->isEmpty()) {
            return [
                'summary' => ['availability_percent' => 0.0, 'monitored_hours' => 0.0, 'unavailable_hours' => 0.0],
                'daily' => [],
            ];
        }

        return $this->cache->remember($stations, $periodStart, $periodEnd, function () use ($stations, $periodStart, $periodEnd): array {
            $transitions = $this->transitions($stations, $periodStart, $periodEnd);

            return [
                'summary' => $this->calculateWithTransitions($stations, $periodStart, $periodEnd, $transitions),
                'daily' => $this->dailyWithTransitions($stations, $periodStart, $periodEnd, $transitions),
            ];
        });
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return array{availability_percent: float, monitored_hours: float, unavailable_hours: float}
     */
    public function calculate(Collection $stations, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->report($stations, $periodStart, $periodEnd)['summary'];
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return list<array{date:string, availability_percent:float}>
     */
    public function daily(Collection $stations, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->report($stations, $periodStart, $periodEnd)['daily'];
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @param  Collection<int, Collection<int, AvailabilityTransition>>  $transitions
     * @return list<array{date:string, availability_percent:float}>
     */
    private function dailyWithTransitions(Collection $stations, Carbon $periodStart, Carbon $periodEnd, Collection $transitions): array
    {
        $points = [];
        $cursor = $periodStart->copy()->startOfDay();

        while ($cursor->lte($periodEnd)) {
            $dayStart = $cursor->greaterThan($periodStart) ? $cursor->copy() : $periodStart->copy();
            $dayEnd = $cursor->copy()->endOfDay();
            if ($dayEnd->greaterThan($periodEnd)) {
                $dayEnd = $periodEnd->copy();
            }

            $metrics = $this->calculateWithTransitions($stations, $dayStart, $dayEnd, $transitions);
            $points[] = [
                'date' => $cursor->toDateString(),
                'availability_percent' => $metrics['availability_percent'],
            ];
            $cursor->addDay();
        }

        return $points;
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return Collection<int, Collection<int, AvailabilityTransition>>
     */
    private function transitions(Collection $stations, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $stationIds = $stations->pluck('id');
        $rankedBaselines = AvailabilityTransition::query()
            ->select(['id', 'station_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY station_id ORDER BY occurred_at DESC, id DESC) AS transition_rank')
            ->whereNull('connector_id')
            ->whereIn('station_id', $stationIds)
            ->where('occurred_at', '<=', $periodStart);
        $baselineIds = DB::query()
            ->fromSub($rankedBaselines, 'ranked_availability_transitions')
            ->where('transition_rank', 1)
            ->pluck('id');

        return AvailabilityTransition::query()
            ->select(['id', 'station_id', 'from_status', 'to_status', 'occurred_at'])
            ->whereNull('connector_id')
            ->whereIn('station_id', $stationIds)
            ->where(function ($query) use ($baselineIds, $periodStart, $periodEnd): void {
                $query->where(function ($query) use ($periodStart, $periodEnd): void {
                    $query->where('occurred_at', '>', $periodStart)
                        ->where('occurred_at', '<=', $periodEnd);
                });

                if ($baselineIds->isNotEmpty()) {
                    $query->orWhereIn('id', $baselineIds);
                }
            })
            ->orderBy('station_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('station_id');
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @param  Collection<int, Collection<int, AvailabilityTransition>>  $transitions
     * @return array{availability_percent: float, monitored_hours: float, unavailable_hours: float}
     */
    private function calculateWithTransitions(Collection $stations, Carbon $periodStart, Carbon $periodEnd, Collection $transitions): array
    {
        $monitoredSeconds = 0;
        $operationalSeconds = 0;

        foreach ($stations as $station) {
            $monitoringStart = $station->availability_monitoring_started_at ?? $station->created_at ?? $periodStart;
            $start = $monitoringStart->greaterThan($periodStart) ? $monitoringStart->copy() : $periodStart->copy();
            if ($start->greaterThanOrEqualTo($periodEnd)) {
                continue;
            }

            $stationTransitions = $transitions->get($station->id, collect());
            $beforeStart = $stationTransitions->filter(fn (AvailabilityTransition $transition) => $transition->occurred_at->lessThanOrEqualTo($start))->last();
            $afterStart = $stationTransitions->filter(fn (AvailabilityTransition $transition) => $transition->occurred_at->greaterThan($start));
            $state = $beforeStart?->to_status ?? $afterStart->first()?->from_status ?? $station->status;
            $cursor = $start;

            foreach ($afterStart as $transition) {
                if ($transition->occurred_at->greaterThan($periodEnd)) {
                    break;
                }
                $seconds = max(0, $cursor->diffInSeconds($transition->occurred_at));
                $monitoredSeconds += $seconds;
                if (in_array($state, self::OPERATIONAL_STATUSES, true)) {
                    $operationalSeconds += $seconds;
                }
                $cursor = $transition->occurred_at;
                $state = $transition->to_status;
            }

            $seconds = max(0, $cursor->diffInSeconds($periodEnd));
            $monitoredSeconds += $seconds;
            if (in_array($state, self::OPERATIONAL_STATUSES, true)) {
                $operationalSeconds += $seconds;
            }
        }

        $unavailableSeconds = max(0, $monitoredSeconds - $operationalSeconds);

        return [
            'availability_percent' => $monitoredSeconds > 0 ? round(($operationalSeconds / $monitoredSeconds) * 100, 1) : 0.0,
            'monitored_hours' => round($monitoredSeconds / 3600, 1),
            'unavailable_hours' => round($unavailableSeconds / 3600, 1),
        ];
    }
}
