<?php

namespace App\Contracts;

use App\Data\PaymentCharge;
use App\Data\PaymentResult;

interface PaymentGateway
{
    public function name(): string;

    public function charge(PaymentCharge $charge): PaymentResult;
}
