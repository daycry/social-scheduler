<?php

return [
    'queue' => env('WEBHOOK_QUEUE', 'poll'),
    'max_per_user' => (int) env('WEBHOOK_MAX_PER_USER', 20),
    'settings_per_minute' => (int) env('WEBHOOK_SETTINGS_PER_MINUTE', 30),
];
