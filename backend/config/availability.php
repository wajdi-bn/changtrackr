<?php

return [
    'heartbeat_interval_seconds' => (int) env('AVAILABILITY_HEARTBEAT_INTERVAL_SECONDS', 30),
    'communication_timeout_seconds' => (int) env('AVAILABILITY_COMMUNICATION_TIMEOUT_SECONDS', 90),
    'critical_alert_due_minutes' => (int) env('AVAILABILITY_CRITICAL_ALERT_DUE_MINUTES', 15),
];
