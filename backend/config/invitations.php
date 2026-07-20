<?php

return [
    'employee_expiration_hours' => (int) env('EMPLOYEE_INVITATION_EXPIRATION_HOURS', 72),
    'reminder_cooldown_minutes' => (int) env('EMPLOYEE_INVITATION_REMINDER_COOLDOWN_MINUTES', 10),
];
