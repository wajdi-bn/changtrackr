<?php

namespace App\Exceptions;

use RuntimeException;

class RetryablePaymentCaptureException extends RuntimeException
{
    public function __construct(
        public readonly int $chargingSessionId,
        public readonly int $paymentId,
        ?string $providerMessage = null,
    ) {
        parent::__construct($providerMessage ?? 'The payment provider could not confirm the capture.');
    }
}
