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
        if ($charge->simulationOutcome === 'declined') {
            return new PaymentResult(
                successful: false,
                failureReason: 'Simulated provider decline',
                metadata: ['mode' => 'sandbox', 'method' => $charge->method],
            );
        }

        return new PaymentResult(
            successful: true,
            transactionId: 'SIM-'.Str::upper(Str::random(12)),
            metadata: ['mode' => 'sandbox', 'method' => $charge->method],
        );
    }
}
