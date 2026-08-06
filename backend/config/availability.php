<?php

return [
    'heartbeat_interval_seconds' => (int) env('AVAILABILITY_HEARTBEAT_INTERVAL_SECONDS', 30),
    'communication_timeout_seconds' => (int) env('AVAILABILITY_COMMUNICATION_TIMEOUT_SECONDS', 90),
    'dashboard_cache_ttl_seconds' => (int) env('AVAILABILITY_DASHBOARD_CACHE_TTL_SECONDS', 60),
    'critical_alert_due_minutes' => (int) env('AVAILABILITY_CRITICAL_ALERT_DUE_MINUTES', 15),
    'warning_alert_due_minutes' => (int) env('AVAILABILITY_WARNING_ALERT_DUE_MINUTES', 60),
    'info_alert_due_minutes' => (int) env('AVAILABILITY_INFO_ALERT_DUE_MINUTES', 240),
];
