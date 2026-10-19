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
    | DECISION PENDIENTE DE CONFIRMAR (producto): design.md:158 dice «tras una caida se publican las vencidas»,
    | sin limite; este tope de 24 h es un valor por defecto prudente, no una decision tomada. Subelo (o usa un valor muy
    | alto) si se prefiere publicar siempre lo vencido.
    */
    'max_lateness_hours' => (int) env('PUBLISH_MAX_LATENESS_HOURS', 24),

    /*
    | Un destino `procesando` cuya cuenta sigue en `requiere_reconexion` espera la reconexion estas horas; pasado el plazo
    | se marca `fallida` (cuenta_no_disponible) con aviso, para que el usuario pueda reprogramarla o borrarla.
    | Con la cuenta desconectada o borrada se falla de inmediato.
    */
    'reconnect_grace_hours' => (int) env('PUBLISH_RECONNECT_GRACE_HOURS', 72),

    /*
    | Cuota de publicacion agotada (p. ej. 25 publicaciones/24 h de Instagram). Decision de producto por confirmar:
    |   reschedule = aplazar hasta que la red libere cupo (por defecto; falla si no llega dentro del margen de retraso)
    |   fail       = fallar de inmediato con motivo «cuota» y avisar
    */
    'quota_policy' => env('PUBLISH_QUOTA_POLICY', 'reschedule'),

    /* Sondeo de publicaciones asincronas (contenedores de IG, videos de FB). */
    'poll_interval_seconds' => (int) env('PUBLISH_POLL_INTERVAL', 30),
    'poll_max_minutes' => (int) env('PUBLISH_POLL_MAX_MINUTES', 30),

    /*
    | Plazo (min) de la fase de SUBIDA de videos grandes (YouTube `subiendo`, TikTok `progress.phase = uploading`), que va
    | aparte del procesado: `poll_max_minutes` empieza a contar cuando la subida TERMINA. Un video de varios GB tarda mas
    | de 30 min en subirse sin estar "colgado".
    */
    'upload_max_minutes' => (int) env('PUBLISH_UPLOAD_MAX_MINUTES', 240),

    /*
    | Presupuesto (s) de UN paso de publicacion/sondeo con varias llamadas a la red (carrusel de IG). Debe quedar por
    | debajo del `$timeout` del job (110 s) menos una llamada (`social.http_timeout`): al agotarse, el paso guarda su
    | progreso y sigue en el siguiente sondeo.
    */
    'step_budget_seconds' => (int) env('PUBLISH_STEP_BUDGET', 85),

    /* Maximo de destinos que despacha el scheduler por pasada. */
    'dispatch_batch' => (int) env('PUBLISH_DISPATCH_BATCH', 200),

    /* Vigencia (min) de las URLs firmadas de media que se entregan a las redes. */
    'media_url_ttl_minutes' => (int) env('PUBLISH_MEDIA_URL_TTL', 720),
];
