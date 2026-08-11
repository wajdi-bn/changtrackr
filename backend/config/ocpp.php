<?php

return [
    'gateway' => [
        'shared_secret' => env('OCPP_GATEWAY_SHARED_SECRET'),
        'public_url' => env('OCPP_GATEWAY_PUBLIC_URL', 'ws://localhost:9000/ocpp'),
        'signature_tolerance_seconds' => (int) env('OCPP_GATEWAY_SIGNATURE_TOLERANCE_SECONDS', 300),
        'command_ttl_seconds' => (int) env('OCPP_COMMAND_TTL_SECONDS', 120),
        'supervision_command_ttl_seconds' => (int) env('OCPP_SUPERVISION_COMMAND_TTL_SECONDS', 60),
        'command_poll_interval_seconds' => (float) env('OCPP_COMMAND_POLL_INTERVAL_SECONDS', 1.5),
        'rate_limits' => [
            'authenticate_per_minute' => (int) env('OCPP_AUTHENTICATE_RATE_LIMIT_PER_MINUTE', 30),
            'events_per_minute' => (int) env('OCPP_EVENTS_RATE_LIMIT_PER_MINUTE', 1200),
            'command_poll_per_minute' => (int) env('OCPP_COMMAND_POLL_RATE_LIMIT_PER_MINUTE', 180),
            'command_result_per_minute' => (int) env('OCPP_COMMAND_RESULT_RATE_LIMIT_PER_MINUTE', 120),
        ],
    ],

    'simulator' => [
        'station_secret' => env('OCPP_SIMULATOR_STATION_SECRET'),
        'id_tag' => env('OCPP_SIMULATOR_ID_TAG', 'TEST-TAG-001'),
        'control_url' => env('OCPP_SIMULATOR_CONTROL_URL', 'http://127.0.0.1:8081'),
        'control_token' => env('OCPP_SIMULATOR_CONTROL_TOKEN'),
        'control_timeout_seconds' => (int) env('OCPP_SIMULATOR_CONTROL_TIMEOUT_SECONDS', 15),
    ],
];
