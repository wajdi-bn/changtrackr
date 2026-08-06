<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Data\PaymentResult;
use App\Models\Alert;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppEvent;
use App\Models\OcppIdTag;
use App\Models\OcppTransaction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\OrphanedAuthorizationReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrphanedAuthorizationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('payments.orphan_reconciliation_grace_hours', 24);
        config()->set('payments.orphan_meter_max_age_minutes', 15);
        config()->set('payments.orphan_reconciliation_retry_minutes', 15);
        config()->set('payments.orphan_reconciliation_batch_size', 100);
    }

    public function test_command_captures_an_orphaned_authorization_from_a_recent_consistent_meter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));
        $fixture = $this->orphanedSession('CAPTURE', now()->subHours(25), 103500, now()->subHours(25)->subMinutes(5));

        $this->artisan('payments:reconcile-orphan-authorizations')
            ->expectsOutputToContain('1 captured, 0 released, 0 failed')
            ->assertSuccessful();

        $session = $fixture['session']->fresh();
        $attempt = $fixture['attempt']->fresh();
        $payment = Payment::query()->where('charging_session_id', $session->id)->sole();
        $this->assertSame('interrupted', $session->status);
        $this->assertSame('paid', $session->payment_status);
        $this->assertSame('ocpp_orphan_authorization_captured', $session->lifecycle_reason);
        $this->assertSame(103.5, $session->meter_stop_kwh);
        $this->assertSame(3.5, $session->energy_kwh);
        $this->assertSame(2000, $session->total_millimes);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('captured', $attempt->payment_status);
        $this->assertSame('capture', $attempt->reconciliation_action);
        $this->assertSame('completed', $attempt->reconciliation_status);
        $this->assertNotNull($attempt->reconciled_at);
        $this->assertSame('reconciled', $fixture['transaction']->fresh()->status);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_missing_meter_releases_funds_and_opens_one_scoped_alert_idempotently(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));
        $fixture = $this->orphanedSession('RELEASE', now()->subHours(25));
        $recentOtherTenant = $this->orphanedSession('OTHER', now()->subHours(2));

        $first = app(OrphanedAuthorizationReconciliationService::class)->scan();
        $second = app(OrphanedAuthorizationReconciliationService::class)->scan();

        $this->assertSame(['captured' => 0, 'released' => 1, 'failed' => 0, 'skipped' => 0], $first);
        $this->assertSame(['captured' => 0, 'released' => 0, 'failed' => 0, 'skipped' => 0], $second);
        $attempt = $fixture['attempt']->fresh();
        $session = $fixture['session']->fresh();
        $this->assertSame('released', $attempt->payment_status);
        $this->assertSame('release', $attempt->reconciliation_action);
        $this->assertSame('completed', $attempt->reconciliation_status);
        $this->assertNotNull($attempt->release_idempotency_key);
        $this->assertSame('released', $session->payment_status);
        $this->assertSame('ocpp_orphan_authorization_released_without_reliable_meter', $session->lifecycle_reason);
        $this->assertSame('reconciled_without_meter', $fixture['transaction']->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('alerts', 1);
        $alert = Alert::query()->sole();
        $this->assertSame($fixture['organization']->id, $alert->organization_id);
        $this->assertSame($fixture['station']->id, $alert->station_id);
        $this->assertSame('payment_reconciliation', $alert->source);
        $this->assertSame('authorized', $recentOtherTenant['attempt']->fresh()->payment_status);
        $this->assertDatabaseMissing('alerts', ['organization_id' => $recentOtherTenant['organization']->id]);
    }

    public function test_stale_meter_is_not_used_after_the_grace_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));
        $endedAt = now()->subHours(23);
        $fixture = $this->orphanedSession('STALE', $endedAt, 105000, $endedAt->copy()->subMinutes(30));

        $this->assertSame(
            ['captured' => 0, 'released' => 0, 'failed' => 0, 'skipped' => 0],
            app(OrphanedAuthorizationReconciliationService::class)->scan(),
        );

        $this->travel(2)->hours();
        $result = app(OrphanedAuthorizationReconciliationService::class)->scan();

        $this->assertSame(1, $result['released']);
        $this->assertSame('release', $fixture['attempt']->fresh()->reconciliation_action);
        $this->assertNull($fixture['session']->fresh()->meter_stop_kwh);
    }

    public function test_failed_release_is_retried_after_the_configured_delay_with_the_same_key(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));
        $fixture = $this->orphanedSession('RETRY', now()->subHours(25));
        $gateway = new FlakyReleasePaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $first = app(OrphanedAuthorizationReconciliationService::class)->scan();
        $releaseKey = $fixture['attempt']->fresh()->release_idempotency_key;
        $immediate = app(OrphanedAuthorizationReconciliationService::class)->scan();
        $this->travel(16)->minutes();
        $retried = app(OrphanedAuthorizationReconciliationService::class)->scan();

        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, $immediate['released']);
        $this->assertSame(1, $retried['released']);
        $this->assertSame(2, $gateway->releaseCalls);
        $this->assertSame([$releaseKey, $releaseKey], $gateway->releaseKeys);
        $this->assertSame('released', $fixture['attempt']->fresh()->payment_status);
        $this->assertSame('completed', $fixture['attempt']->fresh()->reconciliation_status);
    }

    /** @return array{organization:Organization,station:Station,transaction:OcppTransaction,session:ChargingSession,attempt:ChargingAttempt} */
    private function orphanedSession(
        string $suffix,
        \DateTimeInterface $endedAt,
        ?int $lastMeterWh = null,
        ?\DateTimeInterface $lastMeterAt = null,
    ): array {
        $organization = Organization::query()->create([
            'name' => "Reconciliation {$suffix}",
            'slug' => 'reconciliation-'.Str::lower($suffix),
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => "Station {$suffix}",
            'reference' => "CT-REC-{$suffix}",
            'ocpp_identity' => "CT-REC-{$suffix}",
            'location_name' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'offline',
            'max_power_kw' => 120,
            'model' => 'Simulator',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
            'ocpp_auth_secret_hash' => Hash::make('station-secret'),
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'ocpp_connector_id' => 1,
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'offline',
            'ocpp_status' => 'Unavailable',
        ]);
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');
        $idTag = OcppIdTag::query()->create([
            'user_id' => $client->id,
            'token_hash' => hash('sha256', "TAG-{$suffix}"),
            'token_ciphertext' => "TAG-{$suffix}",
            'masked_token' => "TAG-***-{$suffix}",
            'kind' => 'virtual_app',
            'status' => 'active',
        ]);
        $event = OcppEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'connection_id' => (string) Str::uuid(),
            'message_id' => "start-{$suffix}",
            'protocol_version' => '1.6',
            'action' => 'StartTransaction',
            'payload' => ['connectorId' => 1],
            'payload_hash' => hash('sha256', "start-{$suffix}"),
            'processing_status' => 'processed',
            'occurred_at' => now()->subHours(26),
            'received_at' => now()->subHours(26),
        ]);
        $transaction = OcppTransaction::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'ocpp_id_tag_id' => $idTag->id,
            'start_event_id' => $event->id,
            'id_tag_hash' => $idTag->token_hash,
            'id_tag_masked' => $idTag->masked_token,
            'status' => 'awaiting_reconciliation',
            'meter_start_wh' => 100000,
            'last_meter_wh' => $lastMeterWh,
            'started_at' => now()->subHours(26),
            'last_meter_value_at' => $lastMeterAt,
            'stop_reason' => 'communication_timeout',
        ]);
        $session = ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'ocpp_transaction_id' => $transaction->id,
            'reference' => "SES-REC-{$suffix}",
            'source' => 'ocpp',
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => $connector->external_id,
            'status' => 'interrupted',
            'lifecycle_reason' => 'ocpp_connection_lost_awaiting_reconciliation',
            'payment_status' => 'authorized',
            'started_at' => now()->subHours(26),
            'ended_at' => $endedAt,
            'duration_seconds' => 3600,
            'meter_start_kwh' => 100,
            'last_meter_value_at' => $lastMeterAt,
            'energy_kwh' => $lastMeterWh === null ? 0 : ($lastMeterWh - 100000) / 1000,
            'price_per_kwh_millimes' => 500,
            'session_fee_millimes' => 250,
            'minimum_charge_millimes' => 0,
            'total_millimes' => 0,
            'currency' => 'TND',
        ]);
        $attempt = ChargingAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'ocpp_id_tag_id' => $idTag->id,
            'charging_session_id' => $session->id,
            'status' => 'charging',
            'payment_provider' => 'simulated',
            'payment_method' => 'simulated_card',
            'payment_status' => 'authorized',
            'preauthorized_amount_millimes' => 30000,
            'currency' => 'TND',
            'payment_idempotency_key' => (string) Str::uuid(),
            'capture_idempotency_key' => (string) Str::uuid(),
            'release_idempotency_key' => (string) Str::uuid(),
            'provider_authorization_id' => "SIM-AUTH-{$suffix}",
            'simulation_outcome' => 'success',
            'authorized_at' => now()->subHours(26),
            'started_at' => now()->subHours(26),
        ]);

        return compact('organization', 'station', 'transaction', 'session', 'attempt');
    }
}

final class FlakyReleasePaymentGateway implements PaymentGateway
{
    public int $releaseCalls = 0;

    /** @var list<string> */
    public array $releaseKeys = [];

    public function name(): string
    {
        return 'flaky-test';
    }

    public function authorize(PaymentCharge $charge): PaymentResult
    {
        return new PaymentResult(true, 'AUTH-TEST');
    }

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult
    {
        return new PaymentResult(true, 'CAPTURE-TEST');
    }

    public function release(string $authorizationId, string $idempotencyKey): PaymentResult
    {
        $this->releaseCalls++;
        $this->releaseKeys[] = $idempotencyKey;

        return $this->releaseCalls === 1
            ? new PaymentResult(false, failureReason: 'Provider temporarily unavailable')
            : new PaymentResult(true, 'RELEASE-TEST');
    }

    public function charge(PaymentCharge $charge): PaymentResult
    {
        return new PaymentResult(true, 'CHARGE-TEST');
    }
}
