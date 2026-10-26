---
spec: metricool-parity-2
descripcion: Segunda ola de paridad con Metricool para uso personal. Comprende primer comentario y textos guardados, cuadrícula de Instagram con lo programado, campañas con panel de métricas, y media desde una URL o desde Google Drive.
estado: aprobada          # borrador | aprobada | implementada | obsoleta
creado: 2026-10-24
actualizado: 2026-10-24
evaluacion: evaluation.md
design: design.md         # aprobado 2026-10-24: C-01b = A1, C-04 = B1
plan: improvement-plan.md
generacion:
  inicio: 2026-10-24T13:53:17Z
  fin: 2026-10-24T14:03:07Z
  fuente: estimado        # la redacción de esta spec está dentro de la ventana medida de evaluation.md (un solo marcador, sin solapes); ver su bloque generacion
  tokens_reales: { entrada: 0, salida: 0, cache_creacion: 0, cache_lectura: 0 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.00          # contabilizadas en evaluation.md
  duracion: 0m            # contabilizada en evaluation.md
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Paridad con Metricool, segunda ola

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Diseño:** [`design.md`](design.md) (aprobado: C-01b = A1, C-04 = B1; el primer comentario fallido se puede editar antes de reintentar; `design.md`)
> **Plan de implementación:** [`improvement-plan.md`](improvement-plan.md) · ledger [`tasks.md`](tasks.md)

> **Terminología:**
>
> - **Destino** (`PostTarget`): una cuenta más un tipo de contenido. Es la unidad que se publica, se reintenta y se mide.
> - **Primer comentario**: comentario de la propia cuenta que se publica justo después del post, en el mismo destino.
> - **Texto guardado**: fragmento reutilizable, como una firma, una llamada a la acción o un grupo de hashtags, que se inserta en el texto o en el primer comentario.
> - **Campaña**: etiqueta con nombre, color y fechas que agrupa publicaciones de una marca para medirlas juntas.
> - **Importación de media**: el servidor descarga un fichero remoto (una URL o un fichero de Drive) y lo convierte en un `MediaAsset` normal.

## Contexto y objetivo

Uso personal: un solo usuario con varias marcas, y cuatro redes (Instagram, Facebook, YouTube y TikTok; `app/Connectors/Network.php`). El MVP del compositor (`2026-10-23-post-composer`) ya está mergeado: texto por destino, presets por red, notas, UTM, panel de errores y Publicar ahora.

Esta spec recoge las cuatro primeras filas de la «Prioridad propuesta» de [`metricool-analisis-bundle.md`](../../referencia/metricool-analisis-bundle.md#prioridad-propuesta). Cada característica se evalúa y se aprueba por separado:

| ID | Característica | Fuente |
|---|---|---|
| C-01 | Primer comentario (C-01b) y textos guardados (C-01a) | Bundle, prioridad 1. Fuentes públicas §1.6 y §1.10. C-06 de [`post-composer/spec.md`](../2026-10-23-post-composer/spec.md) |
| C-02 | Vista de cuadrícula de Instagram con lo publicado y lo programado | Bundle §Planificación (`brands/connections/instagram/media-feed`) |
| C-03 | Campañas con panel de métricas agregadas | Bundle §Planificación (`/performance-dashboard/campaigns*`) |
| C-04 | Media desde una URL (C-04a) o desde Google Drive (C-04b) | Bundle §Compositor (`integrations/google-drive/*`, `actions/normalize/image/url`) |

## Decisiones de diseño

| Decisión | Elección | Motivo |
|---|---|---|
| Primer comentario: cuándo y cómo | **Se heredan las decisiones de C-06** del compositor: un job `PostFirstComment` por destino, que se encola tras `Published`; **un fallo no marca el post como fallido**; idempotencia comprobando antes un comentario propio con ese texto; máximos en `config/media_specs.php` | Ya decidido el 2026-10-23 (`post-composer/spec.md`, §Decisiones). El post ya está en la red: degradarlo por el comentario sería falso |
| Primer comentario: dónde vive | **Por destino** (`post_targets.first_comment`, con su estado, intentos, id externo y error) | El texto ya es por destino desde el MVP. Cada red tiene su propio máximo |
| Primer comentario: redes | **Instagram (post, carrusel y Reel), Facebook y YouTube.** Ni TikTok ni Stories | La API de TikTok no expone comentarios. Las Stories no admiten comentarios |
| Primer comentario en YouTube | **Entrega aparte (C-01b-YT)**, condicionada a confirmar el scope `youtube.force-ssl` y a reconectar el canal | Hoy se piden `youtube`, `youtube.readonly` y `yt-analytics.readonly` (`config/social.php:60-62`) ⚠️ |
| Textos guardados: propiedad | **Por usuario, con marca opcional**: `brand_id` nulo = «Todas las marcas» | Hay un solo usuario, así que el usuario es el propietario natural y cumple el `UserScope` del resto de modelos. Una firma o una llamada a la acción sirven para todas las marcas. Los grupos de hashtags suelen ser de una marca. Es lo que hace Metricool («para todas las marcas», fuentes §1.10) y evita duplicar textos entre marcas |
| Textos guardados: tipos | **Dos tipos en una tabla**: `fragmento` y `hashtags` | Misma UI y mismo CRUD. El tipo solo cambia el icono, el filtro y el aviso del tope de 30 hashtags de Instagram |
| Textos guardados: dónde se insertan | **En la posición del cursor** del texto (plantilla o texto propio del destino) y del primer comentario | Paridad con Metricool (fuentes §1.10) |
| Cuadrícula de Instagram: datos publicados | **Lectura en vivo** de `GET /{ig-user-id}/media` con caché corta (10 min), **sin persistir** | `media_url` y `thumbnail_url` son URLs del CDN de Meta que caducan. Guardarlas obligaría a refrescarlas |
| Cuadrícula de Instagram: qué entra | Imagen, carrusel (primera pieza) y Reel con «compartir en el feed». **Las Stories no entran** | Es lo que aparece en el perfil |
| Cuadrícula de Instagram: orden | Programadas arriba (la más tardía primero), después las publicadas por `timestamp` descendente | Es el orden en que quedará el perfil. Los fijados no se reflejan (ver Supuestos) |
| Campañas: ámbito | **Por marca** (`campaigns.brand_id` obligatorio) | La analítica, la zona horaria y los permisos ya van por marca (`AnalyticsService::bounds`) ⚠️ a confirmar |
| Campañas: unidad | **El post** (`posts.campaign_id`, nulo al borrar la campaña); todos sus destinos cuentan | Un post con N destinos es una sola pieza de contenido |
| Campañas: métricas | **Último snapshot de cada destino** (`post_metric_snapshots`), agregado como hace hoy el dashboard; `null` = no disponible, nunca 0 | Reutiliza el modelo de datos existente. El alcance sumado se rotula «alcance acumulado» porque no es de usuarios únicos |
| Media remota: cómo se descarga | **Un job en cola** (`ImportRemoteMedia`) que descarga **por tramos** con `Range` y presupuesto de tiempo, y termina en `MediaUploadService::finalize()` | Los workers de Hestia cortan a 120 s y el de `publish` vive 55 s (`docs/modulos/despliegue.md:112-125`). Un vídeo de varios GB no cabe en una petición ni en una pasada |
| Media desde URL: seguridad | **Guardia SSRF**: solo `http`/`https`; se resuelve el DNS y se rechazan IPs privadas, reservadas, de *loopback* y *link-local* (también IPv6); cada redirección se vuelve a comprobar; tope de bytes durante la descarga | Una URL arbitraria descargada por el servidor es la superficie de ataque típica (CWE-918) |
| Google Drive: opción mínima | **Google Picker con el scope `drive.file`** y un token de Google Identity Services en el navegador, de un solo uso. **Sin refresh token y sin guardar credenciales** | `drive.file` solo da acceso a los ficheros que el usuario elige en el Picker. Google lo clasifica como **no sensible** ⚠️, así que no exige la verificación de la app. `drive.readonly` es restringido y la exigiría |
| Paridad con el MCP | **Campos nuevos del post**: `targets.*.first_comment` y `campaign_id` en `PostPayload`, `create_post`, `update_post` y `get_post`, con las reglas de `PostRequest`. **Herramientas nuevas**: `list_campaigns` (sin ella el `campaign_id` no se puede descubrir) e `import_media_from_url` | Restricción del proyecto: el MCP mantiene paridad con la web en los campos nuevos del post |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| Máximo del primer comentario | `config/media_specs.php` `types.*.first_comment.max` (nuevo) | — | **IG 2.200 · FB 8.000 · YT 10.000** (fuentes §1.6). Sin clave en TikTok ni en Stories = no admitido |
| Scope de YouTube para comentar | `config/social.php` `google.scopes` | `youtube`, `youtube.readonly`, `yt-analytics.readonly` | `youtube.force-ssl` si `commentThreads.insert` lo exige ⚠️. Obliga a reconectar |
| Caché de la cuadrícula | `config/social.php` `instagram.grid_cache_minutes` (nuevo) | — | **10** |
| Elementos publicados en la cuadrícula | `config/social.php` `instagram.grid_published` (nuevo) | — | **30** (3 columnas × 10 filas) |
| Tope de la importación | `config/media.php` `max_bytes` (existente) | 30 MB imagen · 4 GB vídeo | Igual que la subida directa |
| Presupuesto por pasada de la importación | `config/media.php` `import_budget_seconds` (nuevo) | — | **80 s** (< `--timeout=120`) |
| Cola de la importación | `config/media.php` `import_queue` (nuevo) | — | **`media`**, con su línea de cron en Hestia |
| Google Picker | `GOOGLE_PICKER_API_KEY`, `GOOGLE_PICKER_APP_ID` (nuevos) y `GOOGLE_CLIENT_ID` (existente) | vacío = botón de Drive oculto | Clave restringida al dominio; Picker API y Drive API activadas en el proyecto de Google Cloud |

## Arquitectura y componentes

| Capa | Pieza | Nueva o reutilizada | C-XX |
|---|---|---|---|
| BD | `saved_texts` (`user_id`, `brand_id` nulo, `kind`, `title`, `body`) | Nueva | C-01a |
| BD | `post_targets.first_comment`, `first_comment_status`, `first_comment_attempts`, `first_comment_external_id`, `first_comment_error` | Nueva (aditiva) | C-01b |
| BD | `campaigns` (`user_id`, `brand_id`, `name`, `color`, `starts_on`, `ends_on`, `archived_at`) y `posts.campaign_id` (`nullOnDelete`) | Nueva (aditiva) | C-03 |
| BD | `media_assets.source` y `source_ref` (origen: subida, URL o Drive) | Nueva (aditiva) | C-04 |
| PHP | `SavedTextService` y `SavedTextController` (CRUD) | Nueva | C-01a |
| PHP | Job `PostFirstComment` y método `comment()` en `InstagramPublisher`, `FacebookPublisher` y `YouTubePublisher`; encolado desde `PublishOutcomes::published` | Nueva, sobre `GraphClient` y `Meta/Comments` | C-01b |
| PHP | `InstagramGrid` (lectura de `/{ig}/media` paginada) y `GridService` (mezcla con los destinos programados) | Nueva, sobre `GraphClient` | C-02 |
| PHP | `CampaignService`, `CampaignController` y `CampaignAnalytics`; la consulta del último snapshot de `AnalyticsService::postStats` se extrae a una pieza compartida | Nueva más refactor | C-03 |
| PHP | `RemoteMediaImporter`, `UrlGuard` (SSRF), `DriveFileSource` y job `ImportRemoteMedia`; termina en `MediaUploadService::finalize()` | Nueva, sobre `MediaUploadService` | C-04 |
| PHP | `PostRequest`, `PostService::plan()`/`fill()`, `PostPayload`, `GetPost`, `ListCampaigns` (nueva) e `ImportMediaFromUrl` (nueva) | Ampliadas | C-01b, C-03, C-04 |
| Vue | `SavedTextsMenu` en `EditorToolbar`; gestión en una página de ajustes | Nueva | C-01a |
| Vue | Campo de primer comentario por destino en `NetworkTabs`/`TargetOptions`; estado y Reintentar en `TargetStatusPanel` | Ampliada | C-01b |
| Vue | `Pages/Instagram/Grid.vue` | Nueva | C-02 |
| Vue | `Pages/Campaigns/Index.vue`, `Pages/Campaigns/Show.vue` y selector en `ComposerHeader` | Nueva | C-03 |
| Vue | «Desde URL» y «Google Drive» en `MediaSection`; `useDrivePicker.js` | Nueva | C-04 |

## Flujo (paso a paso)

1. **C-01a.** En el compositor, el botón «Textos guardados» de la barra muestra los de la marca actual y los de «Todas las marcas». Al elegir uno, se inserta en la posición del cursor del campo activo. Los contadores se recalculan.
2. **C-01b.** Por cada destino que lo admite, el usuario escribe un primer comentario opcional. Al pasar el destino a `Published`, se encola `PostFirstComment`. El job busca primero un comentario propio con ese texto. Si no existe, comenta y guarda el id externo. Si falla, el destino sigue «Publicada», el comentario queda «fallido» con el motivo, llega un aviso in-app y se ofrece Reintentar.
3. **C-02.** En «Cuadrícula» se elige una cuenta de Instagram de la marca. Se ven tres columnas: arriba las programadas (con la fecha y un enlace al compositor) y debajo las publicadas (con un enlace a Instagram).
4. **C-03.** El usuario crea una campaña en la marca y la asigna a los posts desde el compositor o por MCP. El panel de la campaña muestra los KPIs agregados, el desglose por red, la serie diaria y la lista de posts con sus métricas.
5. **C-04a.** En la media del compositor, «Desde URL» acepta un enlace. El servidor valida la URL, crea el asset `pendiente` y encola la importación. La UI sondea el estado hasta `listo` o `invalido`.
6. **C-04b.** «Google Drive» abre el Picker con un token `drive.file`. El navegador envía el `fileId` y el token al servidor, que importa el fichero igual que en C-04a. El token no se guarda: viaja cifrado en el job y caduca en una hora.

## Alcance

- **Dentro (esta iteración, según lo que apruebe el usuario):**
  - C-01a textos guardados (fragmentos y grupos de hashtags) con CRUD e inserción;
  - C-01b primer comentario en Instagram y Facebook; YouTube en una entrega aparte;
  - C-02 cuadrícula de Instagram de solo lectura;
  - C-03 campañas por marca con panel;
  - C-04a importación desde URL, también por MCP;
  - C-04b importación desde Google Drive con Picker.
- **Fuera (siguientes specs):**
  - redes nuevas (Bluesky, Threads), aprobaciones o revisión, posts recurrentes y equipos (fuera por decisión del usuario);
  - primer comentario en TikTok y en Stories (la API no lo permite);
  - reordenar la cuadrícula arrastrando (intercambiar horas): unas 6h más;
  - cuadrícula de YouTube (`youtube/media-feed`);
  - campañas entre marcas, sección de campaña en el informe PDF y filtro del calendario por campaña;
  - Canva, búsqueda de GIF, Dropbox y OneDrive;
  - herramienta MCP `list_saved_texts` (los textos guardados no son un campo del post): unas 1,5h si se quiere.

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| Primer comentario en una red o tipo que no lo admite (TikTok, Story) | El control no se ofrece. Si llega por MCP, error de validación del destino |
| Falla el primer comentario tras publicar | El destino sigue «Publicada». El comentario queda «fallido» con el motivo, llega un aviso in-app y se ofrece Reintentar. Nunca se repite un comentario ya puesto |
| YouTube sin el scope para comentar | El control de YouTube se muestra desactivado, con el aviso «Reconecta el canal para comentar» |
| Vídeo de YouTube «hecho para niños» o privado | Se avisa al escribir el comentario; si aun así se publica, el comentario queda «no admitido», no «fallido» |
| La lectura de la cuadrícula falla o la cuenta pide reconexión | Se muestran las programadas y un aviso en español; nunca una página en blanco |
| Se borra una campaña con posts | Los posts quedan sin campaña (`nullOnDelete`); se pide confirmación |
| Campaña sin datos de métricas | KPIs en «sin datos», no en 0 |
| URL a una IP privada, a un esquema no admitido o con una redirección a una red interna | Error de validación inmediato; no se crea el asset |
| La descarga supera el tope o el tipo real no coincide | Se corta la descarga, se borra el fichero y el asset queda `invalido` con el motivo |
| El token de Drive caduca a mitad de la importación | El asset queda `invalido` con «Vuelve a elegir el fichero en Drive» |
| Fichero de Drive nativo de Google (Docs, Hojas) | No se ofrece: el Picker filtra por tipos de imagen y vídeo |

## Criterios de aceptación

**C-01a: Textos guardados**

- [ ] [GWT] CA-01 — Dado un texto guardado de «Todas las marcas» y otro de la marca B, Cuando el usuario abre «Textos guardados» en el compositor de la marca A, Entonces ve el primero y no el segundo.
- [ ] [GWT] CA-02 — Dado el cursor en mitad del texto, Cuando inserta un grupo de hashtags, Entonces el texto queda insertado en esa posición y el contador de hashtags de Instagram se actualiza.
- [ ] [GWT] CA-03 — Dado un texto guardado, Cuando el usuario lo edita o lo borra en la página de ajustes, Entonces el cambio se refleja en el compositor; otro usuario no puede verlo ni editarlo (403).

**C-01b: Primer comentario**

- [ ] [GWT] CA-04 — Dado un post de Instagram con primer comentario, Cuando se publica, Entonces se crea un comentario de la propia cuenta con ese texto. Si el job se repite, no se crea un segundo comentario. Se comprueba con `Http::fake`.
- [ ] [GWT] CA-05 — Dado que la API devuelve un error al comentar, Cuando corre el job, Entonces el destino sigue «Publicada», el comentario queda «fallido», llega un aviso in-app y Reintentar lo vuelve a intentar.
- [ ] CA-06 — El primer comentario respeta el máximo por red (IG 2.200, FB 8.000, YT 10.000) y no se ofrece en TikTok ni en Stories.
- [ ] [GWT] CA-07 — Dado un cliente MCP, Cuando crea un post con `targets.*.first_comment`, Entonces `get_post` lo devuelve con su estado, y las reglas de validación son las mismas que en la web.

**C-02: Cuadrícula de Instagram**

- [ ] [GWT] CA-08 — Dada una cuenta de Instagram con publicaciones y dos posts programados, Cuando el usuario abre la cuadrícula, Entonces ve las programadas arriba (la más tardía primero) y después las publicadas, en tres columnas.
- [ ] [GWT] CA-09 — Dada una Story programada, Cuando se abre la cuadrícula, Entonces no aparece.
- [ ] CA-10 — La cuadrícula no desborda a 360 y 375 px y pasa axe.

**C-03: Campañas**

- [ ] [GWT] CA-11 — Dado un post asignado a una campaña con dos destinos publicados y sus snapshots, Cuando se abre el panel, Entonces los KPIs suman los últimos snapshots de cada destino y una métrica sin datos se muestra como «sin datos», no como 0.
- [ ] [GWT] CA-12 — Dada una campaña de la marca A, Cuando se intenta asignar a un post de la marca B (web o MCP), Entonces la validación lo rechaza.
- [ ] CA-13 — `campaign_id` entra en `create_post`, `update_post` y `get_post`, y `list_campaigns` devuelve las campañas de la marca.

**C-04: Media desde URL o Drive**

- [ ] [GWT] CA-14 — Dada una URL pública a una imagen JPEG válida, Cuando el usuario la importa, Entonces aparece un asset `listo` en la media del post con sus metadatos.
- [ ] [GWT] CA-15 — Dada una URL que resuelve a `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254` o `::1`, o que redirige a una de ellas, Cuando se intenta importar, Entonces se rechaza sin hacer ninguna petición a esa dirección.
- [ ] [GWT] CA-16 — Dado un fichero que supera `media.max_bytes` o cuyo tipo real no coincide, Cuando se importa, Entonces la descarga se corta y el asset queda `invalido` con el motivo.
- [ ] CA-17 — Un vídeo grande se importa en varias pasadas del job, cada una por debajo de 120 s.
- [ ] [GWT] CA-18 — Dado `GOOGLE_PICKER_API_KEY` configurado, Cuando el usuario elige un vídeo en el Picker, Entonces se importa como en CA-14. Sin la clave, el botón no aparece.

## Pruebas

- Pest con `Http::fake` para todas las llamadas externas (Graph, YouTube Data, Drive y URLs remotas). Nunca se publica ni se comenta en redes reales.
- Pest de `UrlGuard` con una tabla de IPs y redirecciones (CA-15).
- Pest de `CampaignAnalytics` con snapshots `null` y sin snapshots (CA-11), y regresión de `tests/Feature` del dashboard tras extraer la consulta compartida.
- Pest del MCP: paridad de `first_comment` y `campaign_id`, `list_campaigns` e `import_media_from_url`.
- Playwright con axe a 360 y 375 px: menú de textos guardados, primer comentario, cuadrícula, panel de campaña e importación desde URL. Solo usuarios `e2e-sec-*` y marcas `e2e-`.
- Smoke real a cargo del usuario: primer comentario en Instagram y Facebook, cuadrícula con su cuenta, y una importación desde Drive.

## Referencias

- [`docs/referencia/metricool-analisis-bundle.md`](../../referencia/metricool-analisis-bundle.md), §Compositor, §Planificación y analítica, y §Prioridad propuesta.
- [`docs/referencia/metricool-analisis-fuentes-publicas.md`](../../referencia/metricool-analisis-fuentes-publicas.md), §1.6 (límites del primer comentario) y §1.10 (textos guardados).
- [`docs/roadmap/2026-10-23-post-composer/spec.md`](../2026-10-23-post-composer/spec.md), C-06, decisiones y CA-19..CA-21; y su [`evaluation.md`](../2026-10-23-post-composer/evaluation.md), §C-06.
- Código: `app/Services/PostService.php`, `app/Services/PublishOutcomes.php:38`, `app/Services/MediaUploadService.php:30-163`, `app/Models/MediaAsset.php`, `app/Services/AnalyticsService.php:228-293`, `app/Connectors/Meta/Instagram/InstagramPublisher.php:402`, `app/Connectors/Meta/Comments.php`, `config/social.php`, `config/media_specs.php`, `resources/js/Composables/useComposer.js` y `resources/js/Components/Composer/*`.
- Despliegue: [`docs/modulos/despliegue.md`](../../modulos/despliegue.md), líneas 112-141 (workers en cron, `--timeout=120`).

## Supuestos

- **Hecho técnico:** el primer comentario no tiene código hoy; C-06 quedó fuera del MVP — `post-composer/spec.md:80`.
- **Hecho técnico:** `instagram_manage_comments` y `pages_manage_engagement` ya se piden — `config/social.php:45-46`. `instagram_basic` (lectura de `/media`) también — `config/social.php:40`.
- **Hecho técnico:** Google se pide sin `youtube.force-ssl` — `config/social.php:60-62`.
- **Hecho técnico:** ya se lee `/{ig}/media` (25 elementos, sin miniaturas) para la idempotencia — `InstagramPublisher.php:46,402`.
- **Hecho técnico:** las métricas por destino viven en `post_metric_snapshots` (un JSON por destino y día) y el dashboard ya agrega el último — `AnalyticsService.php:228-293`. Se refrescan solo durante `recent_post_days` (30 días) — `config/social.php:158`.
- **Hecho técnico:** no existe ningún modelo de campaña ni de texto guardado — `app/Models/`.
- **Hecho técnico:** la subida acaba en `finalize()`, que comprueba el tamaño real, el tipo real y los metadatos con ffprobe — `MediaUploadService.php:119-163`. No hay ffmpeg, así que no hay miniaturas de vídeo en el servidor.
- **Hecho técnico:** los workers en Hestia cortan los jobs a 120 s y el de `publish` vive 55 s — `docs/modulos/despliegue.md:112-125`.
- **Restricción de negocio (supuesto hasta confirmar):** las campañas son por marca y se asignan al post entero — lo confirmaría el usuario en la puerta go/no-go.
- **Restricción de negocio (supuesto hasta confirmar):** el usuario acepta reconectar YouTube para el primer comentario en ese canal — lo confirmaría el usuario.
- **Restricción de negocio (supuesto hasta confirmar):** el usuario puede activar Picker API y Drive API, y crear una clave de API en el proyecto de Google Cloud que ya usa — lo confirmaría el usuario.
- ⚠️ **Por verificar en la documentación oficial antes del plan:**
  - que `commentThreads.insert` exige `youtube.force-ssl`;
  - que `drive.file` es un scope no sensible;
  - que la Graph API no expone si un post está fijado en el perfil, así que la cuadrícula no refleja los fijados.
