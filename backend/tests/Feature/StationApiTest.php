<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_operator_only_sees_stations_from_their_organization(): void
    {
        [$user, $organization] = $this->userWithRole('operator');
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $visible = $this->station($organization, 'CT-TEST-001');
        $this->station($otherOrganization, 'CT-TEST-002');

        Sanctum::actingAs($user);

        $this->getJson('/api/stations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('summary.stations', 1);
    }

    public function test_operator_can_create_a_station_in_their_organization(): void
    {
        [$user, $organization] = $this->userWithRole('operator');
        Sanctum::actingAs($user);

        $this->postJson('/api/stations', $this->stationPayload('CT-NEW-001'))
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.reference', 'CT-NEW-001');
    }

    public function test_technician_can_view_but_cannot_create_stations(): void
    {
        [$user, $organization] = $this->userWithRole('technician');
        $station = $this->station($organization, 'CT-TECH-001');
        Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.connectors.0.external_id', 'A1');

        $this->postJson('/api/stations', $this->stationPayload('CT-DENIED-001'))->assertForbidden();
    }

    public function test_operator_cannot_update_a_station_from_another_organization(): void
    {
        [$user] = $this->userWithRole('operator');
        $otherOrganization = Organization::query()->create(['name' => 'Other Network', 'slug' => 'other-network', 'status' => 'active']);
        $station = $this->station($otherOrganization, 'CT-OTHER-001');
        Sanctum::actingAs($user);

        $this->patchJson("/api/stations/{$station->id}", ['name' => 'Forbidden update'])
            ->assertForbidden();
    }

    public function test_operator_can_add_a_connector_to_their_station(): void
    {
        [$user, $organization] = $this->userWithRole('operator');
        $station = $this->station($organization, 'CT-CONNECTOR-001');
        Sanctum::actingAs($user);

        $this->postJson("/api/stations/{$station->id}/connectors", [
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ])
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'A1');
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($role).' Organization',
            'slug' => $role.'-'.uniqid(),
            'status' => 'active',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$user, $organization];
    }

    private function station(Organization $organization, string $reference): Station
    {
        return Station::query()->create([
            ...$this->stationPayload($reference),
            'organization_id' => $organization->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function stationPayload(string $reference): array
    {
        return [
            'name' => 'Test Station',
            'reference' => $reference,
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
        ];
    }
}
