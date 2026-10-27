# Decisiones por red (Meta, YouTube, TikTok, Bluesky)

Resumen del spike de plataformas (2026-10-19) y de la decisión G1. Fuente completa y evidencia: [spike-report.md](../roadmap/2026-10-19-social-scheduler/spike-report.md).

## Veredictos

| Red | Publicar | Insights | Veredicto | Riesgo principal |
|---|---|---|---|---|
| Facebook | foto en Página (modo desarrollo) | `page_media_view`, `page_post_engagements`, `page_follows` | viable | métricas deprecadas; `data_access` caduca cada 90 días |
| Instagram | imagen (contenedor → `media_publish`) | cuenta y media con `views` | viable | exige IG profesional enlazada a una Página; reel y story no probados en el spike |
| YouTube | vídeo `public` sin forzar a privado | canal (Analytics API) | viable | refresh token caduca a 7 días en "Testing"; ~6 subidas/día |
| TikTok | Direct Post público bloqueado sin auditoría; borrador a bandeja aceptado | sin scope de analítica sin auditoría | limitado | publicar en abierto exige auditoría; vídeos >= 23 fps |

## Decisión G1 (2026-10-19)

Tomada por el orquestador con delegación expresa del usuario. Ninguna tarea se canceló.

| Red | Decisión | Consecuencia en el código |
|---|---|---|
| Meta | Continuar sin cambios | `MetaConnector`, publicadores de IG y FB |
| YouTube | **A**: continuar con consentimiento "En producción" sin verificar | El refresh token deja de caducar a 7 días; se acepta el aviso "app no verificada" al conectar. Scopes `youtube`, `youtube.readonly`, `yt-analytics.readonly` |
| TikTok | **D + B**: borrador ahora, auditoría en paralelo | Por defecto `inbox/video/init`; Direct Post tras opción por cuenta `meta.direct_post` |

## Meta (Facebook + Instagram)

Verificado en el spike (app en modo desarrollo, usuario admin):

- Basta el modo desarrollo para publicar en cuentas propias: FB foto OK (`post_id` `1065269806670394_122137968398744480`) e IG imagen OK.
- Facebook Login for Business funciona con `/dialog/oauth` + `scope`, sin `config_id`.
- Las Páginas de portfolios empresariales **no** salen en `me/accounts`: hay que pedir `business_management` y leer `me/businesses` → `owned_pages`/`client_pages`.
- En apps por casos de uso, cada permiso se añade dentro del caso de uso.
- Métricas: `page_impressions` y `page_fans` rechazadas (`(#100) must be a valid insights metric`); en IG `impressions` ya no existe, se usa `views`.
- Token de larga duración OK; `data_access_expires_at` a 90 días, se renueva con re-login.
- Borrar un medio de IG exige `instagram_manage_contents`; por eso está en los scopes de `config/social.php`.

Limitaciones: la cuenta debe estar en modo desarrollo con roles propios mientras no pase App Review; ⚠️ verificar publicación real de reel, story y FB texto (M-01).

## YouTube

- Subida de `Libre.mp4` pidiendo `public`: quedó `public` tras `processed`, sin forzarse a privado. ⚠️ Re-verificar a las 24 h con `--inspect` y en YouTube Studio.
- Cuota: 10.000 ud/día, ~1.600 por subida (≈ 6 subidas/día). La cuota es por proyecto de Google.
- En "Testing" hace falta estar en "Usuarios de prueba"; el alta de test user desde la consola no persistió en los primeros intentos: recargar y comprobar.
- `youtube.upload` no permite borrar (403 `insufficientPermissions`): por eso se pide el scope `youtube`.
- Consentimiento pasado a "En producción" el 2026-10-19; requiere página principal, `/privacy` y `/terms` publicadas (ver [pendientes](pendientes-manuales.md)). Los tokens emitidos en "Prueba" siguen caducando a 7 días: reconectar una vez.

## TikTok

- Scopes concedidos en sandbox: `user.info.basic`, `video.publish`, `video.upload`. `creator_info/query` devuelve `PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, `SELF_ONLY`.
- `creator_info/query` exige cuerpo JSON objeto `{}`; con `[]` devuelve `invalid_params`.
- Publicar con `PUBLIC_TO_EVERYONE` sin auditoría falla con `unaudited_client_can_only_post_to_private_accounts`. Sin auditoría solo se publica si la cuenta es privada.
- Tokens: access 24 h, refresh 365 días.
- Vídeos < 23 fps: `frame_rate_check_failed`.
- Analítica: sin `user.info.stats`/`video.list` concedidos, queda fuera de v1.

### Cómo funciona hoy

`TikTokPublisher` sube el vídeo a la bandeja (`/v2/post/publish/inbox/video/init/`, `FILE_UPLOAD` por trozos). El destino queda `publicada` con una nota; el usuario termina de publicar desde la app de TikTok. Las fotos/carrusel solo se ofrecen con `TIKTOK_VERIFIED_DOMAIN`.

### Activar Direct Post tras la auditoría

1. Presentar la auditoría de TikTok con política de privacidad y condiciones en el dominio real, y un vídeo demo.
2. Esperar la aprobación y confirmar que `video.publish` figura concedido.
3. Reconectar la cuenta si el scope cambió.
4. En *Conexiones* de la marca, activar la opción Direct Post de la cuenta (`PATCH brands/{brand}/connections/{account}/direct-post`; guarda `meta.direct_post = true`).
5. Publicar una prueba. Desde ese momento el mismo post se publica directamente, sin pasar por la bandeja.

Si TikTok responde `unaudited_client_can_only_post_to_private_accounts`, el destino falla de forma permanente: desactiva la opción.

## Bluesky

Decisiones de la tercera ola (opción O1 del diseño: contrato segregado y catálogo de redes en `Network`):

- **Conexión (D1):** handle + app password, guardada cifrada (`social_accounts.credential_secret`). La sesión está aislada en `BlueskySession` para poder cambiarla por OAuth de atproto sin tocar publicador ni métricas.
- **Límites** (lexicon vigente, revisado el 2026-10-26): texto de 300 grafemas y 3000 bytes; hasta 4 imágenes de **2.000.000 bytes** cada una (el lexicon decía 1 MB antes; si un PDS antiguo rechaza el blob, bajar `BLUESKY_IMAGE_MAX_BYTES` a 1.000.000); vídeo MP4 (el lexicon admite 300 MB).
- **Tope de vídeo de esta instalación (D3, decisión del usuario 2026-10-27):** unos **50 MB** (`BLUESKY_VIDEO_MAX_BYTES`, 52.428.800). La subida es una sola petición dentro de los 85 s del paso del job (110 s; worker de 120 s): con unos 5 Mbps de subida, 50 MB caben en unos 80 s. Un vídeo mayor se rechaza al guardar. Hacen falta además el email de la cuenta verificado y cupo diario (`getUploadLimits`).
- **Idempotencia:** `rkey` TID guardada antes de `createRecord`; `getRecord` antes de fallar.
- **Métricas:** solo seguidores y posts de cuenta; likes, respuestas, reposts y citas por post; sin visualizaciones ni alcance (`null`).
- **Pendiente de verificar en el smoke real (T-30, M-01):** `aud` del `getServiceAuth` de subida de vídeo, error del PDS con `rkey` repetida y host público del AppView.

## Threads

Decisiones de la tercera ola (opción T1 del diseño: conector `threads` propio, sin heredar de Instagram ni tocar `InstagramPublisher`). Verificado en la documentación oficial de Meta (`developers.facebook.com/docs/threads`, páginas renderizadas en el navegador) el **2026-10-27**. Variables: `THREADS_ENABLED`, `THREADS_APP_ID`, `THREADS_APP_SECRET`; guía de alta: [threads-app-meta.md](../guias/threads-app-meta.md).

### Tabla de verificación (T-54)

| Punto (⚠️ del diseño) | Resultado | Valor fijado | Fuente (actualizada) |
|---|---|---|---|
| Texto máximo | Verificado | 500 caracteres | «Threads Posts» (13 oct 2026) |
| Regla de emojis | Verificado la frase; la interpretación para secuencias (ZWJ, banderas) **no** | «los emojis cuentan el número de bytes UTF-8». `TextLength` suma los bytes de cada símbolo pictográfico y 1 por el resto; una familia compuesta cuenta cada pictograma con sus bytes más 1 por cada unión | «Threads Posts»; comprobar con texto al límite en el smoke (M-02) |
| Carrusel | Verificado | de 2 a 20 elementos (imagen, vídeo o mezcla); cuenta 1 contra la cuota | «Threads Posts» |
| Cuota de publicación | Verificado | 250 publicaciones por 24 h móviles; se lee en `GET /{user}/threads_publishing_limit?fields=quota_usage,config` (`config.quota_total`, `quota_duration`) | «Threads API overview» (22 dic 2025) |
| Campos de `/me` | Verificado | `id`, `username`, `name`, `threads_profile_picture_url` (además `threads_biography`, `is_verified`) | «Threads Profiles» (13 abr 2026) |
| *Permalink* | Verificado | `GET /{media-id}?fields=permalink` (se omite si el contenido tiene copyright marcado) | «Retrieve User Posts» (14 abr 2026) |
| Métricas del post | Verificado | `views`, `likes`, `replies`, `reposts`, `quotes`, `shares`; `views` y `shares` figuran «en desarrollo» (→ `null` si no llegan); vacío para `REPOST_FACADE` | «Threads Insights API» (30 ene 2026) |
| Métricas de cuenta | Verificado | `views` = **visitas al perfil** (serie diaria), `likes`, `replies`, `reposts`, `quotes`, `clicks` (por enlace), `followers_count` (sin `since`/`until`); rango no antes del 13-04-2024 (1712991600) | «Threads Insights API» |
| ¿Basta el modo desarrollo? | Verificado en la documentación; **falta el smoke** | Sin App Review solo se publica y se leen datos de *testers* de la app; para otros usuarios hace falta aprobar `threads_content_publish` y publicar la app | «Threads Posts» y «Get started» |
| Hosts y OAuth | Verificado | autorización `https://threads.com/oauth/authorize`; API `https://graph.threads.com` (la doc admite también `graph.threads.net`); canje `POST /oauth/access_token` (`grant_type=authorization_code`); largo `GET /access_token?grant_type=th_exchange_token` (60 días); renovación `GET /refresh_access_token?grant_type=th_refresh_token` (token de ≥ 24 h y no caducado). La respuesta del canje trae `user_id` | «Get access tokens» y «Long-lived tokens» |
| Dos app IDs | Verificado | La app lleva dos IDs y secretos; se usan los de **Threads** (Configuración > Básica) | «Get started» |
| Permisos | Verificado | `threads_basic`, `threads_content_publish`, `threads_manage_insights` (más `threads_read_replies`/`threads_manage_replies`, no usados) | «Get started» |
| Estado del contenedor | Verificado | `GET /{container}?fields=status,error_message` → `IN_PROGRESS`, `FINISHED`, `ERROR`, `EXPIRED` (24 h), `PUBLISHED`; errores: `FAILED_DOWNLOADING_VIDEO`, `FAILED_PROCESSING_AUDIO`, `FAILED_PROCESSING_VIDEO`, `INVALID_ASPEC_RATIO` (sic), `INVALID_BIT_RATE`, `INVALID_DURATION`, `INVALID_FRAME_RATE`, `INVALID_AUDIO_CHANNELS`, `INVALID_AUDIO_CHANNEL_LAYOUT`, `UNKNOWN`. Se recomienda sondear 1 vez por minuto, 5 minutos como máximo, y esperar ~30 s antes de publicar | «Troubleshooting» (14 jul 2025) y «Threads Posts» |
| Imagen | Verificado | JPEG y PNG, 8 MB, relación máxima 10:1, ancho 320–1440 px (la red reescala), sRGB | «Threads Posts» |
| Vídeo | Verificado | MOV o MP4 (sin edit lists, moov al inicio), H.264/HEVC, AAC, 23–60 fps, ancho ≤ 1920, relación 0,01:1–10:1, ≤ 100 Mbps, de más de 0 s a 300 s, 1 GB | «Threads Posts» |
| Enlaces | Verificado (nuevo) | Máximo 5 enlaces únicos por post de texto; más fallan con `THREADS_API__LINK_LIMIT_EXCEEDED` (desde el 22-12-2025) | «Threads Posts» |
| Máximo de *hashtags* | Verificado | No hay tope: solo **una** etiqueta de tema por post (la primera válida del texto o `topic_tag`, de 1 a 50 caracteres, sin `.` ni `&`). Las demás quedan como texto. No se fija `max_hashtags` | «Threads Posts» |
| Códigos de error de Graph propios de Threads | **No verificado** | Se reutilizan `REAUTH_CODES` y `RATE_LIMIT_CODES` de Graph (`190`, `4`, `17`, `613`…); la doc de Threads no los lista. Comprobar en el smoke | — |
| Límite de llamadas | Verificado | `4800 × impresiones` en 24 h (mínimo 10 impresiones) por par app-usuario; se trata como transitorio | «Threads API overview» |

### Cómo funciona

- **Conexión:** OAuth en `threads.com` (red `threads`, proveedor `threads`, con `THREADS_APP_ID`/`THREADS_APP_SECRET` propios). Token corto (1 h) → largo (60 días) guardado en `access_token` con `token_expires_at`; `refresh_token` es `null` y `RefreshTokens` renueva con `th_refresh_token` antes del margen (168 h).
- **Interruptor:** `THREADS_ENABLED=false` o sin `THREADS_APP_ID` retira la red de Conexiones, compositor, filtros, MCP y reprogramación (como `BLUESKY_ENABLED`).
- **Publicación:** texto, imagen, vídeo y carrusel (2 a 20) con contenedores y estado (`ThreadsPublisher`, sin heredar de Instagram), cuota de 250 por 24 h consultada antes de gastar contenedor y búsqueda de lo ya publicado por texto y hora (`findPublished`). Detalle en [publicación](../modulos/publicacion.md#threads).
- **Métricas:** `views` de cuenta son visitas al perfil (`profile_views`), no visualizaciones; clics en la nueva métrica `clicks`; `views` y `shares` del post «en desarrollo» (`null` si no llegan). Detalle en [analítica](../modulos/analitica.md#threads).
- **Hosts configurables:** `THREADS_AUTH_HOST` (`https://threads.com`), `THREADS_GRAPH_HOST` (`https://graph.threads.com`) y `THREADS_API_VERSION` (`v1.0`).
- **Límites en la configuración:** `config/media_specs.php` (`threads_text`, `threads_image`, `threads_video`, `threads_carousel`, versión 2026-10-27) y `config/social.php` (`threads`: `publish_quota` 250, margen de renovación 168 h, histórico de 30 días).
- **Pendiente del smoke (T-64, M-02):** modo desarrollo con *testers*, regla de emojis en secuencias, códigos de error propios, `shares`/`views` de post, zona del día de las métricas y si Threads acepta `Authorization: Bearer`.
