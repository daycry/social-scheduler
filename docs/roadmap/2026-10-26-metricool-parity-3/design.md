---
design: "metricool-parity-3"
titulo: "Paridad con Metricool, tercera ola: integración de Bluesky y Threads"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-26"
actualizado: "2026-10-27"
spec: spec.md
evaluacion: "evaluation.md"
plan: improvement-plan.md
adr: "docs/knowledge/candidates/pending/2026-10-27-conectores-contrato-segregado-y-catalogo-de-redes.md"   # candidato pending (sha256 ac5976ab…ee9db); lo decide el Curator
opcion_elegida: "O1+T1"       # C-01: O1 · C-02: T1 (validada por el usuario el 2026-10-27)
generacion:
  inicio: 2026-10-26T22:00:47Z
  fin: 2026-10-26T23:42:47Z   # dos marcadores: pasada 1 (22:00:47-22:12:34) y pasada 2 (23:40:31-23:42:47), sumados
  fuente: medido
  tokens_reales: { entrada: 102, salida: 52616, cache_creacion: 209729, cache_lectura: 5674506 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.88
  duracion: 53m           # reloj: 14m
  ratio_usado: 300000     # default no calibrado
---

# Diseño: paridad con Metricool, tercera ola (Bluesky y Threads)

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) (go, D1-D6 resueltas) · **Plan:** [`improvement-plan.md`](improvement-plan.md) · **ADR:** candidato pendiente de curación ([`2026-10-27-conectores-contrato-segregado-y-catalogo-de-redes.md`](../../knowledge/candidates/pending/2026-10-27-conectores-contrato-segregado-y-catalogo-de-redes.md))

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | C-01: **O1**, contrato segregado y catálogo de redes en `Network` · C-02: **T1**, conector `threads` propio |
| **Validada por el usuario** | 2026-10-27 (vía `/dev-cycle` 2-a: `elegida: O1` y `T1`; preguntas 3 → (b) y 4 → (a)) |

Este diseño cubre solo lo que la evaluación marca como riesgo arquitectónico: **C-01 Bluesky** (opciones O1 a O3) y **C-02 Threads** (opciones T1 a T3). Las opciones de C-02 se leen sobre la que se elija en C-01. C-03 y C-04 solo llevan una nota de impacto (§5.3).

## 1. Contexto y restricciones

La spec pide dos redes nuevas:

- **Bluesky:** se conecta con *app password* (D1) y publica texto, de 1 a 4 imágenes y vídeo. Lleva *facets*, idempotencia por `rkey` y métricas.
- **Threads:** usa OAuth propio y publica texto, imagen, vídeo y carrusel. Tiene cuota de 250 publicaciones cada 24h y métricas.

Las dos deben aparecer en el calendario, el dashboard, los informes y el MCP (CA-01..CA-10).

**Lo que hay hoy en el repo (hechos con ruta):**

- **Contrato de conector solo OAuth.** `app/Connectors/Contracts/Connector.php:20-54` obliga a implementar `authorizationUrl()` y `exchangeCode()`. Solo los usa `app/Http/Controllers/OAuth/OAuthController.php:47,91`.
  - `refresh()` y `refreshMarginHours()` los usa `app/Jobs/RefreshSocialAccount.php:70,120`.
  - La desconexión no pasa por el conector (`SocialAccountService::disconnect`, `app/Services/SocialAccountService.php:92`).
- **El registro ya separa las capacidades por red.** `app/Connectors/ConnectorRegistry.php` guarda el `Publisher` y el `InsightsReader` de cada red. El `StatusPoller` y el `FirstCommenter` se detectan con `instanceof`. Todo se registra sin condiciones en `app/Providers/AppServiceProvider.php:52-66`.
- **Credenciales.** `app/Models/SocialAccount.php:41-56` tiene `access_token` y `refresh_token` cifrados y ocultos. No hay sitio para un secreto adicional, como la app password.
  - `RefreshSocialAccount::permanentReason()` (`:76-87`) marca la reconexión si `refresh_expires_at` ya pasó.
- **Publicación asíncrona ya resuelta.**
  - `PublishResult::processing()` y `UPLOADING` (`app/Connectors/Data/PublishResult.php`);
  - `PollPublishStatus` (`app/Jobs/PollPublishStatus.php:86-128`), con plazo de subida y de procesado;
  - `StepBudget`, con 85 s por paso (`app/Connectors/Support/StepBudget.php`);
  - `PublishRequest::checkpoint()`, que persiste la referencia y el progreso antes de cada paso irreversible;
  - `MediaChunkReader`, que lee en streaming.
  - El job tiene `$timeout = 110` y el worker `--timeout=120` (`docs/modulos/despliegue.md:113,147`).
- **Patrón de contenedor de Instagram.** `app/Connectors/Meta/Instagram/InstagramPublisher.php` cubre los contenedores, el carrusel con `progress` y la cuota (`assertQuota`, `:446`, con `content_publishing_limit`).
  - `app/Connectors/Meta/GraphClient.php:159` fija el host `graph.facebook.com` y traduce los subcódigos de IG.
- **Listas fijas de redes** (recon, además de las de la spec):
  - backend:
    - `Network.php:15-38`, con `providers()` fijo;
    - `routes/web.php:56,58`;
    - `AiAssistant.php:86` y `AiAssistantController.php:30`;
    - `Mcp/Tools/{GetStats:36, GetCalendar:35, GetBestTimes:35}`, solo en las descripciones;
    - `Metric::EXPECTED_FLOWS` (`app/Analytics/Metric.php:63-68`);
    - `AnalyticsService::SUPPORTED` (`:58-63`);
    - `ComposerPresetService.php:23-24`;
    - `InboxController.php:29,91` y `ListComments.php:47`, que son capacidad de inbox y no lista de redes;
  - frontend:
    - `NetworkIcon.vue:28`, `Calendar/Index.vue:36`, `Dashboard/Partials/{NetworkTabs.vue:20, format.js:5}`, `Welcome.vue:19` y `composerOptions.js:43`;
  - tests: `tests/Unit/MediaValidator/MediaValidatorTest.php:35`, que espera 13 reglas.
  - **Ya existe una fuente central parcial:** `HandleInertiaRequests.php:106` comparte `shell.networks` (valor, etiqueta y proveedor) a partir de `Network::cases()`. La usan `NetworkChip.vue:13` y `Dashboard/Network.vue:25`.
  - También salen de `Network::cases()`:
    - `BestTimesController`, `ConnectionController:53`, `PostController:203` y `CalendarService:84`;
    - `Period::defaultEnd`, a través de `Days::closed`, que lee `social.analytics.timezones.<red>` con UTC por defecto.
- **Conteo de texto.** `MediaValidator::validateText` usa `mb_strlen` (`app/Services/MediaValidator.php:120`). El compositor recibe `max_chars` por tipo (`PostController.php:213-217`), y el MCP valida con el mismo `MediaValidator`.

**Verificado en la documentación oficial el 2026-10-26:**

*Bluesky*, en los lexicons canónicos de `bluesky-social/bsky` y `bluesky-social/atproto`:

- `app.bsky.feed.post` tiene clave `tid`, `text` de 300 grafemas y 3000 bytes como máximo, y `langs` de hasta 3.
- Las imágenes de `app.bsky.embed.images` pueden pesar **2.000.000 bytes** (el lexicon dice «antes 1 MB»), con 4 como máximo.
- El vídeo de `app.bsky.embed.video` es MP4 de hasta **300 MB** (antes 100 MB) y admite `presentation: gif`.
- `app.bsky.video.uploadVideo` recibe el cuerpo `video/mp4` en **una sola petición**.
- `jobStatus` tiene los estados `JOB_STATE_*` (`COMPLETED`/`FAILED`), `blob` y `failureCode`.
- `getUploadLimits` devuelve `canUpload`, `remainingDailyVideos` y `remainingDailyBytes`.
- `createRecord` acepta un `rkey` opcional, y solo `(did, collection, rkey)` es único.
- `getRecord` devuelve el error `RecordNotFound` si no existe.
- `createSession` devuelve `accessJwt`, `refreshJwt`, `did`, `didDoc` y `emailConfirmed`.
- `refreshSession` puede fallar con `ExpiredToken`, `InvalidToken` o `AccountTakedown`.
- `getServiceAuth` recibe `aud`, `exp` y `lxm`.
- Los *facets* van en un `byteSlice` en **bytes UTF-8**, con `mention` (DID), `link` y `tag` (64 grafemas como máximo).
- `postView` tiene `likeCount`, `repostCount`, `replyCount` y `quoteCount`. `getPosts` admite 25 URIs como máximo, y el perfil da `followersCount` y `postsCount`.
- La TID es de 13 caracteres en base32-sortable.

*Threads* (developers.facebook.com/docs/threads):

- OAuth en `threads.com/oauth/authorize`. El canje se hace con `POST graph.threads.com/oauth/access_token`, con el ID y el secreto **propios de Threads** («hay 2 app IDs»).
- El token corto dura 1h; el largo se obtiene con `th_exchange_token` y dura 60 días.
- `refresh_access_token` con `th_refresh_token` exige que el token tenga al menos 24h y no haya caducado.
- Los contenedores se crean con `POST /{user}/threads` (`TEXT`, `IMAGE`, `VIDEO` o `CAROUSEL`, con `is_carousel_item` y `children`, de 2 a 20). Se publican con `threads_publish` y `creation_id`, y se recomienda esperar unos 30 s.
- El estado del contenedor es `IN_PROGRESS`, `FINISHED`, `ERROR`, `EXPIRED` (24h) o `PUBLISHED`, con `error_message`. Se recomienda sondear una vez por minuto, durante 5 minutos como máximo.
- El texto admite **500 caracteres, y cada emoji cuenta sus bytes UTF-8**.
- El límite es de 250 publicaciones cada 24h (el carrusel cuenta 1). Se consulta en `threads_publishing_limit` (`quota_usage` y `config`).
- Imágenes: JPEG o PNG de 8 MB. Vídeos: MOV o MP4 en H.264 o HEVC, de 23 a 60 fps, 300 s y 1 GB como máximo.
- Insights del post (`lifetime`): `views`, `likes`, `replies`, `reposts`, `quotes` y `shares`. Los de la cuenta admiten `since`/`until`: `views` (las visitas al **perfil**, por día), `likes`, `replies`, `reposts`, `quotes`, `clicks` y `followers_count` (este sin rango).
- Los *testers* invitados conceden los permisos sin App Review.

**Restricciones que fijan el espacio de soluciones**

- **Worker de 120 s.** El job dura 110 s y cada paso tiene 85 s de `StepBudget`. No hay ffmpeg para transcodificar. HestiaCP corre con `MEDIA_DISK=local` (evaluación, §Datos).
- **D1: app password cifrada**, aislada en una clase de sesión que se pueda cambiar por OAuth de atproto. **D2:** app de Threads propia con *tester* y sin App Review.
- **No romper Meta, Google ni TikTok.** Sus 3 conectores, `OAuthController` y `RefreshSocialAccount` deben seguir pasando su regresión sin cambiar su comportamiento.
- **Paridad web y MCP.** Toda regla de texto o media vive en `MediaValidator` y `config/media_specs.php`, que comparten el compositor y `create_post`.
- **`null` = «no disponible», nunca 0** (contrato de `InsightsReader`).
- **No hay constitución** (`docs/CONSTITUTION.md` no existe). Los ADR-001..005 están en `propuesta` o `aceptada` y ninguno fija los conectores. ADR-005 (núcleo de publicación) se respeta: no se cambia `PublishPostTarget` ni `PollPublishStatus`.

## 2. Opciones (2-3)

### Común a todas las opciones de C-01: mecánica de Bluesky

Estas decisiones no cambian entre O1, O2 y O3. Se fijan aquí y la pasada 2 las lleva al ADR.

- **Sesión (`BlueskySession`):**
  - resuelve el handle a su DID con `com.atproto.identity.resolveHandle` en `social.bluesky.entryway`;
  - lee el `didDoc` para obtener el PDS y llama a `createSession` contra ese PDS.
  - **Qué se guarda:**
    - `access_token` = `accessJwt` y `refresh_token` = `refreshJwt`;
    - **columna nueva `credential_secret`**, cifrada y oculta, con la app password;
    - `external_id` = DID, que es estable aunque cambie el handle;
    - en `meta`: `did`, `pds`, `handle` y `email_confirmed`.
  - `token_expires_at` sale del `exp` del `accessJwt`, que se lee sin verificar la firma porque es solo para planificar.
  - `refresh_expires_at` **queda en `null`**: si no, `permanentReason()` marcaría la reconexión cuando aún se puede recrear la sesión.
  - **Renovación:** primero `refreshSession`; si responde `ExpiredToken` o `InvalidToken`, `createSession` con la app password; si esta también falla, `ReconnectRequiredException`.
  - **Bloqueo por cuenta** (`Cache::lock("bsky-session:{id}")`) compartido entre `RefreshSocialAccount` y el publicador. Así dos procesos no rotan el `refreshJwt` a la vez.
  - El publicador también renueva **bajo demanda** si una llamada responde `ExpiredToken`. El token de acceso dura poco ⚠️ (no publicado) y `RefreshTokens` corre cada 30 min.
- **Texto:**
  - nuevo modo de conteo por regla, `text.count` en `media_specs`, con tres valores: `chars` por defecto (`mb_strlen`, como hoy), `graphemes` para Bluesky (`grapheme_strlen`) y `threads` para Threads (caracteres, con cada emoji contado por sus bytes UTF-8 ⚠️; la regla exacta está por confirmar);
  - una sola implementación, `App\Support\TextLength`, con su espejo en `resources/js/Composables/textLength.js` (`Intl.Segmenter`);
  - `PostController` expone el modo junto a `max_chars`;
  - en Bluesky se valida además el tope de 3000 bytes del lexicon.
- **Facets:** `App\Connectors\Bluesky\Facets` calcula los índices en bytes con `strlen` del prefijo UTF-8.
  - **URL** → `link`.
  - **`#tag`** → `tag`, sin `#` y con 64 grafemas como máximo; descarta los números puros y la puntuación final.
  - **`@handle`** → `mention`, solo si `resolveHandle` lo resuelve; si no, se deja como texto plano.
  - La extracción de hashtags se comparte con C-04a (§5.3).
  - `langs` sale de `social.bluesky.default_langs`.
- **Imágenes:**
  - `uploadBlob` por imagen, con los bytes leídos del disco;
  - **recompresión con GD** a una copia derivada en `MediaStorage`, cacheada por media y versión de la regla;
  - la copia se intenta **al validar**: si no baja del tope, falla el guardado (spec, §Errores) y el publicador reutiliza la copia;
  - va `aspectRatio` en cada imagen;
  - el tope es `social.bluesky.image_max_bytes`, **2.000.000 bytes por defecto** (lexicon vigente; decisión del usuario, 2026-10-27). Si un PDS antiguo rechazara el *blob*, se baja a 1.000.000 por configuración.
- **Idempotencia:**
  - en el primer intento se genera una **TID válida** (marca de tiempo actual más un *clock id* aleatorio de 10 bits) y se **persiste con `checkpoint()` antes** de `createRecord`;
  - los reintentos reutilizan esa `rkey`, y `findPublished` hace `getRecord` (`RecordNotFound` → `null`);
  - no se deriva del ID del destino: una TID con fecha antigua es válida, pero desordena el `rkey`;
  - ⚠️ falta comprobar qué error devuelve el PDS si la `rkey` ya existe: se trata como «ya publicado» y se confirma con `getRecord`.
- **Vídeo**, como `StatusPoller`:
  - `publish` comprueba `getUploadLimits`. Si `canUpload` es falso o el email no está verificado, lanza `PermanentPublishException` con el mensaje de la API;
  - pide `getServiceAuth`, con `aud` = DID de servicio del PDS y `lxm=com.atproto.repo.uploadBlob` ⚠️ (el valor exacto de `aud` no está verificado);
  - sube el vídeo con `uploadVideo` en **streaming** (`MediaChunkReader::stream`) y con el *timeout* que deje el `StepBudget`;
  - devuelve `processing(jobId)` con la `rkey` en `progress`;
  - `poll` llama a `getJobStatus`: con `COMPLETED` y el `blob`, hace `createRecord` (con `embed.video` y `aspectRatio`) y devuelve `published`; con `FAILED`, devuelve `failed` con su `failureCode` traducido.
  - **Tope de tamaño `social.bluesky.video_max_bytes`: unos 50 MB por defecto (52.428.800 bytes), configurable.** Lo decidió el usuario el 2026-10-27 y no cambia el despliegue.
    - Un vídeo mayor **se rechaza al guardar**, en `MediaValidator` (compositor y `create_post`), con un mensaje claro: «Bluesky solo admite vídeos de hasta 50 MB en esta instalación; este pesa X MB.»
    - La subida es una sola petición dentro de los 85 s del paso ⚠️: con unos 5 Mbps de subida, 50 MB caben en unos 80 s.
- **Métricas (`BlueskyInsights`):**
  - **por cuenta**, con `getProfile`: `followersCount` → `FOLLOWERS` y `postsCount` → un *stock* nuevo `POST_COUNT` (`VIDEO_COUNT` significa «vídeos»);
  - **por post**, con `getPosts` en lotes de 25: `likeCount` → `LIKES`, `replyCount` → `COMMENTS`, `repostCount + quoteCount` → `SHARES`, la suma → `INTERACTIONS`, y `VIEWS` = `null`;
  - `EXPECTED_FLOWS['bluesky'] = []` (solo *stocks*, como TikTok);
  - lectura en el AppView público sin sesión ⚠️ (`public.api.bsky.app`, no verificado): así una sesión caída no rompe las métricas.

### O1 — Contrato segregado (OAuth y credenciales sobre una base común) y catálogo de redes centralizado en `Network`

**Contrato.**

- `Connector` se queda con lo común: `provider()`, `networks()`, `refresh()` y `refreshMarginHours()`.
- Se crean dos interfaces hijas:
  - `OAuthConnector extends Connector`, con `authorizationUrl()` y `exchangeCode()`;
  - `CredentialConnector extends Connector`, con `credentialFields()` (la descripción del formulario) y `connectWithCredentials(array $input): list<ConnectionCandidate>`.
- Meta, Google y TikTok solo cambian `implements Connector` por `implements OAuthConnector`.
- `OAuthController` hace `abort_unless(... instanceof OAuthConnector, 404)`.
- Se añade `CredentialConnectionController@store`, con la ruta `POST brands/{brand}/connections/{provider}/credentials`, *throttle* y CSRF. Llama a `SocialAccountService::connect()` sin cambios.
- `ConnectionCandidate` recibe un `?string $secret` opcional, sin representación pública.
- `RefreshSocialAccount` no cambia: sigue llamando a `Connector::refresh()`.

**Lista de redes.** `Network` pasa a ser el **catálogo**:

- `label()`, `provider()`, `shortLabel()`, `color()` y `authKind()` (`oauth` o `credentials`);
- `providers()`, derivado de los casos;
- `offered()`, que filtra por `ConnectorRegistry::has()`. Así Threads desaparece si falta `THREADS_APP_ID`.

`shell.networks` incluye estos campos. `NetworkTabs.vue`, `Calendar/Index.vue`, `format.js` y `composerOptions.js` leen de `shell`. `Welcome.vue` es pública: recibe la lista por la prop de la página.

En el backend:

- `routes/web.php` usa `Network::providers()`;
- `AiAssistant` y `AiAssistantController` usan `Rule::enum(Network::class)` y `Network::tryFrom()`;
- las descripciones del MCP se generan desde `Network::cases()`.

Los mapas de datos por red (`EXPECTED_FLOWS`, `SUPPORTED` y los presets) **se quedan donde están**, pero los protege un **test de cobertura**. Ese test recorre `Network::cases()` y comprueba que cada red tiene:

- tipos de contenido y regla de media;
- entrada en `EXPECTED_FLOWS` y en `SUPPORTED`;
- icono en `NetworkIcon.vue` (*grep* del fichero);
- color y etiqueta.

Así, la siguiente red falla en CI y no sale a medias.

| Criterio | Valoración |
|---|---|
| Complejidad | Media: 2 interfaces nuevas, un controlador, una columna y unas 15 ediciones mecánicas de listas; ningún flujo existente cambia de forma |
| Riesgo | Bajo-Medio: el cambio en los 3 conectores es de una línea; el riesgo real es dejar alguna lista sin migrar, y lo cubre el test de cobertura |
| Coste relativo | M |
| Reversibilidad | Alta: las interfaces son aditivas; volver al contrato único es fusionar dos interfaces, y el catálogo se puede abandonar lista a lista |

### O2 — Contrato único ampliado y listas actualizadas en su sitio

**Contrato.** `Connector` gana `authKind()` y `connectWithCredentials()`.

- Los conectores OAuth implementan `connectWithCredentials()` lanzando `LogicException`.
- `BlueskyConnector` implementa `authorizationUrl()` y `exchangeCode()` lanzando también.
- `OAuthController` y el controlador nuevo comprueban `authKind()`.

**Listas.** Se añade `'bluesky'` (y luego `'threads'`) en cada una de las unas 22 listas, en su sitio. Se actualizan los tests de conteo (13 → 15 → 19). No hay catálogo ni test de cobertura, más allá de los tests actuales.

| Criterio | Valoración |
|---|---|
| Complejidad | Baja: es la ruta más corta para Bluesky sola |
| Riesgo | Medio: hay métodos muertos que lanzan en 4 conectores (un error de despacho sale en tiempo de ejecución, no en el tipo), y la duplicación sigue, así que C-02 repite la caza de listas con el mismo riesgo de olvidar alguna (riesgo «Alta» de la evaluación) |
| Coste relativo | S para C-01, pero C-02 vuelve a pagar unas 5h de listas |
| Reversibilidad | Media: el contrato «gordo» se segrega después sin dolor, pero las listas añadidas se quedan como deuda |

### O3 — Definiciones por red (un módulo por red) con contrato segregado

Igual que O1 en el contrato, pero el catálogo **no** vive en el enum. Cada red declara una `NetworkDefinition`, por ejemplo `app/Connectors/Bluesky/BlueskyDefinition.php`, con:

- etiqueta, color e icono;
- tipos de contenido;
- reglas de media, que salen de `config/media_specs.php` y pasan a la definición;
- métricas soportadas y flujos esperados;
- presets.

`Metric::EXPECTED_FLOWS`, `AnalyticsService::SUPPORTED`, `ComposerPresetService` y la parte por red de `ContentType` pasan a leer de las definiciones. Es el modelo «plugin»: añadir una red es crear su carpeta.

| Criterio | Valoración |
|---|---|
| Complejidad | Alta: mueve datos que hoy leen `MediaValidator`, `AnalyticsService`, `Calculator`, los informes y el MCP, y `ContentType` (un enum) no se puede declarar fuera de su fichero, así que la definición queda partida |
| Riesgo | Medio-Alto: regresión amplia en las 4 redes actuales (13 reglas, analítica e informes) por una ganancia que solo se cobra en la red 7 y siguientes |
| Coste relativo | L |
| Reversibilidad | Baja: deshacerlo es volver a centralizar los datos en 5 o más ficheros |

### Común a las opciones de C-02: mecánica de Threads

- **OAuth:**
  - `threads.com/oauth/authorize` con `threads_basic`, `threads_content_publish` y `threads_manage_insights`;
  - canje con `POST graph.threads.com/oauth/access_token` → token corto (1h) → `GET /access_token` con `th_exchange_token` → token largo de 60 días, que va en `access_token` con `token_expires_at` = +60 días;
  - `refresh_token` = `null`;
  - `refresh()` llama a `GET /refresh_access_token` con `th_refresh_token` y el propio token. El margen de 168h cumple la condición de «al menos 24h de antigüedad».
  - Hosts: **`threads.com`** para la autorización y **`graph.threads.com`** para la API, verificados en la documentación oficial el 2026-10-26. Son configurables (`social.threads.auth_host` y `social.threads.graph_host`) por si vuelven a cambiar. La spec decía `.net` y ya está corregida.
- **Cuenta:** `GET /me?fields=id,username,name,threads_profile_picture_url` ⚠️ (campos no verificados), con `external_id` = `user_id`.
- **Publicación:**
  - `TEXT`, `IMAGE` y `VIDEO` crean un contenedor y devuelven `processing(containerId)`;
  - el carrusel crea los hijos con `is_carousel_item` y guarda el progreso con `checkpoint()`, como `InstagramPublisher::advanceCarousel`, y después crea el padre con `children`;
  - `poll` lee `status`: con `FINISHED` publica (`threads_publish`) y pide el *permalink* ⚠️; con `ERROR` o `EXPIRED` lanza `PermanentPublishException` con el `error_message` traducido; con `PUBLISHED` resuelve el ID.
  - Se reutilizan `PollPublishStatus` y `StepBudget`. El plazo de procesado es `social.threads.poll_max_minutes`, y la documentación recomienda 5 minutos.
  - La media va por URL pública firmada (`PublishRequestFactory`).
- **Cuota:** `assertQuota()` consulta `threads_publishing_limit` antes de crear contenedores, igual que IG. Si está agotada, lanza `QuotaExceededException` y se aplica `QuotaPolicy`, sin tocarla.
- **Idempotencia:** `findPublished` recorre `/me/threads` recientes por `since`. El contenedor creado ya está en `reference` gracias a `checkpoint()`.
- **Métricas (`ThreadsInsights` como `RangeInsightsReader`):**
  - **por cuenta**, con `threads_insights`: `views` → **`PROFILE_VIEWS`**, porque son visitas al perfil y no visualizaciones; `likes`, `replies` → `COMMENTS`, `reposts + quotes` → `SHARES`, y `clicks`;
  - `followers_count` → `FOLLOWERS`, sin rango, solo para el día actual;
  - **por post**: `views` → `VIEWS`, `likes`, `replies` → `COMMENTS`, `reposts + quotes + shares` → `SHARES` ⚠️ (`views` y `shares` figuran «en desarrollo»), y `INTERACTIONS` = la suma;
  - el rango empieza el 2024-04-13 como pronto.
- **Media:** `threads_image` en JPEG o PNG de 8 MB (de 320 a 1440 px los reescala la red). `threads_video` en MP4 o MOV, H.264 o HEVC, de 23 a 60 fps, de más de 0 s a 300 s y 1 GB como máximo. Como en IG, la red descarga la media, así que el worker de 120 s no es un problema.

### T1 — Conector propio `threads` con cliente propio, que copia el patrón de contenedor de IG

`app/Connectors/Threads/{ThreadsConnector, ThreadsClient, ThreadsPublisher, ThreadsInsights}.php`:

- `ThreadsConnector implements OAuthConnector` (si gana O1), con el proveedor `threads`;
- `ThreadsClient` es un cliente HTTP propio con su host y su traducción de errores. Los códigos de credencial y de límite de Graph se reutilizan extrayendo **solo** la tabla de clasificación de `GraphClient` (`REAUTH_CODES` y `RATE_LIMIT_CODES`) a `Meta/GraphErrors`;
- `ThreadsPublisher` reproduce la máquina de estados de `InstagramPublisher`: contenedor, estado y publicación, con el carrusel por `progress`. No hereda de él.
- El registro es condicional: solo si `services.threads.client_id` no está vacío.

| Criterio | Valoración |
|---|---|
| Complejidad | Media: la máquina de estados ya existe en IG y se copia con sus tests como guía |
| Riesgo | Bajo: no toca ni una línea del flujo de IG, y los fallos de Threads quedan en su carpeta |
| Coste relativo | M |
| Reversibilidad | Alta: la red se borra quitando su carpeta y su registro |

### T2 — Conector propio, pero extrayendo un núcleo de contenedores común con Instagram

Igual que T1 en el OAuth. Además, se extrae `Connectors/Support/ContainerFlow`, que encapsula:

- crear el contenedor, sondear el estado, publicar y pedir el *permalink*;
- el carrusel con hijos, padre y `progress`;
- los estados y la cuota.

Se parametriza con un cliente (host y versión) y una tabla de estados y errores. `InstagramPublisher` y `ThreadsPublisher` pasan a usarlo, y `GraphClient` gana un host configurable.

| Criterio | Valoración |
|---|---|
| Complejidad | Alta: los estados de IG (`status_code` FINISHED/ERROR/EXPIRED, historias aparte, `media_publish`) y los de Threads (`status`, `threads_publish`) se parecen pero no son iguales, y la abstracción necesita bastantes puntos de extensión |
| Riesgo | Medio-Alto: refactoriza el publicador con más casos del repo (carrusel, historias, cuota y búsqueda de duplicados), que ya funciona en producción |
| Coste relativo | M-L: menos código duplicado, pero más regresión de IG |
| Reversibilidad | Media: deshacerlo es volver a «inlinear» el flujo en dos publicadores |

### T3 — Reutilizar `MetaConnector` y la app de Meta existente (Threads como red del proveedor `meta`)

Se añade `Network::Threads` a `MetaConnector::networks()` y se piden los permisos `threads_*` en el mismo login de Meta.

| Criterio | Valoración |
|---|---|
| Complejidad | Alta: la documentación verificada dice que la Threads API usa **otro ID y otro secreto de app** («hay 2 app IDs»), otro host de autorización (`threads.com`) y otro canje (`th_exchange_token`), así que `MetaConnector` tendría que hacer dos OAuth en uno |
| Riesgo | Alto: pedir un permiso no activado rompe **todo** el login de Meta («Invalid Scopes», `config/social.php:50-55`), y IG y Facebook caerían con Threads |
| Coste relativo | L |
| Reversibilidad | Baja: separa después las cuentas ya conectadas bajo `meta` |

## 3. Criterios de decisión

1. **No romper lo que ya publica** (Meta, Google y TikTok en producción). Pesa más que el coste. Penaliza cualquier refactor de un flujo existente que no exija la red nueva.
2. **Que la siguiente red no salga a medias.** La evaluación da probabilidad Alta al riesgo «lista de redes olvidada» y en esta ola entran dos redes seguidas. Pesa más que ahorrar unas horas en C-01.
3. **Reversibilidad del mecanismo de autenticación** (D1: Bluesky puede retirar las app passwords). Hay que poder pasar a OAuth de atproto sin tocar el resto.
4. **Coste relativo.** Solo desempata. Las horas son las de la evaluación: C-01 65h base y C-02 54h base.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto: C-01 → O1 · C-02 → T1.**

- **O1** cumple los criterios 1 y 3. Los conectores actuales solo cambian el nombre de la interfaz, y el día que llegue OAuth de atproto, `BlueskyConnector` pasa de `CredentialConnector` a `OAuthConnector` sin tocar nada más. También cumple el criterio 2: el catálogo y el test de cobertura convierten un olvido en un fallo de CI, y C-02 cobra esa inversión.
- **T1** cumple el criterio 1: no toca `InstagramPublisher`. El parecido con IG se aprovecha copiando una máquina de estados ya probada, no abstrayéndola.

**Opción elegida: C-01 → O1 (contrato segregado y catálogo de redes en `Network`, con test de superficies) · C-02 → T1 (conector `threads` propio).** El usuario la validó el 2026-10-27 en `/dev-cycle` (2-a).

- O1 se elige por los criterios 1, 2 y 3:
  - los conectores actuales no cambian de comportamiento;
  - el test de superficies convierte una red a medias en un fallo de CI;
  - Bluesky puede pasar a OAuth de atproto cambiando solo su interfaz.
- T1 se elige por el criterio 1: `InstagramPublisher` no se toca.
- Además, el usuario fijó:
  - el tope de imagen de Bluesky en **2 MB, configurable** (pregunta 3 → b; CA-03 ajustado en la spec);
  - el tope de vídeo de Bluesky en **unos 50 MB, configurable, con rechazo y mensaje claro al guardar** (pregunta 4 → a, sin cambiar el despliegue).

Descartadas:

- **O2:** ahorra en C-01, pero C-02 vuelve a pagar la caza de listas y deja métodos que lanzan en 4 conectores (criterio 2).
- **O3:** refactor amplio de las 4 redes actuales por una ganancia que solo se cobra a partir de la séptima red (criterios 1 y 4).
- **T2:** refactoriza el publicador más delicado del repo (criterio 1).
- **T3:** técnicamente inviable según la documentación (otro ID de app y otro OAuth), y arrastra el «Invalid Scopes» de Meta (criterio 1).

## 5. Impacto en módulos y ficheros

### 5.1 C-01 (con O1)

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `app/Connectors/Contracts/Connector.php` | Se queda con `provider`, `networks`, `refresh` y `refreshMarginHours` | Modificado |
| `app/Connectors/Contracts/{OAuthConnector,CredentialConnector}.php` | Interfaces hijas | Nuevo |
| `app/Connectors/{Meta/MetaConnector,Google/GoogleConnector,TikTok/TikTokConnector}.php` | `implements OAuthConnector` | Modificado (1 línea) |
| `app/Connectors/Bluesky/{BlueskyConnector,BlueskySession,BlueskyClient,BlueskyPublisher,BlueskyInsights,Facets,Tid}.php` | Conector, sesión con bloqueo, XRPC, publicador (`Publisher` + `StatusPoller`), métricas, *facets* y TID | Nuevo |
| `app/Connectors/Network.php` | Caso `Bluesky` y catálogo (`shortLabel`, `color`, `authKind`, `providers()` derivado y `offered()`) | Modificado |
| `app/Connectors/ConnectorRegistry.php` | Sin cambios de API. Registro condicional desde el provider | — |
| `app/Connectors/Data/ConnectionCandidate.php` | `?string $secret` (nunca en `toPublicArray`) | Modificado |
| `app/Models/SocialAccount.php` y una migración nueva | Columna `credential_secret` (`encrypted`, `hidden`, `text`) | Modificado / nuevo |
| `app/Services/SocialAccountService.php` | `connect` guarda `secret`, y `disconnect` lo borra | Modificado |
| `app/Http/Controllers/OAuth/OAuthController.php` | Comprueba `instanceof OAuthConnector` | Modificado |
| `app/Http/Controllers/CredentialConnectionController.php` y `routes/web.php` | Formulario de credenciales. `whereIn` con `Network::providers()` | Nuevo / modificado |
| `app/Providers/AppServiceProvider.php` | Registro de Bluesky | Modificado |
| `app/Enums/ContentType.php` y `config/media_specs.php` | `bluesky_post` y `bluesky_video`; `text.count`; topes configurables | Modificado |
| `app/Support/TextLength.php`, `app/Services/MediaValidator.php:118-140` y `resources/js/Composables/textLength.js` | Conteo por modo (`chars`, `graphemes` o `threads`) con espejo en JS | Nuevo / modificado |
| `app/Services/Media/ImageDownscaler.php` (GD) y `app/Services/MediaStorage.php` | Copia derivada recomprimida y cacheada | Nuevo / modificado |
| `app/Analytics/Metric.php` y `app/Services/AnalyticsService.php` | `POST_COUNT` (*stock*), `EXPECTED_FLOWS` y `SUPPORTED` de `bluesky` | Modificado |
| `app/Services/AiAssistant.php:86` y `app/Http/Controllers/AiAssistantController.php:30` | `Network::tryFrom` y `Rule::enum` | Modificado |
| `app/Mcp/Tools/{GetStats,GetCalendar,GetBestTimes}.php` | Descripción generada desde `Network::cases()` | Modificado |
| `app/Http/Middleware/HandleInertiaRequests.php:106` | `shell.networks` con el catálogo completo | Modificado |
| `resources/js/Pages/Dashboard/Partials/{NetworkTabs.vue,format.js}`, `Calendar/Index.vue`, `Welcome.vue` y `Composables/composerOptions.js` | Leen el catálogo en lugar de su lista | Modificado |
| `resources/js/Components/NetworkIcon.vue` y `resources/js/Pages/Brands/Connections.vue` | Icono de Bluesky. Formulario de handle y app password (`authKind = credentials`) | Modificado |
| `tests/Unit/MediaValidator/MediaValidatorTest.php:35` y `tests/Unit/Networks/NetworkCoverageTest.php` | Conteo de reglas. Test de cobertura de todas las superficies | Modificado / nuevo |
| `config/social.php` | `bluesky.{entryway,video_service,default_langs,image_max_bytes,video_max_bytes}` y `refresh_margin_hours.bluesky` | Modificado |

### 5.2 C-02 (con T1 sobre O1)

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `app/Connectors/Threads/{ThreadsConnector,ThreadsClient,ThreadsPublisher,ThreadsInsights}.php` | OAuth, cliente, contenedores, cuota y métricas de rango | Nuevo |
| `app/Connectors/Meta/GraphErrors.php` y `app/Connectors/Meta/GraphClient.php` | Se extraen solo las tablas `REAUTH_CODES` y `RATE_LIMIT_CODES` | Nuevo / modificado (sin cambio de comportamiento) |
| `app/Connectors/Network.php`, `app/Enums/ContentType.php` y `config/media_specs.php` | `Threads`, con sus cuatro tipos y `text.count = threads` | Modificado |
| `config/{services,social}.php` y `.env.example` | `THREADS_APP_ID` y `THREADS_APP_SECRET`; hosts, permisos, margen y `poll_max_minutes` | Modificado |
| `app/Providers/AppServiceProvider.php` | Registro condicional por configuración | Modificado |
| `app/Analytics/Metric.php` y `app/Services/AnalyticsService.php` | Entradas de `threads` (el test de cobertura las exige) | Modificado |
| `resources/js/Components/NetworkIcon.vue` | Icono. El resto lo da el catálogo de O1 | Modificado |

### 5.3 Nota de impacto en C-03 y C-04

- **C-03b y C-03c** (agregado y CSV): recorren `Network::cases()` y `AnalyticsService::SUPPORTED`. Con O1 incluyen Bluesky y Threads sin código propio. Bluesky da `VIEWS = null` («no disponible») y Threads da `PROFILE_VIEWS`, no `VIEWS`, por cuenta: el agregado debe respetar el `null` (CA-12). `POST_COUNT` es un *stock* nuevo, así que el agregado lo suma como tal.
- **C-04a** (hashtags):
  - `HashtagStats` y `Facets` deben usar **la misma** extracción de `#tags` (`App\Support\Hashtags`). Si no, una sugerencia podría no ser clicable en Bluesky.
  - El máximo por red sale de `text.max_hashtags`. El de 1 en Threads no aparece en la documentación consultada ⚠️.
- **C-04b** (GIFs): en Bluesky, el MP4 de Giphy entra como `bluesky_video` con `presentation: gif` (⚠️ sin verificar en el lexicon: se comprueba en el smoke M-01). Gasta el cupo diario de vídeo y respeta `video_max_bytes`. En Threads entra como `threads_video` (vale cualquier duración por encima de 0 s).
- **C-04c** (lenguaje natural): la lista de redes que se envía a la IA sale de `Network::offered()` (O1), no de una lista fija.
- **C-04d** (notas): sin impacto.

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| La subida de vídeo de Bluesky (una sola petición) no cabe en los 85 s del `StepBudget` con una conexión lenta | Baja-Media | Medio | Tope de unos 50 MB (`video_max_bytes`) validado al guardar; subida en streaming con el *timeout* del presupuesto; un fallo de red se trata como transitorio. Se prueba en el smoke. Si aun así no cabe, se baja el tope por configuración; la cola con un worker más largo queda descartada por el usuario (2026-10-27) |
| Rotación concurrente del `refreshJwt` (job de renovación y publicador a la vez) que invalida la sesión | Media | Medio | `Cache::lock` por cuenta en `BlueskySession`, con la app password como recuperación |
| `permanentReason()` marca la reconexión de Bluesky por `refresh_expires_at` | Alta si se rellena | Medio | `refresh_expires_at = null` en Bluesky, con un test de regresión en `RefreshSocialAccount` |
| El PDS responde con un error no previsto a una `rkey` repetida ⚠️ | Baja | Bajo | Ante cualquier error de `createRecord`, `findPublished` con `getRecord` antes de fallar |
| La regla de conteo de Threads («cada emoji cuenta sus bytes UTF-8») está mal entendida ⚠️ | Media | Bajo | Una sola implementación en `TextLength` con tests de tabla, y se comprueba en el smoke publicando un texto con emojis al límite |
| Los hosts de Threads (`threads.com` y `graph.threads.com`, verificados) vuelven a cambiar | Baja | Medio | Hosts en `config/social.php` |
| Métricas de Threads «en desarrollo» (`views` y `shares` del post) que cambian o desaparecen | Media | Bajo | `null` si la API no las da, nunca 0; el mapeo está en una sola clase |
| El test de cobertura no detecta una lista en Vue que no lea el catálogo | Baja | Medio | El test hace *grep* de literales de red en `resources/js` fuera de `NetworkIcon.vue` y avisa |
| La TID generada no es monótona respecto a otros clientes de la misma cuenta | Baja | Bajo | La unicidad solo exige `(did, collection, rkey)` y los *feeds* ordenan por `createdAt` ⚠️ |
| Sale del presupuesto: la columna `credential_secret`, `POST_COUNT` y el test de cobertura no estaban desglosados en la evaluación | Baja | Bajo | Caben en las partidas «contrato» (9h) y «generalización» (10h) de C-01. No hace falta re-evaluar |

## 7. Preguntas abiertas

Las preguntas 1 a 4 se cerraron con el usuario el 2026-10-27:

- C-01 → O1;
- C-02 → T1;
- imagen de Bluesky de 2 MB, configurable;
- vídeo de Bluesky de unos 50 MB, configurable, con rechazo al guardar.

Quedan abiertas:

1. **Para el planner:** `POST_COUNT` como métrica *stock* nueva frente a reutilizar `VIDEO_COUNT` con la etiqueta por red. El diseño elige `POST_COUNT`: confírmalo con el impacto en los informes.
2. **⚠️ Por verificar en la implementación** (no se ha podido confirmar en la documentación):
   - el `aud` exacto de `getServiceAuth` para `uploadVideo`;
   - la duración del `accessJwt`;
   - el error ante una `rkey` duplicada;
   - la lectura de métricas en el AppView público sin sesión;
   - los campos de `/me` en Threads y cómo se pide el *permalink*;
   - el máximo de un hashtag en Threads.

   **Resuelto en T-54 (2026-10-27, documentación oficial de Meta):** los campos de `/me` (`id`, `username`, `name`, `threads_profile_picture_url`), el *permalink* (`GET /{media-id}?fields=permalink`) y el hashtag de Threads (solo una etiqueta de tema por post, sin tope de `max_hashtags`). Tabla completa y lo que sigue sin verificar (interpretación de emojis compuestos, códigos de error propios, modo desarrollo en vivo) en `docs/referencia/decisiones-por-red.md`.

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-26 | Diseño creado (`borrador`), en la pasada 1 de `/dev-cycle` (2-a). Opciones O1-O3 (C-01) y T1-T3 (C-02) presentadas con recomendación; la elección queda pendiente del usuario |
| 2026-10-27 | Pasada 2: el usuario valida O1 y T1 → `aprobado`. Topes de Bluesky: imagen de 2 MB y vídeo de unos 50 MB, configurables (vídeo mayor rechazado al guardar). Correcciones a la spec según la documentación: hosts `threads.com` y `graph.threads.com`, `PROFILE_VIEWS` en la cuenta de Threads y emojis contados por bytes en sus 500 caracteres. Candidato de decisión propuesto al Curator |
