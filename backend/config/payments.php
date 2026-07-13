<?php

use App\Services\Payments\SimulatedPaymentAdapter;

return [
    'default' => env('PAYMENT_DRIVER', 'simulated'),
    'drivers' => [
        'simulated' => SimulatedPaymentAdapter::class,
    ],
];
