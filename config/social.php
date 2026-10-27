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
        // El accessJwt de Bluesky dura horas; se renueva con margen de 1 h (RefreshTokens corre cada 30 min).
        'bluesky' => (int) env('SOCIAL_REFRESH_MARGIN_BLUESKY_HOURS', 1),
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
        'scopes' => array_values(array_unique(array_filter(array_merge([
            'business_management',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'read_insights',
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_insights',
            'instagram_manage_contents',
            // Inbox de Instagram y moderacion de la Pagina (P3): activados en los casos de uso de la app.
            'instagram_manage_comments',
            'pages_manage_engagement',
        ],
            /*
             * Permisos OPCIONALES (Fase P3): Meta rechaza TODO el login («Invalid Scopes») si se pide un permiso que no esta
             * activado en un caso de uso de la app. Por eso solo se piden si se declaran aqui, tras activarlos en
             * developers.facebook.com y antes de reconectar: `pages_read_user_content` (comentarios de Facebook en el Inbox)
             * y `ads_read` (Meta Ads). Sin ellos esas secciones muestran «no disponible» con la instruccion de activarlos.
             */
            array_map('trim', explode(',', (string) env('META_EXTRA_SCOPES', ''))),
        )))),
    ],

    /*
    | Cuadricula de Instagram (C-02): lo ya publicado se lee en vivo de `/{ig}/media` y NO se persiste (las URLs del CDN de Meta
    | caducan). `grid_cache_minutes`: cache por cuenta; `grid_error_cache_minutes`: cuanto se recuerda un error de Graph para no
    | repetir 1-3 llamadas de hasta `http_timeout` s en cada visita; `grid_published`: elementos publicados (3 columnas x 10 filas).
    */
    'instagram' => [
        'grid_cache_minutes' => (int) env('SOCIAL_IG_GRID_CACHE_MINUTES', 10),
        'grid_error_cache_minutes' => (int) env('SOCIAL_IG_GRID_ERROR_CACHE_MINUTES', 2),
        'grid_published' => (int) env('SOCIAL_IG_GRID_PUBLISHED', 30),
    ],

    'google' => [
        'scopes' => [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
            /* Primer comentario (commentThreads.insert). Las cuentas conectadas antes deben reconectar. */
            'https://www.googleapis.com/auth/youtube.force-ssl',
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

        /*
        | Idempotencia (`findPublished`): paginas de la playlist de subidas que se recorren desde `since` (50 por pagina). Cada
        | pagina cuesta 1 ud de cuota (playlistItems.list) + 1 (videos.list): como mucho 2 x paginas = 6 uds por comprobacion.
        */
        'lookup_max_pages' => (int) env('YOUTUBE_LOOKUP_MAX_PAGES', 3),
    ],

    /*
    | Bluesky (D1: app password cifrada). `entryway`: resolucion de handles; el PDS de cada cuenta sale de su documento DID
    | (`plc_directory`). Topes de media: imagen 2 MB (lexicon vigente; bajar a 1.000.000 si un PDS antiguo lo exige) y video
    | unos 50 MB (decision del usuario 2026-10-27; el lexicon admite 300 MB, pero la subida es UNA peticion dentro de los 85 s
    | del paso). Un video mayor se rechaza al guardar.
    */
    'bluesky' => [
        /* Apagar Bluesky sin tocar codigo: la red desaparece de Conexiones, el compositor y los filtros. */
        'enabled' => (bool) env('BLUESKY_ENABLED', true),
        'entryway' => env('BLUESKY_ENTRYWAY', 'https://bsky.social'),
        'plc_directory' => env('BLUESKY_PLC_DIRECTORY', 'https://plc.directory'),
        'appview' => env('BLUESKY_APPVIEW', 'https://public.api.bsky.app'),
        'video_service' => env('BLUESKY_VIDEO_SERVICE', 'https://video.bsky.app'),
        /* Hosts FIJOS permitidos (entryway, PLC, AppView, video): un .env equivocado no puede apuntar el trafico a otro sitio (SSRF). */
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('BLUESKY_ALLOWED_HOSTS', 'bsky.social,plc.directory,public.api.bsky.app,api.bsky.app,video.bsky.app'))))),
        'default_langs' => array_values(array_filter(array_map('trim', explode(',', (string) env('BLUESKY_DEFAULT_LANGS', 'es'))))),
        'image_max_bytes' => (int) env('BLUESKY_IMAGE_MAX_BYTES', 2_000_000),
        'video_max_bytes' => (int) env('BLUESKY_VIDEO_MAX_BYTES', 52_428_800),
        /* Timeout (s) de la subida del video: UNA peticion dentro de los 85 s del paso (job de 110 s). */
        'upload_timeout' => (int) env('BLUESKY_UPLOAD_TIMEOUT', 80),
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

        /*
        | La `upload_url` de FILE_UPLOAD vale 1 hora desde que se emite (doc. de TikTok): pasados estos minutos (con margen) se
        | reinicia la subida con un `init` nuevo, hasta `upload_restarts` veces; luego falla pidiendo reprogramar.
        | `upload_max_minutes` acota la fase de subida de TikTok (sustituye a `publishing.upload_max_minutes`): sesion inicial
        | + reinicio; las esperas de reconexion reinician el reloj.
        */
        'upload_url_ttl_minutes' => (int) env('TIKTOK_UPLOAD_URL_TTL', 55),
        'upload_restarts' => (int) env('TIKTOK_UPLOAD_RESTARTS', 1),
        'upload_max_minutes' => (int) env('TIKTOK_UPLOAD_MAX_MINUTES', 120),

        /* Borrador en `PROCESSING_UPLOAD` tras la subida completa: pasados estos minutos se da por publicado con nota. */
        'inbox_grace_minutes' => (int) env('TIKTOK_INBOX_GRACE_MINUTES', 10),
    ],

    /*
    | Analitica (fase 7, T-31..T-37). Cola `metrics`; los jobs tienen $timeout < 120 (timeout del worker).
    */
    'analytics' => [
        'queue' => env('SOCIAL_ANALYTICS_QUEUE', 'metrics'),
        /* Minutos que se cachea �Todas las marcas� por usuario y periodo (0 = sin cache). */
        'all_brands_cache_minutes' => (int) env('SOCIAL_ALL_BRANDS_CACHE_MINUTES', 5),
        /* Al conectar una cuenta se encola el historico inicial (T-36). */
        'backfill_on_connect' => (bool) env('SOCIAL_BACKFILL_ON_CONNECT', true),
        /*
        | Hora UTC del job diario. Meta y YouTube Analytics definen el dia en el huso del Pacifico (America/Los_Angeles),
        | que cierra a las 07:00/08:00 UTC: a las 12:00 UTC el dia anterior del Pacifico ya esta cerrado y Meta ha
        | publicado sus valores. (A las 03:30 UTC aun no habia terminado ese dia.) Lo que tarde mas (YouTube: 1-3 dias)
        | lo recogen los reintentos de `settle_days`.
        */
        'daily_at' => env('SOCIAL_ANALYTICS_DAILY_AT', '12:00'),
        /* Zona en la que cada red define el "dia" de sus metricas; el ultimo dia cerrado se calcula en ella. */
        'timezones' => [
            'instagram' => 'America/Los_Angeles',
            'facebook' => 'America/Los_Angeles',
            'youtube' => 'America/Los_Angeles',
            'tiktok' => 'UTC',
            'bluesky' => 'UTC',
        ],
        /*
        | Dias anteriores al ultimo cerrado que se reintentan mientras su snapshot no este completo (faltan flujos
        | diarios esperados de la red: latencia de YouTube, caidas, limites de uso). Pasada la ventana el snapshot se
        | da por asentado aunque le falten metricas (la API no las ofrece) y no se vuelve a pedir.
        */
        'settle_days' => (int) env('SOCIAL_ANALYTICS_SETTLE_DAYS', 3),
        /* Publicaciones recientes cuyo snapshot se toma en el job diario. */
        'recent_post_days' => (int) env('SOCIAL_ANALYTICS_RECENT_POST_DAYS', 30),
        'max_posts_per_account' => (int) env('SOCIAL_ANALYTICS_MAX_POSTS', 50),
        /* Presupuesto (s) de una pasada de lectura de publicaciones (< $timeout 90 s del job); si no acaba, se encadena otra. */
        'posts_budget_seconds' => (int) env('SOCIAL_ANALYTICS_POSTS_BUDGET', 60),
        /* Publicaciones que lista el dashboard por red (las de mas interacciones); los KPIs cubren TODAS las del periodo. */
        'dashboard_posts' => (int) env('SOCIAL_ANALYTICS_DASHBOARD_POSTS', 50),
        /* Refresco frecuente de publicaciones nuevas: cada `refresh_hours` durante `refresh_window_hours`. */
        'refresh_hours' => (int) env('SOCIAL_ANALYTICS_REFRESH_HOURS', 6),
        'refresh_window_hours' => (int) env('SOCIAL_ANALYTICS_REFRESH_WINDOW', 72),
        /*
        | Dias de historico que se piden al conectar. Decision (T-36): Instagram 30 (limite de 30 dias por consulta de la
        | API de insights de usuario, y sus metricas total_value exigen una llamada por dia); Facebook 90 (se lee por
        | rangos de <= 30 dias, 3 llamadas por rango); YouTube 365 (un solo informe `dimensions=day` por tramo de 90
        | dias); TikTok ninguno (la Display API no da serie historica). Tope duro: Period::MAX_DAYS.
        */
        'backfill_days' => [
            'instagram' => (int) env('SOCIAL_BACKFILL_DAYS_INSTAGRAM', 30),
            'facebook' => (int) env('SOCIAL_BACKFILL_DAYS_FACEBOOK', 90),
            'youtube' => (int) env('SOCIAL_BACKFILL_DAYS_YOUTUBE', 365),
            'tiktok' => 0,
            // Bluesky no ofrece historico de cuenta: solo el stock de hoy.
            'bluesky' => 0,
        ],
        /* Presupuesto (s) de una pasada de backfill; si no acaba, el job se reencola y continua. */
        'backfill_budget_seconds' => (int) env('SOCIAL_BACKFILL_BUDGET', 80),
    ],

    /*
    | Mejores horas (PA-05): ventana de analisis y minimo de publicaciones con metricas para mostrar el mapa.
    */
    'best_times' => [
        'days' => (int) env('SOCIAL_BEST_TIMES_DAYS', 90),
        'min_posts' => (int) env('SOCIAL_BEST_TIMES_MIN_POSTS', 10),
        /* Cache corta (min) del mapa de calor por usuario/marca/red; 0 = sin cache. */
        'cache_minutes' => (int) env('SOCIAL_BEST_TIMES_CACHE_MINUTES', 10),
    ],

    /*
    | Competidores de Instagram (PA-08, Business Discovery). Hora UTC del snapshot diario (tras el de cuentas) y tope por marca.
    */
    'competitors' => [
        'daily_at' => env('SOCIAL_COMPETITORS_DAILY_AT', '13:00'),
        'max_per_brand' => (int) env('SOCIAL_COMPETITORS_MAX', 10),
    ],

    /*
    | Autolistas (PA-09): el job diario genera las publicaciones programadas de los proximos `horizon_days` dias.
    */
    'autolists' => [
        'daily_at' => env('SOCIAL_AUTOLISTS_DAILY_AT', '02:00'),
        'horizon_days' => (int) env('SOCIAL_AUTOLISTS_HORIZON_DAYS', 7),
        'queue' => env('SOCIAL_AUTOLISTS_QUEUE', 'poll'),
    ],

    /*
    | Buzon de comentarios de Instagram/Facebook (PA-10): sincronizacion periodica en la cola `poll`. Solo publicaciones
    | propias publicadas en los ultimos `post_days` dias (y como mucho `max_posts` por cuenta) para acotar las llamadas.
    */
    /* SmartLinks: contacto al que enviar denuncias de una pagina publica (por defecto, el remitente del correo). */
    'smartlinks' => [
        'report_email' => env('SMARTLINKS_REPORT_EMAIL'),
    ],

    'inbox' => [
        'queue' => env('SOCIAL_INBOX_QUEUE', 'poll'),
        'post_days' => (int) env('SOCIAL_INBOX_POST_DAYS', 30),
        'max_posts' => (int) env('SOCIAL_INBOX_MAX_POSTS', 25),
        'comments_per_post' => (int) env('SOCIAL_INBOX_COMMENTS_PER_POST', 50),
        /* Paginas de Graph que se siguen por publicacion (comentarios) y por hilo (respuestas). */
        'max_pages' => (int) env('SOCIAL_INBOX_MAX_PAGES', 5),
        'max_reply_pages' => (int) env('SOCIAL_INBOX_MAX_REPLY_PAGES', 3),
        /* Presupuesto (s) por pasada del job de sincronizacion; < $timeout (90 s). Al agotarlo continua en otra pasada. */
        'budget_seconds' => (float) env('SOCIAL_INBOX_BUDGET_SECONDS', 60),
        'sync_minutes' => (int) env('SOCIAL_INBOX_SYNC_MINUTES', 15),
        'reply_max_chars' => 2200,
    ],

    /*
    | Primer comentario: busqueda ACOTADA del comentario propio (idempotencia). Pocas paginas, comentarios de nivel superior y un
    | presupuesto (s) por debajo del timeout del job (110 s). Si se agota sin llegar al final, el job no crea otro a ciegas.
    */
    'first_comment' => [
        'find_pages' => (int) env('SOCIAL_FIRST_COMMENT_FIND_PAGES', 3),
        'find_per_page' => (int) env('SOCIAL_FIRST_COMMENT_FIND_PER_PAGE', 100),
        'find_budget_seconds' => (float) env('SOCIAL_FIRST_COMMENT_FIND_BUDGET', 80),
    ],

    /* Meta Ads solo lectura (PA-11): cache corta de las respuestas de Graph (minutos) y topes de listado. */
    'ads' => [
        'cache_minutes' => (int) env('SOCIAL_ADS_CACHE_MINUTES', 10),
        'max_accounts' => 25,
        'max_campaigns' => 50,
    ],

    /*
    | Asistente IA opcional (T-14). Desactivado por defecto: solo se ofrece con AI_ASSISTANT_ENABLED=true Y ANTHROPIC_API_KEY.
    | Llama a la Messages API de Anthropic con Http (sin SDK). No se envian datos personales: solo el brief que escribe el
    | usuario, el nombre de la marca y la red de destino.
    */
    'ai' => [
        'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_ASSISTANT_MODEL', 'claude-sonnet-5-5'),
        'max_tokens' => (int) env('AI_ASSISTANT_MAX_TOKENS', 600),
        'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 30),
        /* Limite por usuario: peticiones al dia. */
        'daily_limit' => (int) env('AI_ASSISTANT_DAILY_LIMIT', 20),
        /* Tope diario GLOBAL (todos los usuarios) de sugerencias; 0 = sin tope. Protege el gasto de la clave compartida. */
        'global_daily_limit' => (int) env('AI_ASSISTANT_GLOBAL_DAILY_LIMIT', 200),
    ],
];
