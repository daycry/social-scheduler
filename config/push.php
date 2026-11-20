<?php

return [
    'enabled' => (bool) env('PUSH_ENABLED', true),
    'vapid' => [
        'subject' => env('PUSH_VAPID_SUBJECT'),
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('PUSH_VAPID_PRIVATE_KEY'),
    ],
    'queue' => env('PUSH_QUEUE', 'poll'),
    'settings_per_minute' => (int) env('PUSH_SETTINGS_PER_MINUTE', 30),
    'max_devices_per_user' => (int) env('PUSH_MAX_DEVICES_PER_USER', 20),
];
