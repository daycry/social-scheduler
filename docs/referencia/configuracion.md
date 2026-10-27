# Configuración (`.env`)

Todas las variables de `.env.example`, agrupadas. Plantilla de producción: `.env.production.example`. Los valores por defecto salen de `config/*.php`.

Leyenda: ⚠️ **Por confirmar** marca una decisión por defecto que el usuario aún no ha validado.

## Aplicación y entorno

| Variable | Defecto | Explicación |
|---|---|---|
| `APP_NAME` | `Social Scheduler` | Nombre mostrado y remitente de correo |
| `APP_ENV`, `APP_DEBUG` | `local`, `true` | En producción: `production`, `false` |
| `APP_KEY` | vacío | `php artisan key:generate`; cifra los tokens OAuth |
| `APP_URL` | `https://metricool.test` | Debe ser HTTPS; base de los redirect URIs OAuth |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | `en`, `en`, `en_US` | Idioma del framework |
| `APP_MAINTENANCE_DRIVER` | `file` | Modo mantenimiento (lo usa `deploy.sh`) |
| `BCRYPT_ROUNDS` | `12` | Coste del hash |
| `TRUSTED_PROXIES` | vacío | Vacío en Hestia; `*` solo tras ngrok |
| `HEALTHCHECK_TOKEN` | vacío | Token de `/health` (`openssl rand -hex 32`); vacío = 404 |

## Registro, sesión, caché, correo

| Variable | Defecto | Explicación |
|---|---|---|
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | `stack`, `single`, `debug`, `null` | Logs |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | `database`, `120`, `false`, `/`, `null` | Sesión en BD |
| `CACHE_STORE` | `database` | Caché en BD (también el contador de cuota de YouTube) |
| `MAIL_MAILER` y `MAIL_*` | `log` | En producción configurar SMTP: los avisos `PublishFailed` usan correo |
| `BROADCAST_CONNECTION`, `FILESYSTEM_DISK` | `log`, `local` | Sin broadcasting |
| `REDIS_*`, `MEMCACHED_HOST`, `AWS_*` | plantilla Laravel | Sin uso en v1 |
| `PHP_CLI_SERVER_WORKERS`, `APP_MAINTENANCE_STORE`, `CACHE_PREFIX` | comentadas | Opcionales |
| `VITE_APP_NAME` | `${APP_NAME}` | Nombre en el frontend |

## Base de datos y colas

| Variable | Defecto | Explicación |
|---|---|---|
| `DB_CONNECTION` ... `DB_PASSWORD` | `mysql`, `127.0.0.1`, `3306`, `metricool`, `root` | MariaDB >= 10.6 / MySQL >= 8.0 |
| `QUEUE_CONNECTION` | `database` | Sin Redis |
| `DB_QUEUE_RETRY_AFTER` | `180` | Debe superar el `--timeout` del worker (120 s) |
| `QUEUE_NAMES` | `publish,poll,metrics,reports,media` | Colas que atienden los workers; `media` es la de las importaciones por URL y Drive |
| `GOOGLE_PICKER_API_KEY` | vacío | Clave de API restringida por referrer; vacía = sin botón «Google Drive» (también sin `GOOGLE_PICKER_APP_ID`) ([guía](../guias/google-cloud-picker-drive.md)) |
| `GOOGLE_PICKER_APP_ID` | vacío | Número de proyecto de Google Cloud para el Picker; **obligatorio** con `drive.file` (sin él no hay botón ni endpoint) |
| `GIPHY_API_KEY` | vacío | Clave *beta* de Giphy (100 llamadas/hora); vacía = sin botón «GIF» en el compositor. La usa solo el servidor ([medios](../modulos/media.md#buscador-de-gifs-de-giphy-c-04b)) |
| `SOCIAL_GIPHY_HOURLY_LIMIT` | 90 | Llamadas reales a Giphy por hora antes de pedir esperar (las respuestas en caché no cuentan; `SOCIAL_GIPHY_CACHE_MINUTES`, 10). Guía: [Giphy](../guias/giphy-api-key.md) |
| `SOCIAL_GIPHY_PER_USER_HOURLY_LIMIT` | 30 | Llamadas reales a Giphy por usuario y hora (uno solo no agota el tope global de `SOCIAL_GIPHY_HOURLY_LIMIT`) |
| `SOCIAL_GIPHY_PER_USER_PER_MINUTE` | 20 | Búsquedas de GIF por usuario y minuto (limitador `giphy`; sin sesión, por IP) |
| `SOCIAL_GIPHY_RATING` / `SOCIAL_GIPHY_LIMIT` | `pg-13` / 24 | Clasificación máxima de los GIF (`g`, `pg`, `pg-13`, `r`) y resultados por búsqueda |
| `SOCIAL_HASHTAGS_MIN_USES` / `_LIMIT` / `_DAYS` / `_CACHE_MINUTES` | 2 / 15 / 365 / 60 | Hashtags sugeridos del histórico propio de la marca: usos mínimos, sugerencias devueltas, días de ventana y caché (minutos) |
| `SOCIAL_EXPORT_MAX_ROWS` | 5000 | Tope de filas de la exportación CSV de la lista de publicaciones; por encima se recorta y se avisa |
| `SOCIAL_ALL_BRANDS_CACHE_MINUTES` | 5 | Caché por usuario y periodo de «Todas las marcas» (0 = sin caché) |
| `MEDIA_IMPORT_QUEUE` | `media` | Cola de `ImportRemoteMedia` |
| `MEDIA_IMPORT_BUDGET_SECONDS` | 80 | Segundos de descarga por pasada |
| `MEDIA_IMPORT_FINALIZE_RESERVE_SECONDS` | 10 | Reserva para validar al final de la pasada |
| `MEDIA_IMPORT_MIN_PASS_BYTES` | 65536 | Ritmo mínimo por pasada; tres pasadas por debajo = importación fallida |
| `MEDIA_IMPORT_MIN_RATE_BYTES` | 16384 | Ritmo medio mínimo (B/s) sobre el tiempo de descarga efectivo, pasada la gracia |
| `MEDIA_IMPORT_RATE_GRACE_SECONDS` | 300 | Descarga efectiva antes de juzgar el ritmo medio |
| `MEDIA_IMPORT_EXPECTED_CHUNK_BYTES` | 8388608 | Tramo esperado por 206 para derivar el tope de pasadas del tamaño total |
| `MEDIA_IMPORT_MAX_PASSES` | 200 | Suelo del tope de pasadas por importación |
| `MEDIA_IMPORT_MIN_PASS_SECONDS` | 1 | Duración mínima de una pasada incompleta (ritmo máximo de reencolado) |

## Media (disco local o R2)

| Variable | Defecto | Explicación |
|---|---|---|
| `MEDIA_DISK` | `local` | `local` (disco del servidor, `storage/app/private`, servido por URL firmada) o `r2`. Nunca `public` |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` | vacío | Credenciales S3 de Cloudflare |
| `R2_URL` | vacío | Dominio público del bucket |
| `R2_REGION` | `auto` | |
| `R2_CONNECT_TIMEOUT`, `R2_TIMEOUT` | `3`, `5` | Segundos; evitan colgar el request si R2 no responde |
| `FFPROBE_BINARY`, `FFPROBE_TIMEOUT` | `ffprobe`, `60` | Binario obligatorio para validar vídeo (duración, fps, códec) |
| `FFMPEG_BINARY`, `FFMPEG_TIMEOUT` | `ffmpeg`, `60` | Recomendado: recomprime con poca memoria las fotos de más de 2 MB para Bluesky; sin él se usa GD (carga la imagen entera). `MEDIA_DOWNSCALE_BUDGET_SECONDS` (90) acota la pasada |


Los demás límites de tamaño y sondeo (`MEDIA_*`) no están en `.env.example`; ver [media](../modulos/media.md).

## Credenciales de las redes

| Variable | Explicación |
|---|---|
| `META_APP_ID`, `META_APP_SECRET` | App de Meta (caso de uso Facebook Login for Business) |
| `META_GRAPH_VERSION` | `v23.0`. Fijar versión evita sorpresas de deprecación |
| `META_MAX_PAGES` | `10`. Tope de páginas de `paging.next` (100 elementos por página) |
| `META_OAUTH_METHOD` | `post` (secretos en el cuerpo) o `get` (en la query; último recurso). ⚠️ **Por confirmar**: no consta que se haya probado `get` con Meta real (M-02) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Cliente web de Google Cloud. Consentimiento "En producción" para que el refresh token no caduque |
| `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | App de TikTok (sandbox o producción) |
| `TIKTOK_VERIFIED_DOMAIN` | Dominio verificado para fotos/carrusel; vacío = tipo no ofrecido. Ver [despliegue](../modulos/despliegue.md#tiktok-media-pública) |
| `BLUESKY_ENABLED` | `true`. `false` retira Bluesky de Conexiones, compositor, filtros, MCP y reprogramación (sus cuentas se conservan). Se conecta con una app password cifrada: [guía](../guias/bluesky-app-password.md) |
| `BLUESKY_IMAGE_MAX_BYTES`, `BLUESKY_VIDEO_MAX_BYTES` | `2000000` y `52428800` bytes. Topes de media; la foto mayor se recomprime y el vídeo mayor se rechaza al guardar |
| `BLUESKY_UPLOAD_TIMEOUT` | `80`. Segundos de la subida del vídeo (una petición dentro de los 85 s del paso del job de 110 s) |
| `BLUESKY_ENTRYWAY`, `BLUESKY_PLC_DIRECTORY`, `BLUESKY_APPVIEW`, `BLUESKY_VIDEO_SERVICE`, `BLUESKY_ALLOWED_HOSTS`, `BLUESKY_DEFAULT_LANGS` | Ver `config/social.php`. Hosts fijos (lista cerrada contra SSRF) e idioma por defecto (`es`); no hace falta tocarlos |
| `THREADS_APP_ID`, `THREADS_APP_SECRET` | ID y secreto **de Threads** de la app de Meta (hay dos pares; Configuración → Básica). Sin `THREADS_APP_ID` la red no se ofrece. Guía: [Threads](../guias/threads-app-meta.md) |
| `THREADS_ENABLED` | `true`. `false` retira Threads de Conexiones, compositor, filtros, MCP y reprogramación (sus cuentas se conservan) |
| `THREADS_AUTH_HOST`, `THREADS_GRAPH_HOST`, `THREADS_API_VERSION` | `https://threads.com`, `https://graph.threads.com` y `v1.0` (en `config/social.php`, por si Meta los cambia) |
| `THREADS_TOKEN_HEADER` | `false`. `true` manda el token de la API en `Authorization: Bearer` en lugar del cuerpo/query documentados; activarlo solo si el smoke (M-02) confirma que Threads lo acepta |
| `THREADS_PUBLISH_QUOTA` | `250`. Publicaciones por 24 h si la API no devuelve el total |

Redirect URIs: `${APP_URL}/connections/{meta,google,tiktok}/callback`.

## Refresco de tokens

| Variable | Defecto | Explicación |
|---|---|---|
| `SOCIAL_REFRESH_MARGIN_META_HOURS` | `168` | Renueva 7 días antes |
| `SOCIAL_REFRESH_MARGIN_GOOGLE_HOURS` | `1` | |
| `SOCIAL_REFRESH_MARGIN_TIKTOK_HOURS` | `6` | Access de 24 h |
| `SOCIAL_REFRESH_MARGIN_BLUESKY_HOURS` | `1` | La sesión de Bluesky dura minutos; se renueva con el refresh token |
| `SOCIAL_REFRESH_MARGIN_THREADS_HOURS` | `168` | Token largo de 60 días; la renovación exige ≥ 24 h de antigüedad |
| `SOCIAL_REFRESH_QUEUE` | `poll` | |
| `SOCIAL_HTTP_TIMEOUT` | `15` | Segundos por llamada a una red |
| `SOCIAL_REFRESH_MAX_TRANSIENT_FAILURES` | `12` | ≈ 6 h de fallos seguidos antes de pedir reconexión |

## Publicación

Estas variables también se pueden definir aunque no estén en `.env.example`; los valores salen de `config/publishing.php`.

| Variable | Defecto | Explicación |
|---|---|---|
| `PUBLISH_QUOTA_POLICY` | `reschedule` | ⚠️ **Por confirmar.** `reschedule` aplaza hasta que la red libere cupo; `fail` falla al instante con motivo "cuota". No está en `.env.example` |
| `PUBLISH_MAX_LATENESS_HOURS` (`max_lateness_hours`) | `24` | ⚠️ **Por confirmar.** Las vencidas con más retraso se marcan `fallida`. El diseño dice que tras una caída se publican las vencidas sin límite; 24 h es un valor prudente, no una decisión tomada. Súbelo si prefieres publicar siempre lo vencido |
| `PUBLISH_RECONNECT_GRACE_HOURS` | `72` | ⚠️ **Por confirmar.** Horas que un destino `procesando` espera a que reconectes la cuenta antes de fallar |
| `PUBLISH_UPLOAD_MAX_MINUTES` | `240` | Plazo de la fase de subida de vídeos grandes |
| `YOUTUBE_DAILY_QUOTA`, `YOUTUBE_UPLOAD_COST` | `10000`, `1600` | Cupo diario y coste por subida |
| `YOUTUBE_UPLOAD_BUDGET`, `YOUTUBE_PASS_SECONDS` | `80`, `100` | Presupuesto blando y marca dura (s) de una pasada; el job dura 110 s |
| `YOUTUBE_LOOKUP_MAX_PAGES` | `3` | Páginas (50 vídeos) para la idempotencia; 2 uds de cuota por página |
| `TIKTOK_UPLOAD_BUDGET`, `TIKTOK_PASS_SECONDS` | `80`, `100` | Igual que YouTube |
| `TIKTOK_UPLOAD_URL_TTL` | `55` | Minutos tras los que se reinicia la subida (la `upload_url` vale 1 h) |
| `TIKTOK_UPLOAD_RESTARTS` | `1` | Reinicios máximos |
| `TIKTOK_UPLOAD_MAX_MINUTES` | `120` | Plazo de subida de TikTok |
| `TIKTOK_INBOX_GRACE_MINUTES` | `10` | ⚠️ **Por confirmar** (producto): tras estos minutos sin confirmación, el borrador de TikTok queda `publicada` con la nota "revisa tus borradores" |

Otros ajustes de `config/publishing.php` sin variable en `.env.example`: `PUBLISH_MAX_ATTEMPTS` (4), `PUBLISH_STUCK_AFTER_MINUTES` (10), `PUBLISH_POLL_INTERVAL` (30 s), `PUBLISH_POLL_MAX_MINUTES` (30), `PUBLISH_DISPATCH_BATCH` (200), `PUBLISH_MEDIA_URL_TTL` (720 min).

## Informes

| Variable | Defecto | Explicación |
|---|---|---|
| `REPORTS_PDF_DRIVER` | `dompdf` | Único driver hoy ([ADR-002](../knowledge/adr/ADR-002-pdf-dompdf.md)) |
| `REPORTS_BRAND_COLOR` | `#4f46e5` | Color de portada y gráficos |
| `REPORTS_LOGO_PATH` | vacío | Ruta absoluta a PNG/JPG local |
| `REPORTS_FOOTER` | vacío | Pie del PDF |
| `REPORTS_CSV_LOCALE` | `es` | `es` = `;` y coma decimal; `en` = `,` y punto |

⚠️ **Por confirmar** (M-03): las secciones por defecto del informe (`cover`, `summary`, `networks`, `top_posts`, `notes`, en `config/reports.php`) y el branding. No hay variable: se cambian en el fichero.

## Variables de MCP y analítica (fuera de `.env.example`)

| Variable | Defecto | Explicación |
|---|---|---|
| `MCP_RATE_PER_MINUTE`, `MCP_RATE_PER_MINUTE_IP` | `60`, `120` | Límites del MCP. Ajustar en producción (M-F6-2) |
| `MCP_ALLOWED_ORIGINS` | vacío | Orígenes de navegador extra |
| `MCP_KEY_DEFAULT_DAYS`, `MCP_KEY_MAX_DAYS` | `365`, `730` | Vida de las API keys |
| `SANCTUM_TOKEN_PREFIX` | `msk_` | Prefijo de las keys |
| `MCP_OAUTH_TOKEN_DAYS` | `90` | Duración preseleccionada del acceso OAuth. El usuario elige 30, 90, 180 o 365 días en el consentimiento; la lista y la vida real se topan en `MCP_KEY_MAX_DAYS` |
| `MCP_OAUTH_CODE_TTL` | `300` | Vida del código de autorización, en segundos |
| `MCP_OAUTH_REDIRECT_ALLOWED` | vacío | Prefijos `https://` extra permitidos como `redirect_uri` al registrar clientes (CSV) |
| `MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR` | `200` | Tope GLOBAL de registros dinámicos de clientes por hora (además de 10/h por IP) |
| `GOOGLE_LOGIN_CLIENT_ID`, `GOOGLE_LOGIN_CLIENT_SECRET` | vacío | Credenciales PROPIAS del login social con Google (distintas de `GOOGLE_CLIENT_*`). Vacías = proveedor desactivado. Redirect URI: `<APP_URL>/auth/google/callback` |
| `META_LOGIN_APP_ID`, `META_LOGIN_APP_SECRET` | vacío | Credenciales PROPIAS del login social con Facebook (distintas de `META_APP_*`). Vacías = proveedor desactivado. Redirect URI: `<APP_URL>/auth/facebook/callback` |
| `SOCIAL_ANALYTICS_DAILY_AT` | `12:00` (UTC) | Hora del snapshot diario |
| `SOCIAL_ANALYTICS_SETTLE_DAYS` | `3` | Días de reintento de snapshots incompletos |
| `SOCIAL_BACKFILL_ON_CONNECT` | `true` | Histórico inicial al conectar |
| `SOCIAL_BACKFILL_DAYS_{INSTAGRAM,FACEBOOK,YOUTUBE,THREADS}` | `30`, `90`, `365`, `30` | Días de histórico |

## Resumen de decisiones por confirmar

| Decisión | Valor por defecto | Dónde se cambia |
|---|---|---|
| `PUBLISH_QUOTA_POLICY` | `reschedule` | `.env` |
| `max_lateness_hours` | 24 h | `PUBLISH_MAX_LATENESS_HOURS` |
| `PUBLISH_RECONNECT_GRACE_HOURS` | 72 h | `.env` |
| `META_OAUTH_METHOD` | `post` | `.env` |
| Borrador TikTok = `publicada` con nota | activo | `TIKTOK_INBOX_GRACE_MINUTES` y `TikTokPublisher` |
| Secciones del informe | 5 secciones | `config/reports.php` |

## Paridad con Metricool (P2–P4)

Variables nuevas, todas opcionales (valores por defecto en la segunda columna). Los permisos de Meta de esta fase hay que activarlos en los casos de uso de la app y **reconectar** las cuentas.

| Variable | Por defecto | Efecto |
|---|---|---|
| `META_EXTRA_SCOPES` | vacío | Permisos opcionales de Meta separados por comas que se añaden al login: `pages_read_user_content` (comentarios de Facebook en el Inbox) y `ads_read` (Meta Ads). Ponlos **solo** tras activarlos en la app de Meta y antes de reconectar: si no, Meta rechaza el login entero («Invalid Scopes»). `instagram_manage_comments` y `pages_manage_engagement` ya van en el login normal |
| `REGISTRATION_ENABLED` | `true` | `false` cierra `/register` (la ruta no existe) y oculta «Crear cuenta». Recomendado `false` en producción de uso personal; las cuentas se crean por consola |
| `SMARTLINKS_REPORT_EMAIL` | `MAIL_FROM_ADDRESS` | Destino del enlace «Denunciar esta página» de las páginas públicas `/l/{slug}` |
| `SOCIAL_AUTOLISTS_DAILY_AT` / `_HORIZON_DAYS` / `_QUEUE` | `02:00` / `7` / `poll` | Hora UTC del reparto diario, días que se generan por delante y cola. El job programado solo reparte: ejecuta un `GenerateAutolistPosts` **por lista** |
| `SOCIAL_INBOX_SYNC_MINUTES` | `15` | Frecuencia de sincronización de comentarios |
| `SOCIAL_INBOX_POST_DAYS` / `_MAX_POSTS` / `_COMMENTS_PER_POST` | `30` / `25` / `50` | Publicaciones recientes que se sincronizan y comentarios por página de Graph |
| `SOCIAL_INBOX_MAX_PAGES` / `_MAX_REPLY_PAGES` | `5` / `3` | Tope de páginas `paging.next` que se siguen por publicación y por hilo de respuestas |
| `SOCIAL_INBOX_BUDGET_SECONDS` | `60` | Presupuesto de tiempo por pasada del job (< `timeout` 90 s); si no caben todas las publicaciones, el job se reencola con el desplazamiento |
| `SOCIAL_BEST_TIMES_DAYS` / `_MIN_POSTS` / `_CACHE_MINUTES` | `90` / `10` / `10` | Ventana del mapa de calor, mínimo de publicaciones con métricas y caché corta (0 = sin caché) |
| `SOCIAL_COMPETITORS_DAILY_AT` / `_MAX` | `13:00` / `10` | Hora UTC del snapshot diario de competidores y máximo por marca |
| `SOCIAL_ADS_CACHE_MINUTES` | `10` | Caché de las respuestas de Meta Ads |
| `AI_ASSISTANT_ENABLED` + `ANTHROPIC_API_KEY` | `false` / vacío | El asistente «Sugerir texto» solo existe si **ambas** están definidas |
| `AI_ASSISTANT_DAILY_LIMIT` / `_GLOBAL_DAILY_LIMIT` | `20` / `200` | Sugerencias por usuario y día, y tope global diario entre todos los usuarios (0 = sin tope global) |
| `AI_ASSISTANT_MODEL` / `_MAX_TOKENS` / `_TIMEOUT` | `claude-sonnet-5-5` / `600` / `30` | Modelo y límites de la llamada a la Messages API |
| `AI_ASSISTANT_SCHEDULE_MAX_DAYS` | `365` | «Escribe cuándo»: la fecha propuesta no puede quedar a más de estos días |
