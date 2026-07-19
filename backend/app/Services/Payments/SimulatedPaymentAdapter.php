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
        if ($declined = $this->declined($charge)) {
            return $declined;
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-CHG-'.Str::upper(Str::random(10)),
            metadata: $this->metadata($charge, 'charge'),
        );
    }

    public function authorize(PaymentCharge $charge): PaymentResult
    {
        if ($declined = $this->declined($charge)) {
            return $declined;
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-AUTH-'.Str::upper(Str::random(10)),
            metadata: $this->metadata($charge, 'authorize'),
        );
    }

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult
    {
        if ($declined = $this->declined($charge)) {
            return $declined;
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

    private function declined(PaymentCharge $charge): ?PaymentResult
    {
        return $charge->simulationOutcome === 'declined'
            ? new PaymentResult(
                successful: false,
                failureReason: 'Simulated provider decline',
                metadata: $this->metadata($charge, 'declined'),
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
