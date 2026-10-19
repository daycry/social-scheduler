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

    'tiktok' => [
        // video.publish solo es util tras la auditoria de TikTok (decision G1: borrador por defecto).
        'scopes' => ['user.info.basic', 'video.upload', 'video.publish'],
    ],
];
