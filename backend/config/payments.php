<?php

use App\Services\Payments\SimulatedPaymentAdapter;

return [
    'default' => env('PAYMENT_DRIVER', 'simulated'),
    'preauthorization_amount_millimes' => (int) env('PAYMENT_PREAUTHORIZATION_AMOUNT_MILLIMES', 30000),
    'authorization_ttl_minutes' => (int) env('PAYMENT_AUTHORIZATION_TTL_MINUTES', 15),
    'drivers' => [
        'simulated' => SimulatedPaymentAdapter::class,
    ],
];
