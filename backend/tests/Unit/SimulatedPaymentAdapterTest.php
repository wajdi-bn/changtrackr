<?php

namespace Tests\Unit;

use App\Data\PaymentCharge;
use App\Services\Payments\SimulatedPaymentAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimulatedPaymentAdapterTest extends TestCase
{
    public function test_successful_charge_returns_a_simulated_transaction(): void
    {
        $result = (new SimulatedPaymentAdapter)->charge($this->charge('success'));

        $this->assertTrue($result->successful);
        $this->assertStringStartsWith('SIM-CHG-', (string) $result->transactionId);
    }

    #[DataProvider('failureScenarios')]
    public function test_charge_preserves_each_simulated_failure_scenario(string $outcome, string $reason, string $errorCode, bool $retryable): void
    {
        $result = (new SimulatedPaymentAdapter)->charge($this->charge($outcome));

        $this->assertFalse($result->successful);
        $this->assertSame($reason, $result->failureReason);
        $this->assertSame($errorCode, $result->metadata['error_code']);
        $this->assertSame($retryable, $result->metadata['retryable']);
    }

    /** @return array<string, array{string, string, string, bool}> */
    public static function failureScenarios(): array
    {
        return [
            'declined' => ['declined', 'Simulated provider decline', 'payment_declined', false],
            'timeout' => ['timeout', 'Simulated provider timeout', 'provider_timeout', true],
            'provider error' => ['provider_error', 'Simulated provider unavailable', 'provider_error', true],
        ];
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
