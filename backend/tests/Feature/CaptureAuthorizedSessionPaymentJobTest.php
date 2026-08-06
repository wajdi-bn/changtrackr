<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Data\PaymentResult;
use App\Exceptions\RetryablePaymentCaptureException;
use App\Jobs\CaptureAuthorizedSessionPayment;
use App\Models\Alert;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\PaymentWebhookSignature;
use App\Services\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaptureAuthorizedSessionPaymentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('payments.simulator.webhook_secret', 'capture-recovery-webhook-secret');
    }

    public function test_retryable_capture_failure_is_retried_then_marked_for_manual_reconciliation(): void
    {
        $fixture = $this->authorizedSession('RETRY');
        $gateway = new CaptureRecoveryPaymentGateway(retryable: true);
        $this->app->instance(PaymentGateway::class, $gateway);
        $job = new CaptureAuthorizedSessionPayment($fixture['session']->id);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $fixture['session']->id, $job->uniqueId());

        try {
            $job->handle(app(PaymentService::class));
            $this->fail('A retryable provider failure must fail the queued attempt.');
        } catch (RetryablePaymentCaptureException $exception) {
            $job->failed($exception);
        }

        $attempt = $fixture['attempt']->fresh();
        $this->assertSame('reconciliation_required', $attempt->status);
        $this->assertSame('capture_failed', $attempt->payment_status);
        $this->assertSame('capture', $attempt->reconciliation_action);
        $this->assertSame('requires_review', $attempt->reconciliation_status);
        $this->assertSame('capture_retries_exhausted', $attempt->reconciliation_reason);
        $this->assertSame('failed', $fixture['session']->fresh()->payment_status);
        $this->assertSame(0, $gateway->releaseCalls);

        $alert = Alert::query()->sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('payment_reconciliation', $alert->source);
        $this->assertSame($fixture['organization']->id, $alert->organization_id);

        $job->failed(null);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame(0, $gateway->releaseCalls);
    }

    public function test_definitive_capture_decline_is_not_retried_or_escalated_as_technical_failure(): void
    {
        $fixture = $this->authorizedSession('DECLINED');
        $gateway = new CaptureRecoveryPaymentGateway(retryable: false);
        $this->app->instance(PaymentGateway::class, $gateway);

        (new CaptureAuthorizedSessionPayment($fixture['session']->id))
            ->handle(app(PaymentService::class));

        $this->assertSame('capture_failed', $fixture['attempt']->fresh()->payment_status);
        $this->assertSame('completed', $fixture['attempt']->fresh()->status);
        $this->assertSame('failed', $fixture['session']->fresh()->payment_status);
        $this->assertDatabaseCount('alerts', 0);
        $this->assertSame(0, $gateway->releaseCalls);
    }

    public function test_late_successful_capture_webhook_resolves_the_reconciliation_alert(): void
    {
        $fixture = $this->authorizedSession('LATE');
        $gateway = new CaptureRecoveryPaymentGateway(retryable: true);
        $this->app->instance(PaymentGateway::class, $gateway);
        $job = new CaptureAuthorizedSessionPayment($fixture['session']->id);

        try {
            $job->handle(app(PaymentService::class));
            $this->fail('A retryable provider failure must fail the queued attempt.');
        } catch (RetryablePaymentCaptureException $exception) {
            $job->failed($exception);
        }

        $payment = Payment::query()->where('charging_session_id', $fixture['session']->id)->sole();
        $payload = [
            'event_id' => 'evt_capture_late_'.Str::uuid(),
            'type' => 'payment.capture.captured',
            'operation' => 'capture',
            'status' => 'captured',
            'payment_reference' => $payment->reference,
            'provider_transaction_id' => 'CAPTURE-LATE-SUCCESS',
            'authorization_id' => $fixture['attempt']->provider_authorization_id,
            'amount_millimes' => $payment->amount_millimes,
            'currency' => $payment->currency,
            'idempotency_key' => $payment->idempotency_key,
        ];
        $signature = app(PaymentWebhookSignature::class)->sign($payload);

        $this->withHeader('X-ChargeTrackr-Signature', $signature)
            ->postJson('/api/internal/payments/webhooks', $payload)
            ->assertAccepted()
            ->assertJsonPath('processing_status', 'processed');

        $attempt = $fixture['attempt']->fresh();
        $this->assertSame('captured', $attempt->payment_status);
        $this->assertSame('completed', $attempt->status);
        $this->assertSame('completed', $attempt->reconciliation_status);
        $this->assertNull($attempt->failure_code);
        $this->assertSame('paid', $fixture['session']->fresh()->payment_status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('resolved', Alert::query()->sole()->status);
        $this->assertSame(0, $fixture['station']->fresh()->open_alerts_count);
        $this->assertSame(0, $gateway->releaseCalls);
    }

    /** @return array{organization:Organization,station:Station,session:ChargingSession,attempt:ChargingAttempt} */
    private function authorizedSession(string $suffix): array
    {
        $organization = Organization::query()->create([
            'name' => "Capture Recovery {$suffix}",
            'slug' => 'capture-recovery-'.Str::lower($suffix),
            'status' => 'active',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => "Recovery Station {$suffix}",
            'reference' => "CT-CAP-{$suffix}",
            'location_name' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Simulator',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
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
        ]);
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');
        $session = ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'reference' => "SES-CAP-{$suffix}",
            'source' => 'ocpp',
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => $connector->external_id,
            'status' => 'completed',
            'payment_status' => 'authorized',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'duration_seconds' => 3600,
            'meter_start_kwh' => 100,
            'meter_stop_kwh' => 112.5,
            'energy_kwh' => 12.5,
            'price_per_kwh_millimes' => 500,
            'session_fee_millimes' => 250,
            'minimum_charge_millimes' => 0,
            'total_millimes' => 6500,
            'currency' => 'TND',
        ]);
        $attempt = ChargingAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'charging_session_id' => $session->id,
            'status' => 'charging',
            'payment_provider' => 'capture-recovery-test',
            'payment_method' => 'simulated_card',
            'payment_status' => 'authorized',
            'preauthorized_amount_millimes' => 30000,
            'currency' => 'TND',
            'payment_idempotency_key' => (string) Str::uuid(),
            'capture_idempotency_key' => (string) Str::uuid(),
            'release_idempotency_key' => (string) Str::uuid(),
            'provider_authorization_id' => "AUTH-CAP-{$suffix}",
            'simulation_outcome' => 'success',
            'authorized_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);

        return compact('organization', 'station', 'session', 'attempt');
    }
}

final class CaptureRecoveryPaymentGateway implements PaymentGateway
{
    public int $releaseCalls = 0;

    public function __construct(private readonly bool $retryable) {}

    public function name(): string
    {
        return 'capture-recovery-test';
    }

    public function authorize(PaymentCharge $charge): PaymentResult
    {
        return new PaymentResult(true, 'AUTH-RECOVERY');
    }

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult
    {
        return new PaymentResult(
            successful: false,
            failureReason: $this->retryable ? 'Provider temporarily unavailable.' : 'Card authorization declined.',
            metadata: [
                'error_code' => $this->retryable ? 'provider_unavailable' : 'payment_declined',
                'retryable' => $this->retryable,
            ],
        );
    }

    public function release(string $authorizationId, string $idempotencyKey): PaymentResult
    {
        $this->releaseCalls++;

        return new PaymentResult(true, 'RELEASE-RECOVERY');
    }

    public function charge(PaymentCharge $charge): PaymentResult
    {
        return new PaymentResult(true, 'CHARGE-RECOVERY');
    }
}
