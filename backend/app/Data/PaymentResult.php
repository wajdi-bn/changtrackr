<?php

namespace App\Data;

final readonly class PaymentResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public bool $successful,
        public ?string $transactionId = null,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}
}
