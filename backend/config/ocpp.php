<?php

return [
    'gateway' => [
        'shared_secret' => env('OCPP_GATEWAY_SHARED_SECRET'),
        'public_url' => env('OCPP_GATEWAY_PUBLIC_URL', 'ws://localhost:9000/ocpp'),
        'signature_tolerance_seconds' => (int) env('OCPP_GATEWAY_SIGNATURE_TOLERANCE_SECONDS', 300),
        'command_ttl_seconds' => (int) env('OCPP_COMMAND_TTL_SECONDS', 120),
        'supervision_command_ttl_seconds' => (int) env('OCPP_SUPERVISION_COMMAND_TTL_SECONDS', 60),
        'command_poll_interval_seconds' => (float) env('OCPP_COMMAND_POLL_INTERVAL_SECONDS', 1.5),
    ],

    'simulator' => [
        'station_secret' => env('OCPP_SIMULATOR_STATION_SECRET'),
        'id_tag' => env('OCPP_SIMULATOR_ID_TAG', 'TEST-TAG-001'),
    ],
];
