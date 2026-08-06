<?php

namespace Tests\Feature;

use App\Models\AvailabilityTransition;
use App\Models\Organization;
use App\Models\Station;
use App\Services\Dashboard\AvailabilityMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailabilityMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_loads_one_historical_baseline_and_only_period_transitions(): void
    {
        [$periodStart, $periodEnd] = $this->period();
        $organization = $this->organization('bounded-history');
        $station = $this->station($organization, 'BOUNDED-001', 'available', $periodStart->copy()->subYear());

        $this->transition($station, 'available', 'offline', $periodStart->copy()->subDays(100));
        $this->transition($station, 'offline', 'available', $periodStart->copy()->subDays(90));
        $this->transition($station, 'available', 'offline', $periodStart->copy()->addDays(2));
        $this->transition($station, 'offline', 'available', $periodStart->copy()->addDays(4));

        DB::enableQueryLog();
        $report = app(AvailabilityMetrics::class)->report(collect([$station]), $periodStart, $periodEnd);
        $transitionQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'availability_transitions'));

        $this->assertSame(71.4, $report['summary']['availability_percent']);
        $this->assertSame(48.0, $report['summary']['unavailable_hours']);
        $this->assertSame([100.0, 100.0, 0.0, 0.0, 100.0, 100.0, 100.0], collect($report['daily'])->pluck('availability_percent')->all());
        $this->assertCount(2, $transitionQueries);
        $this->assertTrue($transitionQueries->contains(fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER() OVER')));
        $this->assertTrue($transitionQueries->contains(fn (array $query): bool => str_contains($query['query'], '"occurred_at" > ?')));
    }

    public function test_report_is_cached_and_a_new_transition_invalidates_it(): void
    {
        [$periodStart, $periodEnd] = $this->period();
        $organization = $this->organization('cache-invalidation');
        $station = $this->station($organization, 'CACHE-001', 'available', $periodStart->copy()->subMonth());
        $metrics = app(AvailabilityMetrics::class);

        $this->assertSame(100.0, $metrics->report(collect([$station]), $periodStart, $periodEnd)['summary']['availability_percent']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $metrics->report(collect([$station]), $periodStart, $periodEnd);
        $this->assertSame(0, $this->availabilityQueryCount());

        $this->transition($station, 'available', 'offline', $periodStart->copy()->addDay());
        DB::flushQueryLog();
        $refreshed = $metrics->report(collect([$station]), $periodStart, $periodEnd);

        $this->assertSame(14.3, $refreshed['summary']['availability_percent']);
        $this->assertSame(2, $this->availabilityQueryCount());
    }

    public function test_cache_is_isolated_between_organizations(): void
    {
        [$periodStart, $periodEnd] = $this->period();
        $firstOrganization = $this->organization('cache-first');
        $secondOrganization = $this->organization('cache-second');
        $firstStation = $this->station($firstOrganization, 'CACHE-FIRST', 'available', $periodStart->copy()->subMonth());
        $secondStation = $this->station($secondOrganization, 'CACHE-SECOND', 'offline', $periodStart->copy()->subMonth());
        $metrics = app(AvailabilityMetrics::class);

        $first = $metrics->report(collect([$firstStation]), $periodStart, $periodEnd);
        $second = $metrics->report(collect([$secondStation]), $periodStart, $periodEnd);

        $this->assertSame(100.0, $first['summary']['availability_percent']);
        $this->assertSame(0.0, $second['summary']['availability_percent']);

        $this->transition($firstStation, 'available', 'offline', $periodStart->copy()->addDay());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $secondAgain = $metrics->report(collect([$secondStation]), $periodStart, $periodEnd);

        $this->assertSame(0.0, $secondAgain['summary']['availability_percent']);
        $this->assertSame(0, $this->availabilityQueryCount());
    }

    /** @return array{Carbon, Carbon} */
    private function period(): array
    {
        $start = Carbon::parse('2026-07-01 00:00:00', 'UTC');

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => str($slug)->headline(),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function station(Organization $organization, string $reference, string $status, Carbon $monitoringStartedAt): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => str($reference)->headline(),
            'reference' => $reference,
            'location_name' => 'Tunis Center',
            'city' => 'Tunis',
            'address' => 'Availability metrics test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => $status,
            'availability_monitoring_started_at' => $monitoringStartedAt,
            'max_power_kw' => 120,
            'model' => 'Test model',
            'manufacturer' => 'Test manufacturer',
        ]);
    }

    private function transition(Station $station, string $from, string $to, Carbon $occurredAt): AvailabilityTransition
    {
        return AvailabilityTransition::query()->create([
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'from_status' => $from,
            'to_status' => $to,
            'from_reason' => 'test_previous_state',
            'to_reason' => 'test_projected_state',
            'source' => 'test',
            'occurred_at' => $occurredAt,
        ]);
    }

    private function availabilityQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'availability_transitions'))
            ->count();
    }
}
