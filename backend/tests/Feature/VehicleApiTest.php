<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_client_can_manage_only_its_vehicles_and_keep_one_default_vehicle(): void
    {
        $client = $this->client();
        Sanctum::actingAs($client);

        $firstId = $this->postJson('/api/vehicles', [
            'name' => 'Daily EV',
            'make' => 'BYD',
            'model' => 'Atto 3',
            'model_year' => 2025,
            'battery_capacity_kwh' => 60.4,
            'max_charging_power_kw' => 88,
            'connector_types' => ['Type 2', 'CCS2'],
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $secondId = $this->postJson('/api/vehicles', [
            'name' => 'Weekend EV',
            'connector_types' => ['CHAdeMO'],
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->assertDatabaseHas('vehicles', ['id' => $firstId, 'is_default' => false]);
        $this->getJson('/api/vehicles')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $secondId);
        $this->patchJson("/api/vehicles/{$firstId}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);
        $this->assertDatabaseHas('vehicles', ['id' => $secondId, 'is_default' => false]);

        $otherClient = $this->client();
        $otherVehicle = Vehicle::query()->create([
            'user_id' => $otherClient->id,
            'name' => 'Private EV',
            'connector_types' => ['CCS2'],
            'is_default' => true,
        ]);
        $this->patchJson("/api/vehicles/{$otherVehicle->id}", ['name' => 'Injected'])->assertForbidden();
        $this->deleteJson("/api/vehicles/{$otherVehicle->id}")->assertForbidden();
    }

    public function test_vehicle_is_linked_to_a_compatible_session_and_incompatible_connector_is_rejected(): void
    {
        $client = $this->client();
        $vehicle = Vehicle::query()->create([
            'user_id' => $client->id,
            'name' => 'CCS commuter',
            'connector_types' => ['CCS2'],
            'is_default' => true,
        ]);
        [$station, $connector] = $this->stationWithConnector('CCS2');
        Sanctum::actingAs($client);

        $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'vehicle_id' => $vehicle->id,
        ])->assertCreated()
            ->assertJsonPath('data.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.vehicle.name', 'CCS commuter');

        $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'vehicle_id' => Vehicle::query()->create([
                'user_id' => $client->id,
                'name' => 'AC only',
                'connector_types' => ['Type 2'],
            ])->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    private function client(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('client');

        return $user;
    }

    /** @return array{Station, Connector} */
    private function stationWithConnector(string $type): array
    {
        $organization = Organization::query()->create([
            'name' => 'Vehicle Network',
            'slug' => 'vehicle-network',
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Vehicle Test Station',
            'reference' => 'CT-VEHICLE-001',
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
            'ocpp_version' => 'OCPP 1.6J',
        ]);

        return [$station, Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => $type,
            'current_type' => $type === 'Type 2' ? 'AC' : 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ])];
    }
}
