<?php

return [
    'notification_email' => env('DEMO_REQUEST_NOTIFICATION_EMAIL'),
    'invitation_expiration_hours' => (int) env('ACCOUNT_INVITATION_EXPIRATION_HOURS', 48),
    'trial_days' => (int) env('DEMO_TRIAL_DAYS', 30),
];
