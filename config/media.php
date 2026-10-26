<?php

return [

    /* Tipos MIME aceptados al subir (se vuelven a comprobar con el contenido real al finalizar). */
    'allowed_mimes' => [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'video' => ['video/mp4', 'video/quicktime'],
    ],

    /* Tamano maximo de subida (bytes). Los limites por red/tipo viven en config/media_specs.php. */
    'max_bytes' => [
        'image' => (int) env('MEDIA_MAX_IMAGE_BYTES', 30 * 1024 * 1024),
        'video' => (int) env('MEDIA_MAX_VIDEO_BYTES', 4 * 1024 * 1024 * 1024),
    ],

    /* A partir de este tamano se sube por multipart (partes de `part_size`; S3/R2 exige >= 5 MiB). */
    'multipart_threshold' => (int) env('MEDIA_MULTIPART_THRESHOLD', 32 * 1024 * 1024),
    'part_size' => (int) env('MEDIA_PART_SIZE', 16 * 1024 * 1024),

    /*
    | Subidas abandonadas: un asset `pendiente`/`invalido` mas viejo que esto (y sin publicaciones) se elimina con
    | `app:media-prune` (cada hora), que tambien aborta su multipart abierto.
    |
    | Ciclo de vida en R2 (configuracion del bucket, complementa al comando; ver docs del despliegue):
    |  - Regla «Abort incomplete multipart uploads» a 1 dia: red de seguridad si el comando no corre.
    |  - La URL prefirmada NO limita el tamano del PUT; el limite real se comprueba en `complete` (objeto real vs
    |    declarado y vs `max_bytes`) y el objeto excedido se borra.
    */
    'pending_ttl_hours' => (int) env('MEDIA_PENDING_TTL_HOURS', 24),

    /* Vigencia (minutos) de las URLs prefirmadas de subida y de las URLs que reciben las redes. */
    'upload_url_ttl' => (int) env('MEDIA_UPLOAD_URL_TTL', 120),
    'public_url_ttl' => (int) env('MEDIA_PUBLIC_URL_TTL', 720),

    /* Binario de ffprobe (requisito de despliegue para leer duracion/fps/codec de los videos). */
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'ffprobe_timeout' => (int) env('FFPROBE_TIMEOUT', 60),

    /* En R2 se sondea bajando solo los primeros y ultimos N bytes (donde vive el atom `moov`), no el video entero. */
    'probe_edge_bytes' => (int) env('MEDIA_PROBE_EDGE_BYTES', 16 * 1024 * 1024),

    /* Un MP4 FRAGMENTADO (moof) no guarda su duracion en `moov`: se baja entero hasta este tope; por encima se rechaza pidiendo `faststart`. */
    'probe_fragmented_max_bytes' => (int) env('MEDIA_PROBE_FRAGMENTED_MAX_BYTES', 256 * 1024 * 1024),

    /*
    | Importacion remota (URL / Drive, C-04a). Cada pasada del job `ImportRemoteMedia` descarga como mucho este presupuesto
    | de segundos y se vuelve a encolar con `Range`; debe quedar por debajo de `$timeout` del job (110) y del `--timeout` del
    | worker (120). El job va en la cola `import_queue`. Requiere `MEDIA_DISK=local` (el parcial se anexa en disco).
    */
    'import_budget_seconds' => (int) env('MEDIA_IMPORT_BUDGET_SECONDS', 80),
    // Segundos del presupuesto que se reservan para `finalize()` (ffprobe...): la descarga corta antes.
    'import_finalize_reserve_seconds' => (int) env('MEDIA_IMPORT_FINALIZE_RESERVE_SECONDS', 10),
    /*
    | Limites del reencolado sin espera (una pasada que termina pronto se vuelve a encolar al instante): una pasada que no completa y
    | trae menos de `import_min_pass_bytes` cuenta como atasco (3 seguidas = fallo). No hay tope de edad: el corte es por RITMO MEDIO
    | (`import_min_rate_bytes` B/s sobre el tiempo de descarga EFECTIVO, pasada la gracia `import_rate_grace_seconds`; la espera en cola no
    | cuenta) y por pasadas (el mayor entre `import_max_passes` y los tramos de `import_expected_chunk_bytes` que pide el total, con
    | margen). `import_min_pass_seconds` es el ritmo maximo de reencolado: una pasada incompleta mas corta espera el resto dentro del job.
    | Sin `delay` a proposito: con `--stop-when-empty` una espera dejaba ~40 s muertos entre pasadas.
    */
    'import_min_pass_bytes' => (int) env('MEDIA_IMPORT_MIN_PASS_BYTES', 64 * 1024),
    'import_min_rate_bytes' => (int) env('MEDIA_IMPORT_MIN_RATE_BYTES', 16 * 1024),
    'import_rate_grace_seconds' => (int) env('MEDIA_IMPORT_RATE_GRACE_SECONDS', 300),
    'import_expected_chunk_bytes' => (int) env('MEDIA_IMPORT_EXPECTED_CHUNK_BYTES', 8 * 1024 * 1024),
    'import_max_passes' => (int) env('MEDIA_IMPORT_MAX_PASSES', 200),
    'import_min_pass_seconds' => (float) env('MEDIA_IMPORT_MIN_PASS_SECONDS', 1),
    'import_queue' => env('MEDIA_IMPORT_QUEUE', 'media'),
];
