<?php

namespace Tests\Feature;

use App\Jobs\ExecuteOcppSimulatorAction;
use App\Models\Connector;
use App\Models\OcppEvent;
use App\Models\OcppSimulatorAction;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppSimulatorControlClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OcppSimulatorConsoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('ocpp.simulator.control_url', 'http://simulator-control:8081');
        config()->set('ocpp.simulator.control_token', 'private-control-token');
    }

    public function test_admin_can_read_live_simulator_state_and_audited_history(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        Sanctum::actingAs($admin);
        Http::fake([
            'http://simulator-control:8081/stations/*' => Http::response([
                'data' => [
                    'identity' => $station->ocpp_identity,
                    'started' => true,
                    'connected' => true,
                    'ws_state' => 1,
                    'connectors' => [],
                ],
            ]),
        ]);

        $this->getJson("/api/stations/{$station->id}/simulator")
            ->assertOk()
            ->assertJsonPath('adapter.available', true)
            ->assertJsonPath('state.identity', $station->ocpp_identity)
            ->assertJsonPath('capabilities.diagnose', true)
            ->assertJsonPath('capabilities.control', true)
            ->assertJsonPath('capabilities.central_commands', true)
            ->assertJsonCount(0, 'history.data');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer private-control-token'));
    }

    public function test_lab_lists_only_simulator_stations_in_the_user_scope(): void
    {
        [$station, , $operator] = $this->fixture('operator');
        [$otherStation] = $this->fixture('operator');
        Station::query()->create([
            'organization_id' => $operator->organization_id,
            'name' => 'External station',
            'reference' => 'EXT-'.uniqid(),
            'ocpp_identity' => 'EXT-'.uniqid(),
            'location_name' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'External address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'offline',
            'max_power_kw' => 22,
            'model' => 'External',
            'manufacturer' => 'External',
            'ocpp_version' => 'OCPP 1.6J',
            'ocpp_commissioning_target' => 'external',
        ]);
        Sanctum::actingAs($operator);

        $this->getJson('/api/simulation-lab/stations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $station->id)
            ->assertJsonMissing(['id' => $otherStation->id]);
    }

    public function test_console_returns_only_sanitized_live_signals_and_capabilities(): void
    {
        [$station, , $technician] = $this->fixture('technician');
        OcppEvent::query()->create([
            'event_id' => (string) str()->uuid(),
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'connection_id' => 'connection-1',
            'message_id' => 'message-1',
            'protocol_version' => '1.6J',
            'action' => 'StatusNotification',
            'payload' => [
                'connectorId' => 1,
                'status' => 'Preparing',
                'errorCode' => 'NoError',
                'idTag' => 'PRIVATE-RFID',
            ],
            'payload_hash' => hash('sha256', 'signal'),
            'response_payload' => ['secret' => 'private'],
            'processing_status' => 'processed',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
        Sanctum::actingAs($technician);
        Http::fake(['*' => Http::response(['data' => [
            'identity' => $station->ocpp_identity,
            'started' => true,
            'connected' => true,
            'ws_state' => 1,
            'supervision_url' => 'http://private-control:8080',
            'secret' => 'private',
            'connectors' => [[
                'connector_id' => 1,
                'status' => 'Preparing',
                'error_code' => 'NoError',
                'availability' => 'operative',
                'transaction_started' => false,
                'private_state' => 'hidden',
            ]],
        ]])]);

        $response = $this->getJson("/api/stations/{$station->id}/simulator")
            ->assertOk()
            ->assertJsonPath('capabilities.view', true)
            ->assertJsonPath('capabilities.diagnose', true)
            ->assertJsonPath('capabilities.control', false)
            ->assertJsonPath('capabilities.central_commands', false)
            ->assertJsonPath('capabilities.allowed_actions.0', 'heartbeat')
            ->assertJsonMissing(['connect', 'disconnect'])
            ->assertJsonPath('signals.events.0.connector_id', 1)
            ->assertJsonPath('signals.events.0.status', 'Preparing')
            ->assertJsonMissingPath('state.supervision_url')
            ->assertJsonMissingPath('state.secret')
            ->assertJsonMissingPath('state.connectors.0.private_state')
            ->assertJsonMissingPath('signals.events.0.payload');

        $this->assertStringNotContainsString('PRIVATE-RFID', $response->getContent());
        $this->assertStringNotContainsString('private-control', $response->getContent());
    }

    public function test_operator_can_queue_connector_action_and_worker_records_sanitized_result(): void
    {
        Queue::fake();
        [$station, $connector, $operator] = $this->fixture('operator');
        Sanctum::actingAs($operator);

        $this->postJson("/api/stations/{$station->id}/simulator/actions", [
            'action' => 'plug',
            'connector_id' => 1,
        ])->assertAccepted()
            ->assertJsonPath('data.action', 'plug')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.connector.id', $connector->id);

        Queue::assertPushed(ExecuteOcppSimulatorAction::class);
        $action = OcppSimulatorAction::query()->sole();
        $this->assertSame(['connector_id' => 1], $action->request_payload);

        Http::fake([
            'http://simulator-control:8081/stations/*/actions' => Http::response([
                'data' => [
                    'identity' => $station->ocpp_identity,
                    'connected' => true,
                    'connectors' => [['connector_id' => 1, 'status' => 'Preparing']],
                ],
            ]),
        ]);
        (new ExecuteOcppSimulatorAction($action->id))->handle(app(OcppSimulatorControlClient::class));

        $action->refresh();
        $this->assertSame('succeeded', $action->status);
        $this->assertSame('Preparing', $action->result_payload['connectors'][0]['status']);
        $this->assertNull($action->failure_message);
    }

    public function test_simulation_control_permission_also_allows_diagnostic_actions(): void
    {
        Queue::fake();
        [$station, , $admin] = $this->fixture('admin');
        Role::findByName('admin', 'web')->revokePermissionTo('ocpp_simulation.diagnose');
        Sanctum::actingAs($admin);

        $this->postJson("/api/stations/{$station->id}/simulator/actions", [
            'action' => 'heartbeat',
        ])->assertAccepted();

        Queue::assertPushed(ExecuteOcppSimulatorAction::class);
    }

    public function test_technician_can_run_diagnostics_but_not_station_or_central_controls(): void
    {
        Queue::fake();
        [$station, $connector, $technician] = $this->fixture('technician');
        Sanctum::actingAs($technician);
        Http::fake(['*' => Http::response(['data' => ['identity' => $station->ocpp_identity]])]);

        $this->getJson("/api/stations/{$station->id}/simulator")->assertOk();
        $this->postJson("/api/stations/{$station->id}/simulator/actions", [
            'action' => 'plug',
            'connector_id' => 1,
        ])->assertAccepted()
            ->assertJsonPath('data.connector.id', $connector->id);
        Queue::assertPushed(ExecuteOcppSimulatorAction::class);

        $this->postJson("/api/stations/{$station->id}/simulator/actions", ['action' => 'connect'])
            ->assertForbidden();
        $this->postJson("/api/stations/{$station->id}/commands/reset", ['type' => 'Soft'])
            ->assertForbidden();
    }

    public function test_client_has_no_simulation_lab_access(): void
    {
        [$station, , $client] = $this->fixture('client');
        Sanctum::actingAs($client);

        $this->getJson('/api/simulation-lab/stations')->assertForbidden();
        $this->getJson("/api/stations/{$station->id}/simulator")->assertForbidden();
    }

    public function test_console_rejects_non_simulator_station_and_cross_organization_access(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        $station->update(['ocpp_commissioning_target' => 'external']);
        Sanctum::actingAs($admin);

        $this->getJson("/api/stations/{$station->id}/simulator")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station');

        [$otherStation, , $otherAdmin] = $this->fixture('admin');
        Sanctum::actingAs($admin);
        $this->getJson("/api/stations/{$otherStation->id}/simulator")->assertForbidden();
        $this->postJson("/api/stations/{$otherStation->id}/simulator/actions", ['action' => 'heartbeat'])
            ->assertForbidden();
        $this->assertNotSame($admin->organization_id, $otherAdmin->organization_id);
    }

    public function test_action_validation_rejects_raw_commands_and_unknown_connectors(): void
    {
        [$station, , $admin] = $this->fixture('admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/stations/{$station->id}/simulator/actions", ['action' => 'raw_json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
        $this->postJson("/api/stations/{$station->id}/simulator/actions", [
            'action' => 'plug',
            'connector_id' => 99,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('connector_id');
    }

    /** @return array{Station, Connector, User} */
    private function fixture(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => 'Network '.uniqid(),
            'slug' => 'network-'.uniqid(),
            'status' => 'active',
        ]);
        $identity = 'CT-'.strtoupper(substr(uniqid(), -8));
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Simulator station',
            'reference' => $identity,
            'ocpp_identity' => $identity,
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
            'ocpp_commissioning_target' => 'simulator',
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
