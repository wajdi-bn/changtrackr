<?php

namespace Tests\Feature;

use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppCommand;
use App\Models\OcppIdTag;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemoteChargingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY_SECRET = 'gateway-test-secret-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('ocpp.gateway.shared_secret', self::GATEWAY_SECRET);
        config()->set('payments.preauthorization_amount_millimes', 30000);
    }

    public function test_remote_start_creates_a_session_only_after_station_confirmation_and_captures_on_stop(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $attemptResponse = $this->postJson('/api/charging-attempts', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
            'limit_energy_kwh' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'command_queued')
            ->assertJsonPath('data.payment_status', 'authorized')
            ->assertJsonPath('data.preauthorized_amount_millimes', 30000);

        $attempt = ChargingAttempt::query()->where('uuid', $attemptResponse->json('data.uuid'))->sole();
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseHas('ocpp_commands', ['charging_attempt_id' => $attempt->id, 'status' => 'queued']);

        $connectionId = (string) Str::uuid();
        $claim = $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $station->ocpp_identity,
            'connection_id' => $connectionId,
        ])->assertOk()->assertJsonPath('command.action', 'RemoteStartTransaction');
        $commandUuid = $claim->json('command.uuid');

        $this->signedPost("/api/internal/ocpp/commands/{$commandUuid}/result", [
            'connection_id' => $connectionId,
            'status' => 'accepted',
            'result' => ['ocppStatus' => 'Accepted'],
        ])->assertOk();

        $idTag = OcppIdTag::query()->where('kind', 'virtual_app')->sole();
        $start = $this->sendEvent($station, 'StartTransaction', 'remote-start-confirmed', [
            'connectorId' => 1,
            'idTag' => $idTag->token_ciphertext,
            'meterStart' => 100000,
            'timestamp' => now()->subMinutes(5)->toISOString(),
        ])->assertCreated()->assertJsonPath('ocpp_response.idTagInfo.status', 'Accepted');
        $transactionId = (int) $start->json('ocpp_response.transactionId');

        $session = ChargingSession::query()->sole();
        $attempt->refresh();
        $this->assertSame('charging', $attempt->status);
        $this->assertSame($session->id, $attempt->charging_session_id);
        $this->assertSame('authorized', $session->payment_status);
        $this->assertSame(10.0, $session->limit_energy_kwh);
        $this->assertSame(30000, $session->limit_amount_millimes);
        $this->assertDatabaseHas('ocpp_commands', ['uuid' => $commandUuid, 'status' => 'confirmed']);

        $this->sendEvent($station, 'MeterValues', 'remote-meter', [
            'connectorId' => 1,
            'transactionId' => $transactionId,
            'meterValue' => [[
                'timestamp' => now()->subMinute()->toISOString(),
                'sampledValue' => [
                    ['value' => '102000', 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh'],
                    ['value' => '45000', 'measurand' => 'Power.Active.Import', 'unit' => 'W'],
                    ['value' => '64', 'measurand' => 'SoC', 'unit' => 'Percent'],
                ],
            ]],
        ])->assertCreated();
        $session->refresh();
        $this->assertSame(45.0, $session->current_power_kw);
        $this->assertSame(64.0, $session->state_of_charge_percent);

        Sanctum::actingAs($client);
        $this->postJson("/api/charging-sessions/{$session->id}/remote-stop")
            ->assertOk()
            ->assertJsonPath('data.status', 'stopping');

        $stopClaim = $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $station->ocpp_identity,
            'connection_id' => $connectionId,
        ])->assertOk()->assertJsonPath('command.action', 'RemoteStopTransaction');
        $this->signedPost('/api/internal/ocpp/commands/'.$stopClaim->json('command.uuid').'/result', [
            'connection_id' => $connectionId,
            'status' => 'accepted',
            'result' => ['ocppStatus' => 'Accepted'],
        ])->assertOk();

        $this->sendEvent($station, 'StopTransaction', 'remote-stop-confirmed', [
            'transactionId' => $transactionId,
            'idTag' => $idTag->token_ciphertext,
            'meterStop' => 103000,
            'timestamp' => now()->toISOString(),
            'reason' => 'Remote',
        ])->assertCreated();

        $session->refresh();
        $attempt->refresh();
        $payment = Payment::query()->sole();
        $this->assertSame('completed', $session->status);
        $this->assertSame('paid', $session->payment_status);
        $this->assertSame('paid', $payment->status);
        $this->assertStringStartsWith('SIM-CAP-', $payment->provider_transaction_id);
        $this->assertSame('completed', $attempt->status);
        $this->assertSame('captured', $attempt->payment_status);
    }

    public function test_declined_preauthorization_creates_no_command_or_session(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $this->postJson('/api/charging-attempts', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'method' => 'simulated_d17',
            'simulation_outcome' => 'declined',
            'idempotency_key' => '10000000-0000-4000-8000-000000000002',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.failure_code', 'payment_declined');

        $this->assertDatabaseCount('ocpp_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_energy_limit_queues_a_remote_stop_command(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);

        $attemptResponse = $this->postJson('/api/charging-attempts', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000004',
            'limit_energy_kwh' => 1,
        ])->assertCreated();

        $connectionId = (string) Str::uuid();
        $claim = $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $station->ocpp_identity,
            'connection_id' => $connectionId,
        ])->assertOk();
        $this->signedPost('/api/internal/ocpp/commands/'.$claim->json('command.uuid').'/result', [
            'connection_id' => $connectionId,
            'status' => 'accepted',
            'result' => ['ocppStatus' => 'Accepted'],
        ])->assertOk();

        $attempt = ChargingAttempt::query()->where('uuid', $attemptResponse->json('data.uuid'))->sole();
        $idTag = OcppIdTag::query()->findOrFail($attempt->ocpp_id_tag_id);
        $start = $this->sendEvent($station, 'StartTransaction', 'limited-start', [
            'connectorId' => 1,
            'idTag' => $idTag->token_ciphertext,
            'meterStart' => 100000,
            'timestamp' => now()->subMinutes(2)->toISOString(),
        ])->assertCreated();

        $this->sendEvent($station, 'MeterValues', 'limited-meter', [
            'connectorId' => 1,
            'transactionId' => (int) $start->json('ocpp_response.transactionId'),
            'meterValue' => [[
                'timestamp' => now()->toISOString(),
                'sampledValue' => [[
                    'value' => '101100',
                    'measurand' => 'Energy.Active.Import.Register',
                    'unit' => 'Wh',
                ]],
            ]],
        ])->assertCreated();

        $session = ChargingSession::query()->sole();
        $command = OcppCommand::query()
            ->where('action', 'RemoteStopTransaction')
            ->where('charging_session_id', $session->id)
            ->sole();

        $this->assertSame('stopping', $session->fresh()->status);
        $this->assertSame('queued', $command->status);
        $this->assertSame('energy_limit_reached', $command->encrypted_payload['reason']);
    }

    public function test_station_rejection_releases_the_authorization(): void
    {
        [$station, $connector, $client] = $this->fixture();
        Sanctum::actingAs($client);
        $this->postJson('/api/charging-attempts', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'method' => 'simulated_edinar',
            'idempotency_key' => '10000000-0000-4000-8000-000000000003',
        ])->assertCreated();

        $connectionId = (string) Str::uuid();
        $claim = $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $station->ocpp_identity,
            'connection_id' => $connectionId,
        ])->assertOk();
        $this->signedPost('/api/internal/ocpp/commands/'.$claim->json('command.uuid').'/result', [
            'connection_id' => $connectionId,
            'status' => 'rejected',
            'result' => ['ocppStatus' => 'Rejected'],
        ])->assertOk();

        $this->assertDatabaseHas('charging_attempts', [
            'user_id' => $client->id,
            'status' => 'failed',
            'payment_status' => 'released',
            'failure_code' => 'remote_start_rejected',
        ]);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    /** @return array{Station, Connector, User} */
    private function fixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Remote Network',
            'slug' => 'remote-network-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Remote Station',
            'reference' => 'CT-REMOTE-001',
            'ocpp_identity' => 'CT-REMOTE-001',
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'charging',
            'max_power_kw' => 120,
            'model' => 'Simulator',
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
            'status' => 'charging',
            'ocpp_status' => 'Preparing',
            'ocpp_last_status_at' => now(),
        ]);
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');

        return [$station, $connector, $client];
    }

    /** @param array<string, mixed> $payload */
    private function sendEvent(Station $station, string $action, string $messageId, array $payload): TestResponse
    {
        return $this->signedPost('/api/internal/ocpp/events', [
            'event_id' => (string) Str::uuid(),
            'connection_id' => (string) Str::uuid(),
            'station_identity' => $station->ocpp_identity,
            'message_id' => $messageId,
            'protocol_version' => '1.6',
            'action' => $action,
            'payload' => $payload,
            'occurred_at' => now()->toISOString(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(string $uri, array $payload): TestResponse
    {
        $timestamp = now()->timestamp;
        $requestId = (string) Str::uuid();
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$requestId.'.'.$json, self::GATEWAY_SECRET);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHARGETRACKR_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CHARGETRACKR_REQUEST_ID' => $requestId,
            'HTTP_X_CHARGETRACKR_SIGNATURE' => $signature,
        ], $json);
    }
}
