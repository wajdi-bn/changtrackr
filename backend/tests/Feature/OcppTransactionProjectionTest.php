<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppIdTag;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppAuthorizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OcppTransactionProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY_SECRET = 'gateway-test-secret-0123456789abcdef0123456789abcdef';

    private const STATION_SECRET = 'station-test-secret-0123456789abcdef0123456789abcdef';

    private const ID_TAG = 'TEST-TAG-001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('ocpp.gateway.shared_secret', self::GATEWAY_SECRET);
        config()->set('ocpp.gateway.signature_tolerance_seconds', 300);
    }

    public function test_authorize_start_meter_and_stop_create_one_priced_session(): void
    {
        [$station, $connector, $client] = $this->transactionFixture();

        $this->send($station, 'Authorize', 'authorize-001', ['idTag' => self::ID_TAG])
            ->assertCreated()
            ->assertJsonPath('ocpp_response.idTagInfo.status', 'Accepted');

        $startPayload = [
            'connectorId' => 1,
            'idTag' => self::ID_TAG,
            'meterStart' => 100000,
            'timestamp' => now()->subMinutes(10)->toISOString(),
        ];
        $start = $this->send($station, 'StartTransaction', 'start-001', $startPayload)
            ->assertCreated()
            ->assertJsonPath('ocpp_response.idTagInfo.status', 'Accepted');
        $transactionId = (int) $start->json('ocpp_response.transactionId');

        $session = ChargingSession::query()->sole();
        $this->assertSame($client->id, $session->client_id);
        $this->assertSame($connector->id, $session->connector_id);
        $this->assertSame($transactionId, $session->ocpp_transaction_id);
        $this->assertSame('ocpp', $session->source);
        $this->assertSame('charging', $session->status);

        $this->send($station, 'MeterValues', 'meter-001', [
            'connectorId' => 1,
            'meterValue' => [[
                'timestamp' => now()->subMinutes(5)->toISOString(),
                'sampledValue' => [
                    ['value' => '102500', 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh'],
                    ['value' => '45000', 'measurand' => 'Power.Active.Import', 'unit' => 'W'],
                ],
            ]],
        ])->assertCreated();

        $session->refresh();
        $this->assertSame(2.5, $session->energy_kwh);
        $this->assertNotNull($session->last_meter_value_at);
        $this->assertDatabaseCount('ocpp_meter_samples', 2);

        $this->send($station, 'StopTransaction', 'stop-001', [
            'transactionId' => $transactionId,
            'idTag' => self::ID_TAG,
            'meterStop' => 104000,
            'timestamp' => now()->toISOString(),
            'reason' => 'EVDisconnected',
        ])->assertCreated()->assertJsonPath('ocpp_response.idTagInfo.status', 'Accepted');

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('stop_transaction_ev_disconnected', $session->lifecycle_reason);
        $this->assertSame(4.0, $session->energy_kwh);
        $this->assertSame(104.0, $session->meter_stop_kwh);
        $this->assertGreaterThan(0, $session->total_millimes);
        $this->assertSame('unpaid', $session->payment_status);
        $this->assertDatabaseHas('ocpp_transactions', [
            'id' => $transactionId,
            'status' => 'completed',
            'stop_reason' => 'EVDisconnected',
        ]);
    }

    public function test_start_transaction_retry_returns_the_same_transaction_and_session(): void
    {
        [$station] = $this->transactionFixture();
        $eventId = (string) Str::uuid();
        $payload = [
            'connectorId' => 1,
            'idTag' => self::ID_TAG,
            'meterStart' => 200000,
            'timestamp' => now()->toISOString(),
        ];

        $first = $this->send($station, 'StartTransaction', 'start-retry', $payload, $eventId)->assertCreated();
        $second = $this->send($station, 'StartTransaction', 'start-retry', $payload, $eventId)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('ocpp_response.transactionId'), $second->json('ocpp_response.transactionId'));
        $this->assertDatabaseCount('ocpp_transactions', 1);
        $this->assertDatabaseCount('charging_sessions', 1);
    }

    public function test_concurrent_client_start_is_returned_as_an_ocpp_concurrent_transaction(): void
    {
        [$station, $connector, $client] = $this->transactionFixture();
        $competingSession = ChargingSession::query()->create([
            'organization_id' => $station->organization_id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'reference' => 'SES-OCPP-RACE-WINNER',
            'source' => 'ocpp',
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => $connector->external_id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinutes(30),
            'meter_start_kwh' => 100,
            'meter_stop_kwh' => 110,
            'energy_kwh' => 10,
            'price_per_kwh_millimes' => 850,
            'session_fee_millimes' => 500,
            'total_millimes' => 9000,
            'currency' => 'TND',
        ]);
        $injected = false;

        ChargingSession::creating(function () use ($competingSession, &$injected): void {
            if ($injected) {
                return;
            }

            $injected = true;
            ChargingSession::query()->whereKey($competingSession->id)->update([
                'status' => 'charging',
                'ended_at' => null,
            ]);
        });

        try {
            $this->send($station, 'StartTransaction', 'start-client-race', [
                'connectorId' => 1,
                'idTag' => self::ID_TAG,
                'meterStart' => 300000,
                'timestamp' => now()->toISOString(),
            ])
                ->assertCreated()
                ->assertJsonPath('ocpp_response.idTagInfo.status', 'ConcurrentTx');
        } finally {
            ChargingSession::flushEventListeners();
        }

        $this->assertDatabaseCount('charging_sessions', 1);
        $this->assertSame('completed', $competingSession->fresh()->status);
        $this->assertDatabaseHas('ocpp_transactions', [
            'status' => 'rejected',
            'rejection_reason' => 'active_client_session_exists',
        ]);
    }

    public function test_unknown_tag_is_rejected_without_creating_a_billable_session(): void
    {
        [$station] = $this->transactionFixture();

        $response = $this->send($station, 'StartTransaction', 'start-invalid', [
            'connectorId' => 1,
            'idTag' => 'UNKNOWN-TAG',
            'meterStart' => 100,
            'timestamp' => now()->toISOString(),
        ])->assertCreated();

        $response->assertJsonPath('ocpp_response.idTagInfo.status', 'Invalid');
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseHas('ocpp_transactions', [
            'status' => 'rejected',
            'rejection_reason' => 'id_tag_invalid',
        ]);
    }

    public function test_emergency_stop_marks_the_session_interrupted_and_public_stop_cannot_fake_completion(): void
    {
        [$station, , $client] = $this->transactionFixture();
        $transactionId = (int) $this->send($station, 'StartTransaction', 'start-emergency', [
            'connectorId' => 1,
            'idTag' => self::ID_TAG,
            'meterStart' => 500000,
            'timestamp' => now()->subMinute()->toISOString(),
        ])->json('ocpp_response.transactionId');
        $session = ChargingSession::query()->sole();

        Sanctum::actingAs($client);
        $this->postJson("/api/charging-sessions/{$session->id}/stop")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session');

        $this->send($station, 'StopTransaction', 'stop-emergency', [
            'transactionId' => $transactionId,
            'meterStop' => 500800,
            'timestamp' => now()->toISOString(),
            'reason' => 'EmergencyStop',
        ])->assertCreated();

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $session->id,
            'status' => 'interrupted',
            'lifecycle_reason' => 'stop_transaction_emergency_stop',
        ]);
    }

    public function test_connection_loss_waits_for_reconciliation_and_meter_values_can_resume_the_session(): void
    {
        [$station, , $client] = $this->transactionFixture();
        $transactionId = (int) $this->send($station, 'StartTransaction', 'start-reconcile', [
            'connectorId' => 1,
            'idTag' => self::ID_TAG,
            'meterStart' => 800000,
            'timestamp' => now()->subMinutes(2)->toISOString(),
        ])->json('ocpp_response.transactionId');

        $this->send($station, 'ConnectionClosed', 'connection-closed', [
            'code' => 1006,
            'reason' => 'network_lost',
        ])->assertCreated();

        $session = ChargingSession::query()->sole();
        $this->assertSame('interrupted', $session->status);
        $this->assertSame('ocpp_connection_lost_awaiting_reconciliation', $session->lifecycle_reason);
        $this->assertNull($session->meter_stop_kwh);
        $this->assertDatabaseHas('ocpp_transactions', [
            'id' => $transactionId,
            'status' => 'awaiting_reconciliation',
        ]);

        Sanctum::actingAs($client);
        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => '30000000-0000-4000-8000-000000000001',
        ])->assertUnprocessable()->assertJsonValidationErrors('session');
        $this->assertDatabaseCount('payments', 0);

        $this->send($station, 'ConnectionOpened', 'connection-reopened', [])->assertCreated();

        $this->send($station, 'MeterValues', 'meter-recovered', [
            'connectorId' => 1,
            'transactionId' => $transactionId,
            'meterValue' => [[
                'timestamp' => now()->toISOString(),
                'sampledValue' => [[
                    'value' => '801200',
                    'measurand' => 'Energy.Active.Import.Register',
                    'unit' => 'Wh',
                ]],
            ]],
        ])->assertCreated();

        $session->refresh();
        $this->assertSame('charging', $session->status);
        $this->assertSame('ocpp_telemetry_recovered', $session->lifecycle_reason);
        $this->assertSame(1.2, $session->energy_kwh);
        $this->assertDatabaseHas('ocpp_transactions', ['id' => $transactionId, 'status' => 'active']);
    }

    /** @return array{Station, Connector, User} */
    private function transactionFixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'OCPP Network',
            'slug' => 'ocpp-network-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'OCPP Transaction Station',
            'reference' => 'CT-OCPP-TX-001',
            'ocpp_identity' => 'CT-OCPP-TX-001',
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Simulator',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
            'ocpp_auth_secret_hash' => Hash::make(self::STATION_SECRET),
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'ocpp_connector_id' => 1,
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        $client = User::factory()->create(['status' => 'active', 'organization_id' => null]);
        $client->assignRole('client');
        OcppIdTag::query()->create([
            'user_id' => $client->id,
            'token_hash' => OcppAuthorizationService::hash(self::ID_TAG),
            'masked_token' => OcppAuthorizationService::mask(self::ID_TAG),
            'label' => 'Test RFID',
            'status' => 'active',
        ]);

        return [$station, $connector, $client];
    }

    /** @param array<string, mixed> $payload */
    private function send(
        Station $station,
        string $action,
        string $messageId,
        array $payload,
        ?string $eventId = null,
    ): TestResponse {
        $body = [
            'event_id' => $eventId ?? (string) Str::uuid(),
            'connection_id' => (string) Str::uuid(),
            'station_identity' => $station->ocpp_identity,
            'message_id' => $messageId,
            'protocol_version' => '1.6',
            'action' => $action,
            'payload' => $payload,
            'occurred_at' => now()->toISOString(),
        ];
        $timestamp = now()->timestamp;
        $requestId = (string) Str::uuid();
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$requestId.'.'.$json, self::GATEWAY_SECRET);

        return $this->call('POST', '/api/internal/ocpp/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHARGETRACKR_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CHARGETRACKR_REQUEST_ID' => $requestId,
            'HTTP_X_CHARGETRACKR_SIGNATURE' => $signature,
        ], $json);
    }
}
