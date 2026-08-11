<?php

namespace Tests\Feature;

use App\Jobs\ExecuteOcppSimulatorAction;
use App\Models\Connector;
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
            ->assertJsonCount(0, 'history.data');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer private-control-token'));
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

    public function test_technician_has_read_only_access_and_client_has_no_console_access(): void
    {
        [$station, , $technician] = $this->fixture('technician');
        Sanctum::actingAs($technician);
        Http::fake(['*' => Http::response(['data' => ['identity' => $station->ocpp_identity]])]);

        $this->getJson("/api/stations/{$station->id}/simulator")->assertOk();
        $this->postJson("/api/stations/{$station->id}/simulator/actions", ['action' => 'connect'])
            ->assertForbidden();

        [, , $client] = $this->fixture('client');
        Sanctum::actingAs($client);
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
