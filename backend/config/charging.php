<?php

return [
    'price_per_kwh_millimes' => (int) env('CHARGING_PRICE_PER_KWH_MILLIMES', 850),
    'session_fee_millimes' => (int) env('CHARGING_SESSION_FEE_MILLIMES', 500),
];
