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

    /* Vigencia (minutos) de las URLs prefirmadas de subida y de las URLs que reciben las redes. */
    'upload_url_ttl' => (int) env('MEDIA_UPLOAD_URL_TTL', 120),
    'public_url_ttl' => (int) env('MEDIA_PUBLIC_URL_TTL', 720),

    /* Binario de ffprobe (requisito de despliegue para leer duracion/fps/codec de los videos). */
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'ffprobe_timeout' => (int) env('FFPROBE_TIMEOUT', 60),
];
