<?php

namespace App\Data;

final readonly class PaymentCharge
{
    public function __construct(
        public string $paymentReference,
        public int $amountMillimes,
        public string $currency,
        public string $method,
        public string $idempotencyKey,
        public string $simulationOutcome = 'success',
    ) {}
}
