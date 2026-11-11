<?php

/*
| Umbrales de salud (A-09). Los usa `App\Support\HealthCheck` (`app:health` y `/health`).
*/
return [
    // Segundos que puede llevar esperando el job pendiente mas viejo de una cola antes de avisar de «cola atrasada».
    // Las colas de trabajo nativo (media, studio) toleran mas. Una cola sin entrada usa `default`.
    'queue_max_age' => [
        'default' => (int) env('HEALTH_QUEUE_MAX_AGE', 600),
        'media' => (int) env('HEALTH_QUEUE_MAX_AGE_NATIVE', 1800),
        'studio' => (int) env('HEALTH_QUEUE_MAX_AGE_NATIVE', 1800),
    ],

    // Horas sin ningun snapshot nuevo de `CollectSnapshots` (diario) tras las que se avisa; solo si hay cuentas conectadas.
    'snapshots_max_age_hours' => (int) env('HEALTH_SNAPSHOTS_MAX_AGE_HOURS', 36),

    // Comprueba los binarios nativos (ffmpeg, ffprobe, Chromium, Python y modelo). Se apaga con HEALTH_NATIVE=false.
    'native' => (bool) env('HEALTH_NATIVE', true),

    // Segundos que se cachea el resultado de `native` para las peticiones HTTP (la CLI siempre comprueba). 0 = sin cache.
    'native_cache_seconds' => (int) env('HEALTH_NATIVE_CACHE_SECONDS', 300),

    // Correo al que avisa `Queue::failing` (vacio = sin aviso) y minutos minimos entre dos avisos para no inundar.
    'alert_email' => env('HEALTH_ALERT_EMAIL'),
    'alert_throttle_minutes' => (int) env('HEALTH_ALERT_THROTTLE_MINUTES', 60),
];
