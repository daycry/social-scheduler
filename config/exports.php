<?php

/*
| Exportación completa de los datos del usuario en un ZIP (T-84, F-10). Documentada en docs/usuario/exportar.md y en docs/modulos/despliegue.md.
|
| El ZIP se genera en cola, se guarda en un disco PRIVADO (nunca el público) y se borra al caducar (`exports:prune`, cada hora). El escritor
| admite ZIP64: no hay límite de 4 GiB por fichero ni de 65 535 ficheros.
*/
return [
    // Disco privado donde viven los ZIP. Debe ser un disco que no sirva ficheros por URL pública.
    'disk' => env('EXPORTS_DISK', 'local'),

    // Directorio local donde se arma el ZIP antes de publicarlo (un fichero `<usuario>-<id>.zip.part` por exportación). Propio de la app, no el
    // /tmp compartido; necesita espacio libre para el ZIP completo. `exports:prune` barre los restos.
    'tmp_dir' => env('EXPORTS_TMP_DIR', storage_path('app/exports-tmp')),

    // Cola y conexión propias (config/queue.php, `retry_after` EXPORTS_QUEUE_RETRY_AFTER = 3700 s): un worker dedicado `queue:work exports --queue=exports`
    // con `--timeout` igual o mayor que `timeout` (ver docs/modulos/despliegue.md). La conexión `database` compartida no cambia.
    'queue' => env('EXPORTS_QUEUE', 'exports'),
    'connection' => env('EXPORTS_QUEUE_CONNECTION', 'exports'),

    // Segundos máximos del job (manda sobre el `--timeout` del worker). El `retry_after` de la conexión `exports` es siempre mayor.
    'timeout' => (int) env('EXPORTS_TIMEOUT', 3600),

    // Tope de tamaño del ZIP (bytes). Si se supera, la exportación termina en error y no se publica nada. 20 GiB por defecto.
    'max_bytes' => (int) env('EXPORTS_MAX_BYTES', 21_474_836_480),

    // Horas que se conserva el ZIP desde que está listo; después `exports:prune` borra el fichero.
    'retention_hours' => (int) env('EXPORTS_RETENTION_HOURS', 72),

    // Minutos de validez del enlace firmado de descarga. Se genera uno nuevo al pulsar «Descargar».
    'link_minutes' => (int) env('EXPORTS_LINK_MINUTES', 10),

    // Segundos entre latidos del job (marca de vida de la fila). Un latido que no se puede escribir porque la fila ya no existe aborta la exportación.
    'heartbeat_seconds' => (int) env('EXPORTS_HEARTBEAT_SECONDS', 15),

    // Minutos que una exportación puede esperar en cola (detrás de otras largas) antes de darla por perdida, medidos desde que se pidió. Es mayor que
    // `stuck_minutes` porque una pendiente no late: solo cuenta que el worker no la haya cogido en este plazo.
    'pending_minutes' => (int) env('EXPORTS_PENDING_MINUTES', 360),

    // Minutos sin latido tras los que una exportación en curso se da por atascada (worker caído) y `exports:prune` la marca en error y libera
    // el cupo. Debe ser MAYOR que `timeout`; también es la antigüedad a partir de la cual se borran los temporales huérfanos.
    'stuck_minutes' => (int) env('EXPORTS_STUCK_MINUTES', 90),

    // Espacio libre (bytes) que se exige además del tamaño estimado de los ficheros para arrancar: el JSON de los datos no se conoce de antemano.
    'free_space_margin' => (int) env('EXPORTS_FREE_SPACE_MARGIN', 268_435_456),
];
