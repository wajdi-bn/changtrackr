<?php

namespace Tests\Feature;

use App\Models\Station;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SaasPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcppSimulatorFleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_fleet_matches_the_multi_station_simulator_manifest(): void
    {
        $this->seed([RolePermissionSeeder::class, SaasPlanSeeder::class, DemoDataSeeder::class]);

        $manifest = json_decode(
            file_get_contents(base_path('../infra/ocpp/simulator/stations.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $stations = Station::query()
            ->with(['connectors' => fn ($query) => $query->orderBy('ocpp_connector_id')])
            ->get();
        $manifestByIdentity = collect($manifest)->keyBy('identity');

        $this->assertCount(9, $stations);
        $this->assertGreaterThanOrEqual($stations->count(), count($manifest));

        foreach ($stations as $station) {
            $simulatorStation = $manifestByIdentity->get($station->reference);
            $this->assertNotNull($simulatorStation, "Station [{$station->reference}] is missing from the simulator manifest.");
            $this->assertSame('OCPP 1.6J', $station->ocpp_version);
            $this->assertSame('offline', $station->status);
            $this->assertNull($station->last_heartbeat_at);
            $this->assertSame([1, 2], $station->connectors->pluck('ocpp_connector_id')->all());
            $this->assertEquals(
                $simulatorStation['connectorPowersKw'],
                $station->connectors->pluck('max_power_kw')->all(),
            );
        }
    }
}
