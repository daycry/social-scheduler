<?php

return [

    /* Colas (ver config/queue.php names): publicar compite solo con publicar; el sondeo va aparte. */
    'queue' => env('PUBLISH_QUEUE', 'publish'),
    'poll_queue' => env('PUBLISH_POLL_QUEUE', 'poll'),

    /* Intentos totales de publicar un destino antes de darlo por fallido (errores transitorios). */
    'max_attempts' => (int) env('PUBLISH_MAX_ATTEMPTS', 4),

    /* Espera (s) antes del reintento n (1.., el ultimo valor se repite). */
    'backoff_seconds' => [60, 300, 900],

    /* Un destino `publicando` sin avances durante estos minutos se considera colgado y se recupera. */
    'stuck_after_minutes' => (int) env('PUBLISH_STUCK_AFTER_MINUTES', 10),

    /*
    | Las vencidas (VPS caido, cuenta sin reconectar) se publican al volver SOLO si no llevan mas de estas horas
    | de retraso; despues se marcan `fallida` (vencida) para no publicar contenido obsoleto de golpe.
    */
    'max_lateness_hours' => (int) env('PUBLISH_MAX_LATENESS_HOURS', 24),

    /* Sondeo de publicaciones asincronas (contenedores de IG, videos de FB). */
    'poll_interval_seconds' => (int) env('PUBLISH_POLL_INTERVAL', 30),
    'poll_max_minutes' => (int) env('PUBLISH_POLL_MAX_MINUTES', 30),

    /* Maximo de destinos que despacha el scheduler por pasada. */
    'dispatch_batch' => (int) env('PUBLISH_DISPATCH_BATCH', 200),

    /* Vigencia (min) de las URLs firmadas de media que se entregan a las redes. */
    'media_url_ttl_minutes' => (int) env('PUBLISH_MEDIA_URL_TTL', 720),
];
