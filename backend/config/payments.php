<?php

use App\Services\Payments\SimulatedPaymentAdapter;
use App\Services\Payments\WireMockPaymentAdapter;

return [
    'default' => env('PAYMENT_DRIVER', 'wiremock'),
    'preauthorization_amount_millimes' => (int) env('PAYMENT_PREAUTHORIZATION_AMOUNT_MILLIMES', 30000),
    'authorization_ttl_minutes' => (int) env('PAYMENT_AUTHORIZATION_TTL_MINUTES', 15),
    'drivers' => [
        'simulated' => SimulatedPaymentAdapter::class,
        'wiremock' => WireMockPaymentAdapter::class,
    ],
    'simulator' => [
        'base_url' => env('PAYMENT_SIMULATOR_BASE_URL', 'http://127.0.0.1:9090'),
        'operation_endpoint' => env('PAYMENT_SIMULATOR_OPERATION_ENDPOINT', '/v1/payment-operations'),
        'api_key' => env('PAYMENT_SIMULATOR_API_KEY', 'chargetrackr-local'),
        'webhook_url' => env('PAYMENT_SIMULATOR_WEBHOOK_URL', 'http://host.docker.internal:8000/api/internal/payments/webhooks'),
        'webhook_secret' => env('PAYMENT_SIMULATOR_WEBHOOK_SECRET', 'local-payment-webhook-secret'),
        'timeout_seconds' => (int) env('PAYMENT_SIMULATOR_TIMEOUT_SECONDS', 3),
        'retries' => (int) env('PAYMENT_SIMULATOR_RETRIES', 0),
    ],
];
