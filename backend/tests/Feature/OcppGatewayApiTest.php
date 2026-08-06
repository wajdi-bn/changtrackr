<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\OcppEvent;
use App\Models\Organization;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OcppGatewayApiTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY_SECRET = 'gateway-test-secret-0123456789abcdef0123456789abcdef';

    private const STATION_SECRET = 'station-test-secret-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ocpp.gateway.shared_secret', self::GATEWAY_SECRET);
        config()->set('ocpp.gateway.signature_tolerance_seconds', 300);
    }

    public function test_internal_routes_require_a_fresh_valid_non_replayed_signature(): void
    {
        $payload = [
            'station_identity' => 'CT-OCPP-001',
            'username' => 'CT-OCPP-001',
            'password' => self::STATION_SECRET,
            'protocol_version' => '1.6',
        ];

        $this->postJson('/api/internal/ocpp/authenticate', $payload)->assertUnauthorized();
        $this->signedPost('/api/internal/ocpp/authenticate', $payload, timestamp: now()->subMinutes(10)->timestamp)
            ->assertUnauthorized();

        $requestId = (string) Str::uuid();
        $this->signedPost('/api/internal/ocpp/authenticate', $payload, requestId: $requestId)
            ->assertUnauthorized();
        $this->signedPost('/api/internal/ocpp/authenticate', $payload, requestId: $requestId)
            ->assertUnauthorized();
    }

    public function test_gateway_authenticates_only_a_provisioned_active_ocpp_16_station(): void
    {
        $station = $this->station();

        $this->signedPost('/api/internal/ocpp/authenticate', [
            'station_identity' => $station->ocpp_identity,
            'username' => $station->ocpp_identity,
            'password' => self::STATION_SECRET,
            'protocol_version' => '1.6',
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('station.identity', 'CT-OCPP-001');

        $this->signedPost('/api/internal/ocpp/authenticate', [
            'station_identity' => $station->ocpp_identity,
            'username' => $station->ocpp_identity,
            'password' => 'incorrect-secret-that-is-long-enough',
            'protocol_version' => '1.6',
        ])->assertUnauthorized();
    }

    public function test_heartbeat_is_persisted_and_triggers_the_business_projection(): void
    {
        $station = $this->station();
        $event = $this->eventPayload($station, 'Heartbeat', 'heartbeat-001', []);

        $this->signedPost('/api/internal/ocpp/events', $event)
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('processing_status', 'applied');

        $stored = OcppEvent::query()->sole();
        $station->refresh();

        $this->assertSame($station->organization_id, $stored->organization_id);
        $this->assertSame('unavailable', $station->status);
        $this->assertSame('no_connectors', $station->availability_reason);
        $this->assertNotNull($station->last_heartbeat_at);
        $this->assertNotNull($station->ocpp_last_message_at);
    }

    public function test_status_notification_updates_raw_telemetry_and_business_projection(): void
    {
        $station = $this->station();
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'ocpp_connector_id' => 1,
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        $event = $this->eventPayload($station, 'StatusNotification', 'status-001', [
            'connectorId' => 1,
            'errorCode' => 'NoError',
            'status' => 'Charging',
            'timestamp' => now()->toISOString(),
        ]);

        $this->signedPost('/api/internal/ocpp/events', $event)->assertCreated();

        $connector->refresh();
        $station->refresh();
        $this->assertSame('charging', $connector->status);
        $this->assertSame('Charging', $connector->ocpp_status);
        $this->assertSame('NoError', $connector->ocpp_error_code);
        $this->assertSame('charging', $station->status);
    }

    public function test_events_are_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        $station = $this->station();
        $event = $this->eventPayload($station, 'Heartbeat', 'heartbeat-idempotent', []);

        $this->signedPost('/api/internal/ocpp/events', $event)->assertCreated();
        $this->signedPost('/api/internal/ocpp/events', $event)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('ocpp_events', 1);

        $conflict = $event;
        $conflict['payload'] = ['unexpected' => true];
        $this->signedPost('/api/internal/ocpp/events', $conflict)->assertConflict();
        $this->assertDatabaseCount('ocpp_events', 1);
    }

    public function test_unmapped_connector_event_is_audited_without_creating_or_mutating_a_connector(): void
    {
        $station = $this->station();
        $event = $this->eventPayload($station, 'StatusNotification', 'status-unmapped', [
            'connectorId' => 99,
            'errorCode' => 'ConnectorLockFailure',
            'status' => 'Faulted',
        ]);

        $this->signedPost('/api/internal/ocpp/events', $event)
            ->assertCreated()
            ->assertJsonPath('processing_status', 'ignored');

        $this->assertDatabaseCount('connectors', 0);
        $this->assertDatabaseHas('ocpp_events', [
            'event_id' => $event['event_id'],
            'processing_status' => 'ignored',
        ]);
    }

    public function test_event_rate_limit_is_isolated_by_station_identity(): void
    {
        config()->set('ocpp.gateway.rate_limits.events_per_minute', 2);
        $firstStation = $this->station('CT-OCPP-RATE-001');
        $secondStation = $this->station('CT-OCPP-RATE-002');

        $this->signedPost(
            '/api/internal/ocpp/events',
            $this->eventPayload($firstStation, 'Heartbeat', 'rate-event-001', []),
        )->assertCreated();
        $this->signedPost(
            '/api/internal/ocpp/events',
            $this->eventPayload($firstStation, 'Heartbeat', 'rate-event-002', []),
        )->assertCreated();
        $this->signedPost(
            '/api/internal/ocpp/events',
            $this->eventPayload($firstStation, 'Heartbeat', 'rate-event-003', []),
        )->assertTooManyRequests()->assertHeader('Retry-After');

        $this->signedPost(
            '/api/internal/ocpp/events',
            $this->eventPayload($secondStation, 'Heartbeat', 'rate-event-004', []),
        )->assertCreated();
    }

    public function test_authentication_and_event_limits_use_independent_buckets(): void
    {
        config()->set('ocpp.gateway.rate_limits.authenticate_per_minute', 1);
        config()->set('ocpp.gateway.rate_limits.events_per_minute', 1);
        $station = $this->station('CT-OCPP-RATE-AUTH');
        $credentials = [
            'station_identity' => $station->ocpp_identity,
            'username' => $station->ocpp_identity,
            'password' => self::STATION_SECRET,
            'protocol_version' => '1.6',
        ];

        $this->signedPost('/api/internal/ocpp/authenticate', $credentials)->assertOk();
        $this->signedPost('/api/internal/ocpp/authenticate', $credentials)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->signedPost(
            '/api/internal/ocpp/events',
            $this->eventPayload($station, 'Heartbeat', 'rate-auth-event', []),
        )->assertCreated();
    }

    public function test_command_polling_limit_is_isolated_by_station_identity(): void
    {
        config()->set('ocpp.gateway.rate_limits.command_poll_per_minute', 1);
        $firstStation = $this->station('CT-OCPP-POLL-001');
        $secondStation = $this->station('CT-OCPP-POLL-002');

        $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $firstStation->ocpp_identity,
            'connection_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $firstStation->ocpp_identity,
            'connection_id' => (string) Str::uuid(),
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->signedPost('/api/internal/ocpp/commands/claim', [
            'station_identity' => $secondStation->ocpp_identity,
            'connection_id' => (string) Str::uuid(),
        ])->assertOk();
    }

    private function station(string $identity = 'CT-OCPP-001'): Station
    {
        $organization = Organization::query()->create([
            'name' => 'OCPP Network',
            'slug' => 'ocpp-network-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);

        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => "OCPP Test Station {$identity}",
            'reference' => $identity,
            'ocpp_identity' => $identity,
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
    }

    /** @param array<string, mixed> $payload */
    private function eventPayload(
        Station $station,
        string $action,
        string $messageId,
        array $payload,
    ): array {
        return [
            'event_id' => (string) Str::uuid(),
            'connection_id' => (string) Str::uuid(),
            'station_identity' => $station->ocpp_identity,
            'message_id' => $messageId,
            'protocol_version' => '1.6',
            'action' => $action,
            'payload' => $payload,
            'occurred_at' => now()->toISOString(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(
        string $uri,
        array $payload,
        ?int $timestamp = null,
        ?string $requestId = null,
    ): TestResponse {
        $timestamp ??= now()->timestamp;
        $requestId ??= (string) Str::uuid();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = 'v1='.hash_hmac(
            'sha256',
            $timestamp.'.'.$requestId.'.'.$body,
            self::GATEWAY_SECRET,
        );

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHARGETRACKR_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CHARGETRACKR_REQUEST_ID' => $requestId,
            'HTTP_X_CHARGETRACKR_SIGNATURE' => $signature,
        ], $body);
    }
}
