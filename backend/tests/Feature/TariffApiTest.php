<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TariffApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_tariffs_and_only_one_remains_default(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $firstId = $this->postJson('/api/tariffs', $this->payload('STANDARD', true))
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->json('data.id');

        $secondId = $this->postJson('/api/tariffs', $this->payload('PREMIUM', true))
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('tariffs', ['id' => $firstId, 'is_default' => false]);
        $this->assertDatabaseHas('tariffs', ['id' => $secondId, 'is_default' => true]);
    }

    public function test_connector_assignment_overrides_station_and_default_tariffs(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        [$station, $connector] = $this->stationWithConnector($organization);
        $default = $this->tariff($organization, 'DEFAULT', 800, true);
        $stationTariff = $this->tariff($organization, 'STATION', 1000);
        $connectorTariff = $this->tariff($organization, 'CONNECTOR', 1200);
        Sanctum::actingAs($admin);

        $this->postJson("/api/tariffs/{$stationTariff->id}/assignments", ['station_id' => $station->id])->assertCreated();
        $this->getJson("/api/stations/{$station->id}/pricing?connector_id={$connector->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $stationTariff->id)
            ->assertJsonPath('data.source', 'station');

        $this->postJson("/api/tariffs/{$connectorTariff->id}/assignments", ['connector_id' => $connector->id])->assertCreated();
        $this->getJson("/api/stations/{$station->id}/pricing?connector_id={$connector->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $connectorTariff->id)
            ->assertJsonPath('data.price_per_kwh_millimes', 1200)
            ->assertJsonPath('data.source', 'connector');

        $this->assertTrue($default->is_default);
    }

    public function test_client_session_keeps_a_tariff_snapshot(): void
    {
        $organization = $this->organization('snapshot-network');
        $client = $this->user(null, 'client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-SNAPSHOT');
        $tariff = $this->tariff($organization, 'SNAPSHOT', 975, true);
        Sanctum::actingAs($client);

        $sessionId = $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tariff.id', $tariff->id)
            ->assertJsonPath('data.price_per_kwh_millimes', 975)
            ->json('data.id');

        $tariff->update(['price_per_kwh_millimes' => 1500]);

        $this->getJson("/api/charging-sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.price_per_kwh_millimes', 975);
    }

    public function test_operator_can_view_but_cannot_manage_tariffs(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        $this->tariff($organization, 'VISIBLE', 850, true);
        Sanctum::actingAs($operator);

        $this->getJson('/api/tariffs')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/tariffs', $this->payload('DENIED'))->assertForbidden();
    }

    public function test_administrator_cannot_access_or_assign_tariffs_across_organizations(): void
    {
        [$administrator, $organization] = $this->userWithRole('admin');
        $otherOrganization = $this->organization('other-tariff-network');
        [$otherStation, $otherConnector] = $this->stationWithConnector($otherOrganization, 'CT-OTHER-TARIFF');
        $ownTariff = $this->tariff($organization, 'OWN', 850, true);
        $otherTariff = $this->tariff($otherOrganization, 'OTHER', 900, true);
        Sanctum::actingAs($administrator);

        $this->getJson("/api/tariffs/{$otherTariff->id}")->assertForbidden();
        $this->patchJson("/api/tariffs/{$otherTariff->id}", ['name' => 'Forbidden'])->assertForbidden();

        $this->postJson("/api/tariffs/{$ownTariff->id}/assignments", [
            'station_id' => $otherStation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignment');
        $this->postJson("/api/tariffs/{$ownTariff->id}/assignments", [
            'connector_id' => $otherConnector->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignment');
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = $this->organization($role.'-'.uniqid());

        return [$this->user($organization, $role), $organization];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
    }

    private function user(?Organization $organization, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{Station, Connector} */
    private function stationWithConnector(Organization $organization, string $reference = 'CT-TARIFF-001'): array
    {
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Tariff Station',
            'reference' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test',
            'manufacturer' => 'Test',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);

        return [$station, $connector];
    }

    private function tariff(Organization $organization, string $code, int $price, bool $default = false): Tariff
    {
        return Tariff::query()->create([
            'organization_id' => $organization->id,
            'name' => $code.' tariff',
            'code' => $code,
            'status' => 'active',
            'currency' => 'TND',
            'price_per_kwh_millimes' => $price,
            'session_fee_millimes' => 500,
            'idle_fee_per_minute_millimes' => 100,
            'minimum_charge_millimes' => 1000,
            'is_default' => $default,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $code, bool $default = false): array
    {
        return [
            'name' => $code.' tariff',
            'code' => $code,
            'status' => 'active',
            'currency' => 'TND',
            'price_per_kwh_millimes' => 850,
            'session_fee_millimes' => 500,
            'idle_fee_per_minute_millimes' => 100,
            'minimum_charge_millimes' => 1000,
            'is_default' => $default,
        ];
    }
}
