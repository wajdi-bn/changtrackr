<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/oauth/*', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))),
    // A regular expression itself may contain commas (for example `{1,3}`).
    // Keep the configured pattern intact instead of splitting it as a CSV value.
    'allowed_origins_patterns' => filled(env('CORS_ALLOWED_ORIGIN_PATTERNS'))
        ? [env('CORS_ALLOWED_ORIGIN_PATTERNS')]
        : [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
