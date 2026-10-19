<?php

return [

    /*
    | Margen (horas) con que el job RefreshTokens renueva las credenciales antes de que caduquen.
    | Meta: token de usuario de ~60 dias -> 7 dias de margen. TikTok: access de 24 h.
    */
    'refresh_margin_hours' => [
        'meta' => (int) env('SOCIAL_REFRESH_MARGIN_META_HOURS', 168),
        'google' => (int) env('SOCIAL_REFRESH_MARGIN_GOOGLE_HOURS', 1),
        'tiktok' => (int) env('SOCIAL_REFRESH_MARGIN_TIKTOK_HOURS', 6),
    ],

    /* Cola donde corre el refresco de tokens (ver config/queue.php names). */
    'refresh_queue' => env('SOCIAL_REFRESH_QUEUE', 'poll'),

    /*
    | Fallos TRANSITORIOS (5xx, red, timeouts) seguidos tras los que una cuenta pasa a requiere_reconexion.
    | El job corre cada 30 min: 12 ~ 6 h de Graph/Google/TikTok caidos. Los permanentes (invalid_grant,
    | token revocado, acceso a datos o refresh token caducados) escalan al instante.
    */
    'max_transient_failures' => (int) env('SOCIAL_REFRESH_MAX_TRANSIENT_FAILURES', 12),

    /* Timeout HTTP de los conectores (segundos). */
    'http_timeout' => (int) env('SOCIAL_HTTP_TIMEOUT', 15),

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        /* Intercambio de codigo/token: `post` (secretos en el cuerpo, por defecto) o `get` (en la query; ultimo recurso). */
        'oauth_method' => env('META_OAUTH_METHOD', 'post'),
        /* Tope de paginas que se siguen en `paging.next` (100 elementos por pagina). */
        'max_pages' => (int) env('META_MAX_PAGES', 10),
        'scopes' => [
            'business_management',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'read_insights',
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_insights',
            'instagram_manage_contents',
        ],
    ],

    'google' => [
        'scopes' => [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ],
    ],

    /*
    | Publicacion en YouTube (T-26, decision G1 = A). La cuota de la API es POR PROYECTO de Google (no por canal):
    | 10.000 unidades/dia y ~1.600 por subida (~6 subidas). Se cuenta localmente (cache) para no gastar llamadas
    | que la API rechazaria; al agotarse se aplica `PUBLISH_QUOTA_POLICY` (reprogramar o fallar). El dia de cuota
    | de YouTube acaba a medianoche de Pacifico.
    */
    'youtube' => [
        'daily_quota' => (int) env('YOUTUBE_DAILY_QUOTA', 10000),
        'upload_cost' => (int) env('YOUTUBE_UPLOAD_COST', 1600),
        /* Trozos de la subida reanudable: multiplo de 256 KiB (requisito de Google). 8 MiB. */
        'chunk_bytes' => (int) env('YOUTUBE_CHUNK_BYTES', 8 * 1024 * 1024),
        /* Segundos de subida por pasada del job (< timeout de 110 s); si no acaba, se continua en el sondeo. */
        'upload_budget_seconds' => (int) env('YOUTUBE_UPLOAD_BUDGET', 80),
        /* No se empieza otro PUT si queda menos de esto del presupuesto (un trozo de 8 MiB necesita unos segundos). */
        'upload_min_slice_seconds' => (int) env('YOUTUBE_UPLOAD_MIN_SLICE', 10),
        /* Marca DURA de la pasada (< `$timeout` 110 s del job): el timeout HTTP de cada llamada se acota a lo que queda. */
        'pass_seconds' => (int) env('YOUTUBE_PASS_SECONDS', 100),
        /* Tope de UN PUT de trozo (siempre acotado ademas por lo que quede hasta `pass_seconds`). */
        'upload_timeout' => (int) env('YOUTUBE_UPLOAD_TIMEOUT', 60),
    ],

    'tiktok' => [
        // video.publish solo es util tras la auditoria de TikTok (decision G1: borrador por defecto).
        'scopes' => ['user.info.basic', 'video.upload', 'video.publish'],

        /*
        | Dominio VERIFICADO en TikTok (URL prefix) que sirve la media por PULL_FROM_URL. Solo con esto se ofrece el tipo
        | foto/carrusel (T-27): la subida de archivos (FILE_UPLOAD) solo existe para video. Vacio = foto desactivada.
        */
        'verified_domain' => env('TIKTOK_VERIFIED_DOMAIN'),

        /* Sufijos de host permitidos para la `upload_url` que devuelve TikTok (evita subir a un host arbitrario). */
        'upload_hosts' => ['tiktokapis.com', 'tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokv.com'],

        /* Tamano de trozo de FILE_UPLOAD cuando el video supera `single_chunk_max_bytes` (el ultimo absorbe el resto). */
        'chunk_bytes' => (int) env('TIKTOK_CHUNK_BYTES', 10 * 1024 * 1024),
        'single_chunk_max_bytes' => 64 * 1024 * 1024,
        'upload_timeout' => (int) env('TIKTOK_UPLOAD_TIMEOUT', 100),

        /* Presupuesto BLANDO (s) de subida por pasada y marca DURA (< `$timeout` 110 s del job): ver TikTokPublisher. */
        'upload_budget_seconds' => (int) env('TIKTOK_UPLOAD_BUDGET', 80),
        'upload_min_slice_seconds' => (int) env('TIKTOK_UPLOAD_MIN_SLICE', 15),
        'pass_seconds' => (int) env('TIKTOK_PASS_SECONDS', 100),

        /* Borrador en `PROCESSING_UPLOAD` tras la subida completa: pasados estos minutos se da por publicado con nota. */
        'inbox_grace_minutes' => (int) env('TIKTOK_INBOX_GRACE_MINUTES', 10),
    ],

    /*
    | Analitica (fase 7, T-31..T-37). Cola `metrics`; los jobs tienen $timeout < 120 (timeout del worker).
    */
    'analytics' => [
        'queue' => env('SOCIAL_ANALYTICS_QUEUE', 'metrics'),
        /* Hora UTC del job diario: el dia anterior ya esta cerrado en todas las zonas habituales. */
        'daily_at' => env('SOCIAL_ANALYTICS_DAILY_AT', '03:30'),
        /* Dias anteriores a ayer que se reintentan si su snapshot falta o salio vacio (latencia de YouTube, caidas). */
        'settle_days' => (int) env('SOCIAL_ANALYTICS_SETTLE_DAYS', 2),
        /* Publicaciones recientes cuyo snapshot se toma en el job diario. */
        'recent_post_days' => (int) env('SOCIAL_ANALYTICS_RECENT_POST_DAYS', 30),
        'max_posts_per_account' => (int) env('SOCIAL_ANALYTICS_MAX_POSTS', 50),
        /* Refresco frecuente de publicaciones nuevas: cada `refresh_hours` durante `refresh_window_hours`. */
        'refresh_hours' => (int) env('SOCIAL_ANALYTICS_REFRESH_HOURS', 6),
        'refresh_window_hours' => (int) env('SOCIAL_ANALYTICS_REFRESH_WINDOW', 72),
        /* Dias de historico que se piden al conectar (maximo razonable por red; Meta ofrece ~30 en cuenta). */
        'backfill_days' => [
            'instagram' => (int) env('SOCIAL_BACKFILL_DAYS_INSTAGRAM', 30),
            'facebook' => (int) env('SOCIAL_BACKFILL_DAYS_FACEBOOK', 30),
            'youtube' => (int) env('SOCIAL_BACKFILL_DAYS_YOUTUBE', 90),
            'tiktok' => 0, // la Display API no ofrece serie historica
        ],
        /* Presupuesto (s) de una pasada de backfill; si no acaba, el job se reencola y continua. */
        'backfill_budget_seconds' => (int) env('SOCIAL_BACKFILL_BUDGET', 80),
    ],
];
