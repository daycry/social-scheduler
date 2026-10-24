---
generacion:
  inicio: 2026-10-24T13:53:17Z
  fin: 2026-10-24T14:03:07Z
  fuente: medido              # incluye también la redacción de spec.md (misma ventana, un solo marcador)
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-7986bb0b150f4480a396d3224ec148e1
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 70, salida: 48729, cache_creacion: 195023, cache_lectura: 2641317 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.81
  duracion: 49m           # reloj: 10m
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# 2026-10-24-metricool-parity-2

> Evaluación y presupuesto de la segunda ola de paridad con Metricool: primer comentario y textos guardados, cuadrícula de Instagram, campañas, y media desde URL o Google Drive. Cada característica se presupuesta por separado para que el usuario decida el alcance en la puerta go/no-go.

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | completado |
| **Prioridad global** | Media |
| **Solicitante** | daycry (usuario del proyecto), vía `/pm-cycle` |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **Características evaluadas** | 4 (con 6 entregas separables: C-01a, C-01b, C-02, C-03, C-04a y C-04b) |

---

## Cuadro de mando

| Métrica | Total estimado (las 4) | Confianza |
|--------|----------------|-----------|
| Esfuerzo humano | **185,4h** (154,5h base +20 %) | Media |
| Tiempo IA (ejecución) | **25,0h** (+ 6,3h supervisión) | Baja |
| Coste | **9.270 € + tokens** (tokens: ⚠️ verificar) | Media |
| Tokens IA | **6,26M** (in 5,63M / out 0,63M) | Baja |
| Multiplicador productividad | **×5,9** | — |
| Características | **4** | — |

> Las horas humanas equivalentes (base del valor) y las horas IA (base del plazo) son magnitudes distintas y no se suman (LES-007). Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h). En el único ledger con horas IA medidas (`social-scheduler`, fases 1 y 3) el real quedó entre un 87 % y un 92 % por debajo de lo estimado. Es un indicio de que el plazo real será menor.

---

## Resumen ejecutivo

El usuario pide la segunda ola de paridad con Metricool: las cuatro primeras filas de la «Prioridad propuesta» del análisis del bundle. Las cuatro suman **185,4h con margen, ≈ 9.270 € + tokens**. Ninguna necesita una red nueva ni revisión de Meta.

El recon da cinco hallazgos que condicionan el coste:

1. **El primer comentario no es «bajo coste».** Toca efectos externos, idempotencia y un estado nuevo por destino. Pero el MVP lo abarata respecto a C-06 del compositor: el texto ya es por destino y existen `TargetStatusPanel` y `Meta/Comments::fetch`. En **YouTube** sigue pendiente el scope `youtube.force-ssl` (`config/social.php:60-62`), que obliga a reconectar ⚠️. Se separa como entrega aparte.
2. **Los textos guardados son el quick win:** una tabla, un CRUD y la inserción en el cursor. La decisión es «por usuario con marca opcional» (nula = todas las marcas).
3. **La cuadrícula de Instagram no necesita permisos nuevos** (`instagram_basic`, `config/social.php:40`). Ya se lee `/{ig}/media` para la idempotencia (`InstagramPublisher.php:402`), aunque sin miniaturas. Sus límites: las URLs del CDN caducan y los posts fijados no se pueden reflejar ⚠️.
4. **Las campañas se apoyan en datos que ya existen** (`post_metric_snapshots`). El coste está en extraer la consulta del último snapshot de `AnalyticsService::postStats` (`:228-293`) sin romper el dashboard. Sus métricas se congelan a los 30 días de publicar (`recent_post_days`, `config/social.php:158`).
5. **La importación de media es la de más riesgo.** Tiene dos frentes:
   - una URL arbitraria descargada por el servidor es una superficie SSRF;
   - los workers de Hestia cortan a 120 s (`docs/modulos/despliegue.md:112-125`), así que un vídeo grande debe descargarse por tramos en varias pasadas.

   Drive con **Picker y `drive.file`** es la opción mínima. Usa un token de navegador de un solo uso y un scope no sensible ⚠️, así que no hace falta la verificación de Google.

**Veredicto: go**, condicionado a que el usuario confirme las decisiones D1–D5 (§Requerimientos).

> **Decisiones del usuario (2026-10-24, puerta go):** **go** con alcance **completo** (C-01 a C-04). D1 por usuario con marca opcional (lo propuesto). D2 **sí**: reconectará YouTube para `youtube.force-ssl`. D3 por marca y por post (lo propuesto). D4 **sí**: configurará Picker API, Drive API y la clave de API en Google Cloud. D5 **sí**: cola `media` nueva con su worker en Hestia.

---

## Requerimientos recibidos

Mapa de la petición de `/pm-cycle` (fase 1) y de la spec a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | Primer comentario (C-01b) y textos guardados (C-01a) | Petición (1); bundle §Prioridad 1; fuentes §1.6 y §1.10; `post-composer/spec.md` C-06; spec CA-01..CA-07 | Claro. YouTube depende del scope ⚠️ (D2) |
| C-02 | Vista de cuadrícula de Instagram | Petición (2); bundle §Planificación (`instagram/media-feed`); spec CA-08..CA-10 | Claro. Los fijados no se reflejan ⚠️ |
| C-03 | Campañas con panel de métricas | Petición (3); bundle §Planificación (`performance-dashboard/campaigns*`); spec CA-11..CA-13 | Claro. Ámbito por marca, supuesto (D3) |
| C-04 | Media desde URL (C-04a) o Google Drive (C-04b) | Petición (4); bundle §Compositor (`integrations/google-drive/*`); spec CA-14..CA-18 | Claro. Configuración de Google Cloud a cargo del usuario (D4) |

**Ambigüedades / información que falta:**

1. **D1. Textos guardados por usuario o por marca.** Se decide **por usuario con `brand_id` opcional** (nula = «Todas las marcas»). Motivos:
   - hay un solo usuario y todos los modelos ya van con `UserScope`;
   - una firma sirve para todas las marcas y un grupo de hashtags suele ser de una;
   - es lo que hace Metricool (fuentes §1.10).

   Coste de la alternativa «solo por marca»: el mismo, pero obliga a duplicar los textos comunes.
2. **D2. YouTube en el primer comentario.** ¿Acepta el usuario reconectar el canal para añadir `youtube.force-ssl`? Si no, C-01b se queda en Instagram y Facebook (−8,4h).
3. **D3. Campañas.** Se suponen **por marca y asignadas al post entero**. Las campañas entre marcas complicarían la zona horaria y el ámbito de la analítica (+8h aprox.).
4. **D4. Drive.** El usuario debe hacer tres cosas en el proyecto de Google Cloud que ya usa: activar Picker API y Drive API, crear una clave de API restringida al dominio y añadir `drive.file` a la pantalla de consentimiento.
5. **D5. Cola de importación.** Hace falta una línea de cron nueva en Hestia (cola `media`). La alternativa es reutilizar la de `metrics`, que vive 280 s, aunque las importaciones competirían con las métricas.
6. ⚠️ No verificable desde el repo; se comprueba en la documentación oficial antes del plan:
   - el scope exacto de `commentThreads.insert`;
   - la clasificación de `drive.file`;
   - si la Graph API expone los posts fijados.

---

## Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades: la petición, el bundle, las fuentes públicas, C-06 y el recon. Quedan D1–D5.
- [ ] **Alcance** de cada característica acotado: lo está en la spec (§Alcance), pero falta que el usuario elija qué entregas entran.
- [x] **Criterios de aceptación / éxito** por característica: CA-01..CA-18.
- [x] **Restricciones**:
  - Laravel 13 con Inertia/Vue 3, MySQL, Pest y Playwright;
  - `MEDIA_DISK=local` en HestiaCP;
  - UI en español;
  - paridad con el MCP;
  - fuera: redes nuevas, aprobaciones, recurrentes y equipos.

  No hay deadline.
- [ ] **Dependencias externas** identificadas: Graph API v23.0, YouTube Data API v3, Google Picker y Drive API v3. Identificadas, pero con tres puntos ⚠️.
- [x] **Contexto técnico** del proyecto disponible: `PostService`, `PublishOutcomes`, `MediaUploadService`, `MediaAsset`, los publicadores, `Meta/Comments`, `AnalyticsService`, `PostMetricSnapshot`, el compositor, el MCP y el despliegue.
- [ ] **Tarifa/hora y supuestos de coste** confirmados: no existe `.claude/rates.json`. Se usan los defaults y los tokens quedan ⚠️ verificar.

---

## Supuestos económicos (ajustables)

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**.

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default compartido (`estimation-defaults.md`). No hay `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 | Base de la previsión de tokens |
| Precio input | ⚠️ verificar € / 1M tokens | Sin `rates.json`: ejecuta `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre las horas base (humanas e IA) |
| Ratio tokens→hora-IA | 300.000 tok/h | Default **no calibrado**: no existe `docs/roadmap/CALIBRATION.md` |
| Densidad tokens/hora humana | ≈ 40.500 tok por hora base, con un reparto 90/10 de entrada y salida | La misma de `post-composer`, `social-login-mcp-oauth` y `account-2fa-passkeys`. Es un indicio, no un histórico calibrado: los ledgers de esas iniciativas no registran tokens reales por tarea |
| Supervisión humana | 25 % de las horas IA | Default. En UI la revisión visual del usuario pesa más (LES-009) |
| Revisión de robustez | Partida propia en cada característica | LES-001 y LES-009: la revisión es la partida grande. Ya no va dentro del margen |

---

## Evaluación por característica

### C-01: Primer comentario y textos guardados

- **Requisito origen**: petición (1); bundle §Prioridad 1; fuentes §1.6 y §1.10; `post-composer/spec.md` C-06 (decisiones y CA-19..CA-21); spec CA-01..CA-07.
- **Descripción**: dos entregas independientes.
  - **C-01a. Textos guardados.**
    - Tabla `saved_texts` (`user_id`, `brand_id` nulo, `kind` `fragmento`/`hashtags`, `title`, `body`).
    - CRUD en una página de ajustes y un menú «Textos guardados» en `EditorToolbar`, con los de la marca más los de «Todas las marcas».
    - Inserción en el cursor del campo activo: plantilla, texto propio del destino o primer comentario.
  - **C-01b. Primer comentario**, con las decisiones de C-06:
    - `first_comment` por destino, con su estado, intentos, id externo y error;
    - job `PostFirstComment` encolado desde `PublishOutcomes::published` (`:38`);
    - `comment()` en Instagram, Facebook y YouTube;
    - idempotencia buscando antes un comentario propio con ese texto (`Meta/Comments::fetch`);
    - un fallo no degrada el destino: hay aviso in-app y Reintentar en `TargetStatusPanel`;
    - máximos en `config/media_specs.php`;
    - paridad MCP en `PostPayload` y `get_post`.
- **Complejidad**: C-01a **Baja**. C-01b **Alta** (efectos externos, idempotencia y un estado nuevo).
- **Esfuerzo**: **63,6h** (53h base) · confianza Media. Desglose base:

  | Entrega | Partida | Horas base |
  |---|---|---|
  | C-01a | Migración, modelo y `UserScope` | 1,5h |
  | C-01a | `SavedTextService`, controlador, validación y propiedad | 3h |
  | C-01a | Página de ajustes (lista, búsqueda, alta, edición y borrado; filtro marca o todas) | 4h |
  | C-01a | Menú en el compositor e inserción en el cursor (texto y primer comentario) | 3h |
  | C-01a | Pest (CA-01, CA-03) y E2E con axe (CA-02) | 3,5h |
  | C-01a | Documentación y revisión | 2h |
  | | **Subtotal C-01a** | **17h** |
  | C-01b | Verificar el scope de YouTube y el camino de reconexión | 2h |
  | C-01b | Migración aditiva del estado del comentario en `post_targets` | 1,5h |
  | C-01b | Job `PostFirstComment`: idempotencia, reintentos y espera a que el vídeo de YouTube esté procesado | 7h |
  | C-01b | `comment()` en IG, FB y YT, y traducción de errores al español | 6h |
  | C-01b | `PostRequest`, `PostService::plan()`/`fill()`, `media_specs` y tipos que lo admiten | 2h |
  | C-01b | UI: campo por destino, contador, estado y Reintentar | 5h |
  | C-01b | Aviso in-app y MCP (`PostPayload`, `create_post`, `update_post` y `get_post`) | 2,5h |
  | C-01b | Pest con `Http::fake` (CA-04..CA-07) | 4h |
  | C-01b | E2E con axe y documentación | 3h |
  | C-01b | Revisión de robustez (doble comentario y estados) | 3h |
  | | **Subtotal C-01b** | **36h** |
  | | **Total C-01** | **53h** |

  C-01b reutiliza las 28h base que C-06 estimó el 2026-10-23. La UI baja 2h porque el compositor ya es por destino, pero suma lo que antes iba en el cierre conjunto: E2E, documentación y revisión. De las 36h, unas **7h base son solo de YouTube** (scope, `commentThreads.insert`, espera al procesado y sus tests): es la entrega separable **C-01b-YT**.
- **Previsión IA**: 1,93M in / 0,21M out tok (C-01a 0,69M · C-01b 1,46M) · ⚠️ verificar €.
- **Coste**: (63,6h × 50 €/h) + tokens = **3.180 € + tokens**. C-01a: 20,4h · 1.020 €. C-01b: 43,2h · 2.160 €, de los que C-01b-YT son 8,4h · 420 €.
- **Impacto / áreas afectadas**:
  - `database/migrations` (dos aditivas), `app/Models/SavedText.php`, `app/Services/SavedTextService.php` y `app/Http/Controllers/SavedTextController.php`;
  - `app/Services/PublishOutcomes.php`, `app/Jobs/PostFirstComment.php` (nuevo), `app/Connectors/Meta/{GraphClient,Comments}.php`, `InstagramPublisher.php`, `FacebookPublisher.php`, `YouTubePublisher.php` y `config/social.php`;
  - `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `config/media_specs.php`, `app/Mcp/Tools/{PostPayload,GetPost}.php`;
  - `resources/js/Components/Composer/{EditorToolbar,TextEditor,NetworkTabs,TargetOptions,TargetStatusPanel}.vue` y `useComposer.js`.
- **Dependencias y prerequisitos**: el MVP del compositor (mergeado, PR #10). C-01a no depende de nada; conviene hacerla antes para que el primer comentario ya admita textos guardados. C-01b-YT depende de D2.
- **Qué admite cada API** (⚠️ = se comprueba en la documentación oficial antes del plan):

  | Red | ¿Se puede? | Permiso | Notas |
  |---|---|---|---|
  | Instagram (post, carrusel y Reel) | **Sí** | `instagram_manage_comments`, ya pedido (`config/social.php:45`) | No en Stories. El post debe tener los comentarios abiertos ⚠️ |
  | Facebook (Página) | **Sí** | `pages_manage_engagement`, ya pedido (`config/social.php:46`) | En Reels se comenta sobre el id del vídeo ⚠️ |
  | YouTube | **Sí, con reconexión probable** | `commentThreads.insert` exige `youtube.force-ssl` ⚠️ | Unas 50 unidades de cuota ⚠️ (la subida gasta 1.600 de 10.000). Ni en privados ni en «hechos para niños» |
  | TikTok | **No** | La Content Posting API no expone comentarios | Fuera de alcance |
- **Riesgos**:
  - Doble comentario si el job se repite tras un timeout. Se mitiga con la idempotencia y el id externo guardado antes de terminar.
  - Comentar un vídeo de YouTube todavía en proceso falla: hay que esperar el estado de `PollPublishStatus`.
  - La reconexión de YouTube invalida los tokens actuales hasta que el usuario vuelva a autorizar.
- **Incógnitas / preguntas abiertas**: D1 (decidido, a confirmar) y D2. El scope exacto de `commentThreads.insert` ⚠️.

### C-02: Vista de cuadrícula de Instagram

- **Requisito origen**: petición (2); bundle §Planificación (`brands/connections/instagram/media-feed`); spec CA-08..CA-10.
- **Descripción**: una página «Cuadrícula» por cuenta de Instagram de la marca, en tres columnas con el orden del perfil:
  - arriba los destinos programados (imagen, carrusel y Reel con «compartir en el feed»; sin Stories), con su fecha y un enlace al compositor;
  - debajo lo publicado, leído en vivo de `GET /{ig-user-id}/media` (`media_type`, `media_url`, `thumbnail_url`, `permalink` y `timestamp`), con caché de 10 min y un enlace a Instagram.

  Las programadas usan `MediaAsset::publicUrl()`. Los vídeos sin miniatura muestran el primer fotograma con `<video preload="metadata">`, porque no hay ffmpeg en el servidor.
- **Complejidad**: Media.
- **Esfuerzo**: **28,8h** (24h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | `InstagramGrid`: lectura paginada de `/media`, campos, primera pieza del carrusel y errores | 3h |
  | `GridService`: mezcla con los destinos programados, orden, exclusión de Stories, `share_to_feed`, caché y selector de cuenta | 4h |
  | Ruta, controlador y `Pages/Instagram/Grid.vue`: teselas, insignias, enlaces, estados vacío y de error, y 360 px | 7h |
  | Miniatura de los vídeos programados sin ffmpeg | 1,5h |
  | Entrada en la navegación | 0,5h |
  | Pest con `Http::fake` (CA-08, CA-09) | 3h |
  | E2E con axe (CA-10) | 2h |
  | Documentación y revisión | 3h |
  | **Total** | **24h** |

- **Previsión IA**: 0,87M in / 0,10M out tok · ⚠️ verificar €.
- **Coste**: (28,8h × 50 €/h) + tokens = **1.440 € + tokens**.
- **Impacto / áreas afectadas**: `app/Connectors/Meta/Instagram/` (lector nuevo, sin tocar `InstagramPublisher`), `app/Services/GridService.php`, `routes/web.php`, `resources/js/Pages/Instagram/Grid.vue` y la navegación.
- **Dependencias y prerequisitos**: ninguna. No necesita permisos nuevos (`instagram_basic`, `config/social.php:40`).
- **Riesgos**:
  - Las URLs del CDN de Meta caducan: por eso no se guardan y la caché es corta.
  - La Graph API no expone los posts fijados ⚠️: el orden puede no coincidir con el perfil. Se avisa en la página.
  - El recorte de las teselas (4:5 o 1:1) cambia con la app de Instagram. Se elige uno y se documenta.
  - Límite de llamadas de Meta: la caché lo acota a una lectura cada 10 min por cuenta.
- **Incógnitas / preguntas abiertas**: ¿hace falta reordenar arrastrando? Hoy está fuera (+6h aprox.).

### C-03: Campañas

- **Requisito origen**: petición (3); bundle §Planificación (`/performance-dashboard/campaigns*` y `reporting/campaigns-dashboard`); spec CA-11..CA-13.
- **Descripción**:
  - **Modelo:** `campaigns` por marca (nombre, color, fechas y archivo) y `posts.campaign_id` (`nullOnDelete`).
  - **Gestión:** una página de campañas y un selector en `ComposerHeader`, con alta en línea.
  - **Panel:** agrega el último snapshot de cada destino de la campaña. Muestra los KPIs (posts, vistas, alcance acumulado, interacciones, likes, comentarios, compartidos, guardados y engagement), el desglose por red, la serie diaria y la lista de posts.
  - **MCP:** `campaign_id` en `create_post`, `update_post` y `get_post`, y la herramienta `list_campaigns`.
- **Complejidad**: Media.
- **Esfuerzo**: **44,4h** (37h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Migración aditiva, modelo, `UserScope` y regla «misma marca» | 1,5h |
  | `CampaignService`, controlador y validación | 3h |
  | Página de campañas: lista, alta, edición, archivo y color | 4h |
  | Selector en el compositor, `PostRequest` y `PostService::fill()`; las copias desde biblioteca y plantillas conservan la campaña | 3h |
  | MCP: `campaign_id` en el payload y en `get_post`, y `list_campaigns` | 2,5h |
  | `CampaignAnalytics`: extraer la consulta del último snapshot de `AnalyticsService::postStats`, agregar por red, totales y serie diaria | 6h |
  | Panel `Pages/Campaigns/Show.vue`: KPIs, tabla por red, gráfica (`LineChart`) y lista de posts | 6h |
  | Pest: agregación con `null`, propiedad, MCP y regresión del dashboard (CA-11..CA-13) | 4h |
  | E2E con axe | 2,5h |
  | Documentación y revisión | 4h |
  | **Total** | **37h** |

- **Previsión IA**: 1,35M in / 0,15M out tok · ⚠️ verificar €.
- **Coste**: (44,4h × 50 €/h) + tokens = **2.220 € + tokens**.
- **Impacto / áreas afectadas**:
  - `database/migrations`, `app/Models/{Campaign,Post}.php`, `app/Services/{CampaignService,CampaignAnalytics,AnalyticsService}.php` y `app/Http/Controllers/CampaignController.php`;
  - `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Mcp/Tools/{PostPayload,GetPost,ListCampaigns}.php`;
  - `resources/js/Pages/Campaigns/*` y `resources/js/Components/Composer/ComposerHeader.vue`.
- **Dependencias y prerequisitos**: ninguna funcional. Comparte `PostRequest`, `PostService` y `PostPayload` con C-01b: conviene no hacerlas en paralelo.
- **Riesgos**:
  - Extraer la consulta del dashboard puede romperlo. Se mitiga con la regresión de sus tests antes y después.
  - **Las métricas se congelan a los 30 días de publicar** (`recent_post_days`, `config/social.php:158`). El panel lo dice. Ampliarlo para los posts de campaña supone más llamadas a las APIs (+3h aprox.).
  - El alcance sumado no es de usuarios únicos: se rotula «alcance acumulado».
- **Incógnitas / preguntas abiertas**: D3 (por marca y por post). ¿Sección de campaña en el informe PDF? Hoy está fuera (+6h aprox.).

### C-04: Media desde una URL o desde Google Drive

- **Requisito origen**: petición (4); bundle §Compositor (`integrations/google-drive/*` y `actions/normalize/image/url`); spec CA-14..CA-18.
- **Descripción**: un solo camino de importación con dos fuentes.
  - **Común:** `media_assets.source`; job `ImportRemoteMedia` en la cola `media`, que descarga **por tramos** (`Range`) con presupuesto de 80 s por pasada y se reencola hasta terminar, con un tope de bytes; al acabar llama a `MediaUploadService::finalize()`, que ya comprueba el tamaño real, el tipo real y ffprobe. La UI sondea el estado.
  - **C-04a. URL.** `UrlGuard` contra SSRF: solo `http`/`https`; resolución DNS con rechazo de IPs privadas, reservadas, *loopback* y *link-local* (IPv4 e IPv6); redirecciones seguidas a mano y vueltas a comprobar; el host se fija a la IP validada. Más la herramienta MCP `import_media_from_url`.
  - **C-04b. Google Drive** con la opción mínima: **Google Picker con `drive.file`**. El navegador pide un token con Google Identity Services, sin refresh token. El Picker filtra imagen y vídeo. El servidor recibe el `fileId` y el token, y descarga con `files.get?alt=media`. El token viaja cifrado en el job (`ShouldBeEncrypted`), no se guarda ni se registra, y caduca en 1 h.
- **Complejidad**: **Alta** (seguridad SSRF, descarga larga con los workers de 120 s y OAuth en el navegador).
- **Esfuerzo**: **48,6h** (40,5h base) · confianza Media-Baja. Desglose base:

  | Entrega | Partida | Horas base |
  |---|---|---|
  | C-04a | Migración aditiva de `source` y job `ImportRemoteMedia` con descarga por tramos, presupuesto y reencolado | 9h |
  | C-04a | `UrlGuard` (DNS, lista de IPs, redirecciones, puertos) y endpoint con límite de peticiones | 5h |
  | C-04a | UI «Desde URL» en `MediaSection`, sondeo y errores en español | 2h |
  | C-04a | MCP `import_media_from_url` | 2h |
  | C-04a | Pest: tabla SSRF, tope, tipo y tramos (CA-14..CA-17) | 3h |
  | C-04a | E2E con axe, documentación (cola nueva en Hestia) y revisión de seguridad | 4h |
  | | **Subtotal C-04a** | **25h** |
  | C-04b | Configuración (clave, app id y client id), `useDrivePicker.js` con GIS y Picker, endpoint y `DriveFileSource` | 8h |
  | C-04b | UI «Google Drive» (oculto sin configurar) | 2h |
  | C-04b | Pest con `Http::fake` de Drive (CA-18) | 2h |
  | C-04b | E2E, documentación de Google Cloud y smoke real con el usuario | 2,5h |
  | C-04b | Revisión | 1h |
  | | **Subtotal C-04b** | **15,5h** |
  | | **Total C-04** | **40,5h** |

- **Previsión IA**: 1,48M in / 0,16M out tok (C-04a 1,01M · C-04b 0,63M) · ⚠️ verificar €.
- **Coste**: (48,6h × 50 €/h) + tokens = **2.430 € + tokens**. C-04a: 30h · 1.500 €. C-04b: 18,6h · 930 €.
- **Impacto / áreas afectadas**:
  - `app/Services/MediaUploadService.php` (solo se reutiliza `finalize()`), `app/Services/Media/{RemoteMediaImporter,UrlGuard,DriveFileSource}.php` y `app/Jobs/ImportRemoteMedia.php`;
  - `app/Models/MediaAsset.php`, `app/Http/Controllers/MediaController.php`, `routes/web.php`, `config/media.php` y `config/social.php`;
  - `app/Mcp/Tools/ImportMediaFromUrl.php`;
  - `resources/js/Components/Composer/MediaSection.vue` y `resources/js/Composables/useDrivePicker.js`;
  - `deploy/hestia` y `docs/modulos/{media,despliegue}.md`.
- **Dependencias y prerequisitos**: C-04b depende del camino común de C-04a. D4 (configuración de Google Cloud) y D5 (cola nueva en Hestia).
- **Riesgos**:
  - **SSRF (CWE-918).** Es el riesgo principal. Se mitiga con `UrlGuard` y un host fijado a la IP validada contra el *DNS rebinding*, más una revisión de seguridad propia.
  - **Descarga larga.** Un vídeo de varios GB no cabe en 120 s. Los tramos con `Range` lo resuelven si el servidor remoto los admite; si no, se corta con un error claro. El tope es `media.max_bytes`.
  - **Disco local en Hestia.** Un vídeo importado ocupa lo mismo que uno subido. `app:media-prune` ya limpia los abandonados.
  - **Token de Drive.** Caduca en 1 h. Si la cola tarda en empezar, la importación falla con «Vuelve a elegir el fichero».
  - **Clasificación de `drive.file`.** Si Google la cambiara a sensible, habría que verificar la app ⚠️.
- **Incógnitas / preguntas abiertas**: D4 y D5. Si los enlaces públicos de Drive (`drive.google.com/file/d/…`) deben ir por C-04a: no se recomienda, porque Google los sirve con una página de confirmación en los ficheros grandes.

---

## Comparativa

| # | Característica | Complejidad | Horas | Coste € | Tokens | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-01a | Textos guardados | Baja | 20,4h | 1.020 € | 0,69M | Alta | Alta |
| C-01b | Primer comentario (IG y FB; YT 8,4h dentro) | Alta | 43,2h | 2.160 € | 1,46M | Alta | Media |
| C-02 | Cuadrícula de Instagram | Media | 28,8h | 1.440 € | 0,97M | Alta | Media |
| C-03 | Campañas | Media | 44,4h | 2.220 € | 1,50M | Media | Media |
| C-04a | Media desde URL | Alta | 30h | 1.500 € | 1,01M | Media | Media-Baja |
| C-04b | Media desde Google Drive | Media | 18,6h | 930 € | 0,63M | Baja | Media-Baja |
| | **Total** | | **185,4h** | **9.270 €** | **6,26M** | | |

Por característica de la petición: **C-01** 63,6h · 3.180 € · Alta (por C-01b) · **C-02** 28,8h · 1.440 € · Media · **C-03** 44,4h · 2.220 € · Media · **C-04** 48,6h · 2.430 € · Alta.

---

## Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 154,5h × 50 €/h | 7.725 € |
| Margen de contingencia | +20 % sobre desarrollo base (30,9h) | 1.545 € |
| Tokens IA (input) | 5,63M tok × precio | ⚠️ verificar |
| Tokens IA (output) | 0,63M tok × precio | ⚠️ verificar |
| Coste de proceso (LES-008) | Spec y evaluación (medidas en `generacion:`), más el plan y el diseño de C-01b y C-04: ≈ 1,5h IA. Referencia: 0,4–1,2h por artefacto en las iniciativas anteriores | tokens ⚠️ verificar |
| **Total estimado (con margen)** | | **9.270 € + tokens** |

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 185,4 h |
| Horas IA (ejecución) | 25,0 h (20,9h base) |
| Supervisión humana | 6,3 h |
| **Horas totales (IA + supervisión)** | **31,3 h** |
| Horas ahorradas | 154,1 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,96 |

> Horas mostradas **con el margen de contingencia (+20 %)** ya aplicado. Horas IA = tokens × 1,2 / 300.000. Por característica: C-01 8,6h (C-01a 2,8h · C-01b 5,8h) · C-02 3,9h · C-03 6,0h · C-04 6,6h (C-04a 4,1h · C-04b 2,5h). El ratio no está calibrado: en `social-scheduler` el real medido fue un 87–92 % menor. El plazo real probablemente lo marcará la revisión y el smoke del usuario, no la ejecución (LES-009).

---

## Recomendación

- **Veredicto**: **go**, condicionado a que el usuario confirme D1–D5. Si el presupuesto aprieta, la ola A sola (C-01a, C-01b IG y FB, y C-02) cubre lo de uso diario por **84h · 4.200 €**.
- **Quick wins** (bajo coste, alto valor): **C-01a** (20,4h) y **C-02** (28,8h, sin permisos nuevos).
- **Costosas / a valorar**:
  - C-01b, por los efectos externos y el scope de YouTube;
  - C-04a, por la seguridad y la descarga larga;
  - C-04b, que depende de configurar Google Cloud y aporta menos que la URL si los ficheros ya están en local.
- **Orden sugerido**: **C-01a → C-01b (IG y FB) → C-02 → C-03 → C-04a → C-04b**, y **C-01b-YT** cuando el usuario acepte reconectar. Motivos:
  - es la prioridad del bundle;
  - C-01a va antes que C-01b porque el primer comentario reutiliza la inserción de textos guardados;
  - C-01b y C-03 tocan `PostRequest`, `PostService` y `PostPayload`, así que van en serie para no chocar en la paridad del MCP;
  - C-02 es independiente y muy visible;
  - C-04 cierra porque es la de más riesgo y C-04b depende del camino común de C-04a.

  | Ola | Entregas | Horas | Coste |
  |---|---|---|---|
  | A | C-01a, C-01b (IG y FB) y C-02 | 84h | 4.200 € |
  | B | C-03 y C-04a | 74,4h | 3.720 € |
  | Opcional | C-01b-YT | 8,4h | 420 € |
  | Opcional | C-04b | 18,6h | 930 € |
  | | **Total** | **185,4h** | **9.270 €** (cuadra con el total) |

- **Diseño**: pasar por `architect` antes del plan para **C-01b** (estado del comentario e idempotencia) y **C-04** (descarga por tramos, cola y `UrlGuard`). El resto no lo necesita.
- **Fuera de alcance recomendado**:
  - reordenar la cuadrícula arrastrando;
  - campañas entre marcas y en el PDF;
  - enlaces públicos de Drive por la vía de URL;
  - `list_saved_texts` en el MCP;
  - todo lo excluido por el usuario: redes nuevas, aprobaciones, recurrentes y equipos.

---

## Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Romper la paridad web/MCP en los campos nuevos del post (`first_comment` y `campaign_id`) | Media | Medio | `PostPayload` ya reutiliza `PostRequest::rules()`. Pest de paridad en cada entrega |
| Efectos externos no deseados en redes reales durante el desarrollo | Baja | Alto | Solo `Http::fake` en Pest y E2E. El smoke real lo hace el usuario |
| SSRF en la importación por URL | Media | Alto | `UrlGuard`, host fijado a la IP validada y revisión de seguridad propia (C-04a) |
| Los jobs largos chocan con los workers de 120 s de Hestia | Alta | Medio | Descarga por tramos con presupuesto y reencolado. Cola `media` propia (D5) |
| Reconexión de YouTube al añadir `youtube.force-ssl` | Media | Medio | C-01b-YT en una entrega aparte, con aviso en el compositor y en Conexiones |
| Regresión del dashboard al extraer la consulta de snapshots | Media | Medio | Tests del dashboard antes y después. Extracción sin cambio de comportamiento |
| El plazo lo marca la revisión del usuario, no la ejecución | Alta | Bajo | Revisión de robustez presupuestada por característica (LES-001 y LES-009). Smoke por olas |

---

## Siguiente paso

Para **ejecutar** lo aprobado, genera el plan detallado con el agente **`planner`**, que crea `improvement-plan.md` y `tasks.md` en `docs/roadmap/2026-10-24-metricool-parity-2/`. Antes, pasa por `architect` para C-01b y C-04 si entran.

Indica qué entregas se aprueban (todas, la ola A o un subconjunto) y las respuestas a D1–D5. La secuencia es C-01a antes que C-01b, C-01b y C-03 en serie, y C-04a antes que C-04b. El `planner` heredará las horas y los costes de esta evaluación por entrega, sin re-estimar desde cero. Al crear el plan actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec.

---

## Changelog

- 2026-10-24: creación (estado `en-revision`), en la fase 1 de `/pm-cycle`. Spec en `borrador`. Puerta go/no-go pendiente del usuario.
