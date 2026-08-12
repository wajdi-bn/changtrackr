<?php

namespace Tests\Feature;

use App\Jobs\ExecuteOcppSimulatorAction;
use App\Models\Connector;
use App\Models\OcppSimulatorAction;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientChargingTerminalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    public function test_client_can_queue_an_idempotent_plug_for_the_selected_simulator_connector(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);
        $key = (string) Str::uuid();

        $first = $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => $key,
        ])->assertAccepted()
            ->assertJsonPath('data.action', 'plug')
            ->assertJsonPath('data.connector.id', $connector->id);

        $connector->update(['status' => 'charging', 'ocpp_status' => 'Preparing']);
        $second = $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => $key,
        ])->assertAccepted();

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertDatabaseHas('ocpp_simulator_actions', [
            'uuid' => $first->json('data.uuid'),
            'requested_by_id' => $client->id,
            'connector_id' => $connector->id,
            'origin' => 'client_terminal',
            'idempotency_key' => $key,
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('ocpp_simulator_actions', 1);
        Queue::assertPushed(ExecuteOcppSimulatorAction::class, 1);

        $this->getJson($this->statusEndpoint($station, $connector, $first->json('data.uuid')))
            ->assertOk()
            ->assertJsonPath('data.uuid', $first->json('data.uuid'))
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_client_can_only_unplug_a_recent_virtual_connection_they_started(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertAccepted();
        $connector->update(['status' => 'charging', 'ocpp_status' => 'Preparing']);

        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'unplug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->firstSimulatorAction()->update(['status' => 'succeeded', 'completed_at' => now()]);
        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'unplug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertAccepted();

        $otherClient = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $otherClient->assignRole('client');
        Sanctum::actingAs($otherClient);
        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'unplug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('connector');
    }

    public function test_terminal_rejects_employee_access_cross_station_connectors_and_non_simulator_stations(): void
    {
        [$station, $connector, $client] = $this->fixture();
        $admin = User::factory()->create(['organization_id' => $station->organization_id, 'status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        [, $otherConnector] = $this->fixture();
        Sanctum::actingAs($client);
        $this->postJson($this->endpoint($station, $otherConnector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('connector');

        $station->update(['ocpp_commissioning_target' => 'external']);
        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('station');
    }

    public function test_terminal_rejects_unknown_actions_and_unavailable_connectors(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'inject_fault',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $connector->update(['status' => 'faulted', 'ocpp_status' => 'Faulted']);
        $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('connector');
    }

    public function test_client_cannot_read_another_clients_terminal_action(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $response = $this->postJson($this->endpoint($station, $connector), [
            'action' => 'plug',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertAccepted();

        $otherClient = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $otherClient->assignRole('client');
        Sanctum::actingAs($otherClient);

        $this->getJson($this->statusEndpoint($station, $connector, $response->json('data.uuid')))
            ->assertNotFound();
    }

    /** @return array{Station, Connector, User} */
    private function fixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Terminal Network '.Str::random(5),
            'slug' => 'terminal-network-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $identity = 'CT-TERM-'.Str::upper(Str::random(8));
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Virtual terminal station',
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
            'ocpp_provisioning_status' => 'provisioned',
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
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');

        return [$station, $connector, $client];
    }

    private function endpoint(Station $station, Connector $connector): string
    {
        return "/api/stations/{$station->id}/connectors/{$connector->id}/charging-terminal/actions";
    }

    private function statusEndpoint(Station $station, Connector $connector, string $actionUuid): string
    {
        return $this->endpoint($station, $connector).'/'.$actionUuid;
    }

    private function firstSimulatorAction(): OcppSimulatorAction
    {
        return OcppSimulatorAction::query()->oldest('id')->firstOrFail();
    }
}
