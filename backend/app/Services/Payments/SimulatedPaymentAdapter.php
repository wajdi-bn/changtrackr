<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Data\PaymentResult;
use Illuminate\Support\Str;

class SimulatedPaymentAdapter implements PaymentGateway
{
    public function name(): string
    {
        return 'simulated';
    }

    public function charge(PaymentCharge $charge): PaymentResult
    {
        if ($failure = $this->failure($charge)) {
            return $failure;
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-CHG-'.Str::upper(Str::random(10)),
            metadata: $this->metadata($charge, 'charge'),
        );
    }

    public function authorize(PaymentCharge $charge): PaymentResult
    {
        if ($failure = $this->failure($charge)) {
            return $failure;
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-AUTH-'.Str::upper(Str::random(10)),
            metadata: $this->metadata($charge, 'authorize'),
        );
    }

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult
    {
        if ($failure = $this->failure($charge)) {
            return $failure;
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-CAP-'.Str::upper(Str::random(10)),
            metadata: [...$this->metadata($charge, 'capture'), 'authorization_id' => $authorizationId],
        );
    }

    public function release(string $authorizationId, string $idempotencyKey): PaymentResult
    {
        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-REL-'.Str::upper(Str::random(10)),
            metadata: [
                'mode' => 'sandbox',
                'operation' => 'release',
                'authorization_id' => $authorizationId,
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    private function failure(PaymentCharge $charge): ?PaymentResult
    {
        $failure = match ($charge->simulationOutcome) {
            'declined' => ['Simulated provider decline', 'payment_declined', false],
            'timeout' => ['Simulated provider timeout', 'provider_timeout', true],
            'provider_error' => ['Simulated provider unavailable', 'provider_error', true],
            default => null,
        };

        return $failure !== null
            ? new PaymentResult(
                successful: false,
                failureReason: $failure[0],
                metadata: [
                    ...$this->metadata($charge, $charge->simulationOutcome),
                    'error_code' => $failure[1],
                    'retryable' => $failure[2],
                ],
            )
            : null;
    }

    /** @return array<string, mixed> */
    private function metadata(PaymentCharge $charge, string $operation): array
    {
        return [
            'mode' => 'sandbox',
            'operation' => $operation,
            'method' => $charge->method,
            'idempotency_key' => $charge->idempotencyKey,
        ];
    }
}
