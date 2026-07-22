<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_admin_can_manage_only_their_organization_stations_and_connectors(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        $ownStation = $this->station($organization, 'CT-ADMIN-OWN');
        $otherOrganization = Organization::query()->create(['name' => 'External Network', 'slug' => 'external-admin-network', 'status' => 'active']);
        $otherStation = $this->station($otherOrganization, 'CT-ADMIN-OTHER');
        Sanctum::actingAs($admin);

        $this->getJson('/api/stations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownStation->id);

        $createdId = $this->postJson('/api/stations', $this->stationPayload('CT-ADMIN-NEW'))
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->json('data.id');

        $this->postJson("/api/stations/{$createdId}/connectors", [
            'external_id' => 'ADM-1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ])->assertCreated();

        $this->patchJson("/api/stations/{$otherStation->id}", ['name' => 'Forbidden update'])->assertForbidden();
        $this->deleteJson("/api/stations/{$otherStation->id}")->assertForbidden();
        $this->postJson('/api/stations', [
            ...$this->stationPayload('CT-ADMIN-INJECTED'),
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->deleteJson("/api/stations/{$createdId}")->assertNoContent();
    }

    public function test_ocpp_managed_station_rejects_manual_status_and_reserves_maintenance_for_supervision(): void
    {
        [$user, $organization] = $this->userWithRole('operator');
        $station = $this->station($organization, 'CT-MANAGED-001');
        $station->update([
            'ocpp_auth_secret_hash' => Hash::make('managed-station-secret-0123456789'),
            'availability_monitoring_started_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/stations/{$station->id}", ['status' => 'available'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/stations/{$station->id}", ['availability_override' => 'maintenance'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('availability_override');

        $this->patchJson("/api/stations/{$station->id}", ['availability_override' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.availability_reason', 'manually_disabled');

        $this->postJson("/api/stations/{$station->id}/connectors", [
            'external_id' => 'AUTO-1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
        ])->assertCreated()->assertJsonPath('data.status', 'unavailable');

        $this->postJson("/api/stations/{$station->id}/connectors", [
            'external_id' => 'MANUAL-1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_operator_cannot_inject_an_organization_or_use_a_connector_from_another_station(): void
    {
        [$user, $organization] = $this->userWithRole('operator');
        $otherOrganization = Organization::query()->create(['name' => 'External', 'slug' => 'external-'.uniqid(), 'status' => 'active']);
        $ownStation = $this->station($organization, 'CT-OWN-001');
        $externalStation = $this->station($otherOrganization, 'CT-EXTERNAL-001');
        $externalConnector = Connector::query()->create([
            'station_id' => $externalStation->id,
            'external_id' => 'X1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/stations', [
            ...$this->stationPayload('CT-INJECTED-001'),
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->putJson("/api/stations/{$ownStation->id}/connectors/{$externalConnector->id}", [
            'external_id' => 'X1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ])->assertNotFound();

        $this->deleteJson("/api/stations/{$ownStation->id}/connectors/{$externalConnector->id}")
            ->assertNotFound();
    }

    public function test_global_client_sees_stations_from_all_active_organizations(): void
    {
        $firstOrganization = Organization::query()->create(['name' => 'First Network', 'slug' => 'first-network', 'status' => 'active']);
        $secondOrganization = Organization::query()->create(['name' => 'Second Network', 'slug' => 'second-network', 'status' => 'active']);
        $inactiveOrganization = Organization::query()->create(['name' => 'Inactive Network', 'slug' => 'inactive-network', 'status' => 'inactive']);
        $firstStation = $this->station($firstOrganization, 'CT-CLIENT-001');
        $secondStation = $this->station($secondOrganization, 'CT-CLIENT-002');
        $hiddenStation = $this->station($inactiveOrganization, 'CT-CLIENT-003');
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');
        Sanctum::actingAs($client);

        $this->getJson('/api/stations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.stations', 2)
            ->assertJsonFragment(['id' => $firstStation->id, 'organization_id' => $firstOrganization->id])
            ->assertJsonFragment(['id' => $secondStation->id, 'organization_id' => $secondOrganization->id])
            ->assertJsonMissingPath('data.0.organization.settings')
            ->assertJsonMissingPath('data.0.organization.contact_email')
            ->assertJsonMissing(['id' => $hiddenStation->id]);

        $this->getJson("/api/stations/{$secondStation->id}")->assertOk();
        $this->getJson("/api/stations/{$hiddenStation->id}")->assertForbidden();
    }

    public function test_client_can_resolve_an_opaque_connector_qr_code(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        $client->update(['organization_id' => null]);
        $station = $this->station($organization, 'CT-QR-001');
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Sanctum::actingAs($client);

        $this->getJson("/api/connector-qr/{$connector->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.station_id', $station->id)
            ->assertJsonPath('data.connector_id', $connector->id)
            ->assertJsonPath('data.connector_external_id', 'A1');
    }

    public function test_map_endpoint_scopes_stations_to_the_operator_organization(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        $visible = $this->station($organization, 'CT-MAP-001');
        $otherOrganization = Organization::query()->create(['name' => 'Hidden Network', 'slug' => 'hidden-network', 'status' => 'active']);
        $this->station($otherOrganization, 'CT-MAP-002');
        Connector::query()->create([
            'station_id' => $visible->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Sanctum::actingAs($operator);

        $this->getJson('/api/stations/map')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.available_connectors_count', 1)
            ->assertJsonPath('summary.stations', 1)
            ->assertJsonPath('summary.available_connectors', 1)
            ->assertJsonPath('facets.cities.0', 'Tunis');
    }

    public function test_map_endpoint_filters_by_connector_power_availability_and_bounds(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        $client->update(['organization_id' => null]);
        $matching = $this->station($organization, 'CT-MAP-FILTER-001');
        $outside = $this->station($organization, 'CT-MAP-FILTER-002');
        $outside->update(['latitude' => 34.7, 'longitude' => 10.7, 'max_power_kw' => 50]);
        Connector::query()->create([
            'station_id' => $matching->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Connector::query()->create([
            'station_id' => $outside->id,
            'external_id' => 'B1',
            'type' => 'Type 2',
            'current_type' => 'AC',
            'max_power_kw' => 22,
            'status' => 'faulted',
        ]);
        Sanctum::actingAs($client);

        $this->getJson('/api/stations/map?connector_type=CCS2&min_power_kw=100&available_only=true&north=37&south=36&east=11&west=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_station_resources_expose_remote_start_capability_and_reason(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        $client->update(['organization_id' => null]);
        $station = $this->station($organization, 'CT-REMOTE-CAPABILITY-001');
        $station->update([
            'ocpp_auth_secret_hash' => Hash::make('remote-capability-secret'),
            'ocpp_connected_at' => now(),
            'ocpp_last_message_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'ocpp_connector_id' => 1,
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
            'ocpp_status' => 'Available',
            'ocpp_last_status_at' => now(),
        ]);
        Sanctum::actingAs($client);

        $this->getJson('/api/stations/map')
            ->assertOk()
            ->assertJsonPath('data.0.remote_start_available', true)
            ->assertJsonPath('data.0.remote_start_unavailable_reason', null);

        $station->update([
            'status' => 'offline',
            'ocpp_disconnected_at' => now(),
        ]);

        $this->getJson("/api/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.remote_start_available', false)
            ->assertJsonPath('data.remote_start_unavailable_reason', 'station_offline');
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
