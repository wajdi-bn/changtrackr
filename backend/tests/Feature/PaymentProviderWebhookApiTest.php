<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\ChargingPlan;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanSubscriptionInvoice;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\PaymentWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProviderWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payments.simulator.webhook_secret', 'test-webhook-secret');
    }

    public function test_a_signed_settlement_webhook_is_processed_once(): void
    {
        [$payment, $station] = $this->pendingPayment();
        $payload = $this->payload($payment);
        $signature = app(PaymentWebhookSignature::class)->sign($payload);

        $this->withHeader('X-ChargeTrackr-Signature', $signature)
            ->postJson('/api/internal/payments/webhooks', $payload)
            ->assertAccepted()
            ->assertJsonPath('processing_status', 'processed')
            ->assertJsonPath('duplicate', false);

        $this->withHeader('X-ChargeTrackr-Signature', $signature)
            ->postJson('/api/internal/payments/webhooks', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('payment_provider_events', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'provider_transaction_id' => $payload['provider_transaction_id'],
        ]);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $payment->charging_session_id,
            'payment_status' => 'paid',
        ]);
        $this->assertSame(12.5, (float) $station->fresh()->revenue_today);
    }

    public function test_an_invalid_signature_is_rejected_without_persisting_the_event(): void
    {
        [$payment] = $this->pendingPayment();

        $this->withHeader('X-ChargeTrackr-Signature', 'invalid')
            ->postJson('/api/internal/payments/webhooks', $this->payload($payment))
            ->assertUnauthorized();

        $this->assertDatabaseCount('payment_provider_events', 0);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_a_signed_plan_invoice_webhook_is_reconciled_without_a_charging_session(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Membership Network',
            'slug' => 'membership-network',
            'status' => 'active',
        ]);
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $plan = ChargingPlan::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Member',
            'code' => 'MEMBER',
            'monthly_fee_millimes' => 19000,
            'discount_basis_points' => 800,
            'audience' => 'Drivers',
            'status' => 'active',
        ]);
        $invoice = PlanSubscriptionInvoice::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $client->id,
            'charging_plan_id' => $plan->id,
            'reference' => 'CPS-WEBHOOK-001',
            'status' => 'pending',
            'billing_reason' => 'initial',
            'payment_provider' => 'wiremock',
            'payment_method' => 'simulated_card',
            'idempotency_key' => '30000000-0000-4000-8000-000000000001',
            'amount_millimes' => 19000,
            'currency' => 'TND',
            'period_starts_at' => now(),
            'period_ends_at' => now()->addMonth(),
            'due_at' => now(),
        ]);
        $payload = [
            'event_id' => 'evt_charge_30000000-0000-4000-8000-000000000001',
            'type' => 'payment.charge.paid',
            'operation' => 'charge',
            'status' => 'paid',
            'payment_reference' => $invoice->reference,
            'provider_transaction_id' => 'sim_chg_30000000-0000-4000-8000-000000000001',
            'authorization_id' => '',
            'amount_millimes' => 19000,
            'currency' => 'TND',
            'idempotency_key' => '30000000-0000-4000-8000-000000000001',
        ];
        $signature = app(PaymentWebhookSignature::class)->sign($payload);

        $this->withHeader('X-ChargeTrackr-Signature', $signature)
            ->postJson('/api/internal/payments/webhooks', $payload)
            ->assertAccepted()
            ->assertJsonPath('processing_status', 'processed');

        $this->assertDatabaseHas('plan_subscription_invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'provider_transaction_id' => 'sim_chg_30000000-0000-4000-8000-000000000001',
        ]);
        $this->assertDatabaseHas('payment_provider_events', [
            'plan_subscription_invoice_id' => $invoice->id,
            'processing_status' => 'processed',
        ]);
    }

    /** @return array{Payment, Station} */
    private function pendingPayment(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Webhook Network',
            'slug' => 'webhook-network',
            'status' => 'active',
        ]);
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Webhook Station',
            'reference' => 'CT-WEBHOOK-001',
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
            'revenue_today' => 0,
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        $session = ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'reference' => 'SES-WEBHOOK-001',
            'source' => 'simulated',
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => $connector->external_id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
            'duration_seconds' => 1200,
            'meter_start_kwh' => 10,
            'meter_stop_kwh' => 20,
            'energy_kwh' => 10,
            'price_per_kwh_millimes' => 1200,
            'session_fee_millimes' => 500,
            'total_millimes' => 12500,
            'currency' => 'TND',
        ]);
        $payment = Payment::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $client->id,
            'charging_session_id' => $session->id,
            'reference' => 'PAY-WEBHOOK-001',
            'provider' => 'wiremock',
            'method' => 'simulated_card',
            'status' => 'pending',
            'amount_millimes' => 12500,
            'currency' => 'TND',
            'idempotency_key' => '20000000-0000-4000-8000-000000000001',
        ]);

        return [$payment, $station];
    }

    /** @return array<string, mixed> */
    private function payload(Payment $payment): array
    {
        return [
            'event_id' => 'evt_charge_20000000-0000-4000-8000-000000000001',
            'type' => 'payment.charge.paid',
            'operation' => 'charge',
            'status' => 'paid',
            'payment_reference' => $payment->reference,
            'provider_transaction_id' => 'sim_chg_20000000-0000-4000-8000-000000000001',
            'authorization_id' => '',
            'amount_millimes' => 12500,
            'currency' => 'TND',
            'idempotency_key' => '20000000-0000-4000-8000-000000000001',
        ];
    }
}
