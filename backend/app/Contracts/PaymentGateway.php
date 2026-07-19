<?php

namespace App\Contracts;

use App\Data\PaymentCharge;
use App\Data\PaymentResult;

interface PaymentGateway
{
    public function name(): string;

    public function authorize(PaymentCharge $charge): PaymentResult;

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult;

    public function release(string $authorizationId, string $idempotencyKey): PaymentResult;

    public function charge(PaymentCharge $charge): PaymentResult;
}
