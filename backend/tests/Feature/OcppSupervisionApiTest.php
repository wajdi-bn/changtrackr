<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\OcppCommand;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppCommandService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OcppSupervisionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('ocpp.gateway.supervision_command_ttl_seconds', 60);
    }

    public function test_admin_can_queue_an_idempotent_soft_reset_for_its_connected_station(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        Sanctum::actingAs($admin);

        $first = $this->postJson("/api/stations/{$station->id}/commands/reset")
            ->assertAccepted()
            ->assertJsonPath('data.action', 'Reset')
            ->assertJsonPath('data.status', 'queued');
        $second = $this->postJson("/api/stations/{$station->id}/commands/reset")
            ->assertAccepted();

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertDatabaseCount('ocpp_commands', 1);
        $this->assertSame(['type' => 'Soft'], OcppCommand::query()->sole()->encrypted_payload);
        $this->assertStringNotContainsString('Soft', (string) DB::table('ocpp_commands')->value('encrypted_payload'));
    }

    public function test_operator_can_unlock_an_ocpp_connector_and_read_the_audit_history(): void
    {
        [$station, $connector, $operator] = $this->fixture('operator');
        Sanctum::actingAs($operator);

        $this->postJson("/api/stations/{$station->id}/connectors/{$connector->id}/commands/unlock")
            ->assertAccepted()
            ->assertJsonPath('data.action', 'UnlockConnector')
            ->assertJsonPath('data.connector.external_id', 'A1')
            ->assertJsonPath('data.requested_by.id', $operator->id);

        $this->getJson("/api/stations/{$station->id}/commands")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'UnlockConnector')
            ->assertJsonMissingPath('data.0.encrypted_payload');
    }

    public function test_technician_has_read_only_command_history_access(): void
    {
        [$station, , $technician] = $this->fixture('technician');
        Sanctum::actingAs($technician);

        $this->getJson("/api/stations/{$station->id}/commands")->assertOk();
        $this->postJson("/api/stations/{$station->id}/commands/reset")->assertForbidden();
    }

    public function test_organization_scope_is_enforced_for_command_execution_and_history(): void
    {
        [$station] = $this->fixture('admin');
        [, , $otherAdmin] = $this->fixture('admin');
        Sanctum::actingAs($otherAdmin);

        $this->getJson("/api/stations/{$station->id}/commands")->assertForbidden();
        $this->postJson("/api/stations/{$station->id}/commands/reset")->assertForbidden();
    }

    public function test_offline_station_rejects_reset_but_maintenance_override_is_applied_locally(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        $station->update([
            'ocpp_connected_at' => now()->subMinutes(10),
            'ocpp_last_message_at' => now()->subMinutes(10),
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/stations/{$station->id}/commands/reset")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station');

        $this->putJson("/api/stations/{$station->id}/maintenance", ['enabled' => true])
            ->assertAccepted()
            ->assertJsonPath('ocpp_sync', 'not_connected')
            ->assertJsonPath('station.status', 'maintenance');

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'availability_override' => 'maintenance',
            'status' => 'maintenance',
        ]);
        $this->assertDatabaseCount('ocpp_commands', 0);
    }

    public function test_connected_station_maintenance_queues_change_availability_and_can_be_cleared(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        Sanctum::actingAs($admin);

        $this->putJson("/api/stations/{$station->id}/maintenance", ['enabled' => true])
            ->assertAccepted()
            ->assertJsonPath('ocpp_sync', 'queued')
            ->assertJsonPath('command.action', 'ChangeAvailability');

        $command = OcppCommand::query()->sole();
        $this->assertSame(['connectorId' => 0, 'type' => 'Inoperative'], $command->encrypted_payload);
        $this->assertSame('maintenance', $station->fresh()->availability_override);

        $command->update(['status' => 'accepted', 'responded_at' => now()]);
        $this->putJson("/api/stations/{$station->id}/maintenance", ['enabled' => false])
            ->assertAccepted()
            ->assertJsonPath('command.action', 'ChangeAvailability');

        $this->assertNull($station->fresh()->availability_override);
        $this->assertSame(
            ['connectorId' => 0, 'type' => 'Operative'],
            OcppCommand::query()->latest('id')->firstOrFail()->encrypted_payload,
        );
    }

    public function test_accepted_supervision_command_is_terminal_and_is_not_timed_out_later(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        $service = app(OcppCommandService::class);
        $command = $service->queueReset($station, $admin);
        $connectionId = '11111111-1111-4111-8111-111111111111';
        $claimed = $service->claim($station->ocpp_identity, $connectionId);

        $service->complete($claimed, $connectionId, 'accepted', ['ocppStatus' => 'Accepted']);
        $this->travel(2)->minutes();

        $this->assertSame(0, $service->expireDue());
        $this->assertSame('accepted', $command->fresh()->status);
    }

    public function test_unclaimed_supervision_command_times_out_after_its_deadline(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        $service = app(OcppCommandService::class);
        $command = $service->queueReset($station, $admin);

        $this->travel(61)->seconds();

        $this->assertSame(1, $service->expireDue());
        $command->refresh();
        $this->assertSame('timed_out', $command->status);
        $this->assertSame('command_timed_out', $command->failure_code);
    }

    /** @return array{Station, Connector, User} */
    private function fixture(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => 'Network '.uniqid(),
            'slug' => 'network-'.uniqid(),
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Lac 1 Fast Hub',
            'reference' => 'CT-'.strtoupper(substr(uniqid(), -8)),
            'ocpp_identity' => 'CT-'.strtoupper(substr(uniqid(), -8)),
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'SAP simulator',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
            'ocpp_auth_secret_hash' => Hash::make('station-secret'),
            'ocpp_connected_at' => now(),
            'ocpp_last_message_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        $connector = Connector::query()->create([
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
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$station, $connector, $user];
    }
}
