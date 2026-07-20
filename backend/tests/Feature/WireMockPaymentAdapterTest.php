<?php

namespace Tests\Feature;

use App\Data\PaymentCharge;
use App\Services\Payments\PaymentWebhookSignature;
use App\Services\Payments\WireMockPaymentAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WireMockPaymentAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payments.simulator.base_url', 'http://payment-simulator.test');
        config()->set('payments.simulator.operation_endpoint', '/v1/payment-operations');
        config()->set('payments.simulator.api_key', 'test-api-key');
        config()->set('payments.simulator.webhook_url', 'http://backend.test/api/internal/payments/webhooks');
        config()->set('payments.simulator.webhook_secret', 'test-webhook-secret');
        config()->set('payments.simulator.timeout_seconds', 2);
        config()->set('payments.simulator.retries', 0);
    }

    public function test_it_authorizes_through_the_external_sandbox_contract(): void
    {
        Http::fake([
            'payment-simulator.test/*' => Http::response([
                'event_id' => 'evt_authorize_10000000-0000-4000-8000-000000000001',
                'status' => 'authorized',
                'provider_transaction_id' => 'sim_auth_10000000-0000-4000-8000-000000000001',
            ]),
        ]);

        $charge = $this->charge('success');
        $result = $this->adapter()->authorize($charge);

        $this->assertTrue($result->successful);
        $this->assertSame('sim_auth_10000000-0000-4000-8000-000000000001', $result->transactionId);
        $this->assertSame('external_sandbox', $result->metadata['mode']);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $event = [
                'event_id' => $payload['event_id'],
                'type' => $payload['event_type'],
                'operation' => $payload['operation'],
                'status' => $payload['provider_status'],
                'payment_reference' => $payload['payment_reference'],
                'provider_transaction_id' => $payload['provider_transaction_id'],
                'authorization_id' => $payload['authorization_id'],
                'amount_millimes' => $payload['amount_millimes'],
                'currency' => $payload['currency'],
                'idempotency_key' => $payload['idempotency_key'],
            ];

            return $request->url() === 'http://payment-simulator.test/v1/payment-operations'
                && $request->hasHeader('X-Simulator-Api-Key', 'test-api-key')
                && $payload['simulation_outcome'] === 'success'
                && app(PaymentWebhookSignature::class)->verify($event, $payload['webhook_signature']);
        });
    }

    public function test_it_exposes_a_provider_decline_as_a_business_failure(): void
    {
        Http::fake([
            'payment-simulator.test/*' => Http::response([
                'status' => 'declined',
                'provider_transaction_id' => 'sim_chg_declined',
                'error' => [
                    'code' => 'insufficient_funds',
                    'message' => 'The simulated provider declined the payment.',
                ],
            ], 422),
        ]);

        $result = $this->adapter()->charge($this->charge('declined'));

        $this->assertFalse($result->successful);
        $this->assertSame('insufficient_funds', $result->metadata['error_code']);
        $this->assertFalse($result->metadata['retryable']);
    }

    public function test_it_exposes_provider_unavailability_as_retryable(): void
    {
        Http::fake([
            'payment-simulator.test/*' => Http::response([
                'status' => 'unavailable',
                'error' => [
                    'code' => 'provider_unavailable',
                    'message' => 'The simulated payment provider is temporarily unavailable.',
                ],
            ], 503),
        ]);

        $result = $this->adapter()->charge($this->charge('provider_error'));

        $this->assertFalse($result->successful);
        $this->assertSame('provider_unavailable', $result->metadata['error_code']);
        $this->assertTrue($result->metadata['retryable']);
    }

    private function adapter(): WireMockPaymentAdapter
    {
        return app(WireMockPaymentAdapter::class);
    }

    private function charge(string $outcome): PaymentCharge
    {
        return new PaymentCharge(
            paymentReference: 'PAY-TEST-001',
            amountMillimes: 12500,
            currency: 'TND',
            method: 'simulated_card',
            idempotencyKey: '10000000-0000-4000-8000-000000000001',
            simulationOutcome: $outcome,
        );
    }
}
