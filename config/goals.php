<?php

return [
    // Se evalúa después de la recogida diaria de snapshots (12:00 por defecto).
    'daily_at' => env('GOALS_DAILY_AT', '13:15'),
    // Vacío usa la cola de analítica configurada, metrics por defecto.
    'mail_queue' => env('GOALS_MAIL_QUEUE', 'poll'),
    'queue' => env('GOALS_QUEUE') ?: env('SOCIAL_ANALYTICS_QUEUE', 'metrics'),
];
