# Analítica y snapshots

La app guarda un snapshot diario por cuenta y métricas por publicación reciente. Los dashboards y los informes leen de esas tablas, no de las redes.

## Piezas

| Pieza | Ruta |
|---|---|
| Job diario | `app/Jobs/CollectSnapshots.php` → `CollectAccountSnapshot.php` |
| Histórico inicial | `app/Jobs/BackfillMetrics.php` |
| Métricas de publicaciones | `app/Jobs/RefreshPostMetrics.php` |
| Recolector | `app/Analytics/SnapshotCollector.php` |
| Día cerrado por zona | `app/Analytics/Days.php` |
| Cálculos (crecimiento, engagement) | `app/Analytics/Calculator.php`, `Metric.php`, `Period.php` |
| Servicio | `app/Services/AnalyticsService.php` (`withLatestSnapshot`: el último snapshot de cada destino, compartido con las campañas) |
| Campañas | `app/Services/CampaignAnalytics.php`, `resources/js/Pages/Campaigns/{Index,Show}.vue` |
| Lectores por red | `Meta/Insights.php`, `Google/Analytics.php`, `TikTok/Display.php`, `Bluesky/BlueskyInsights.php` |
| UI | `resources/js/Pages/Dashboard/{Index,Network}.vue`, `LineChart.vue` |
| Tablas | `account_snapshots` (con `settled_at`), `post_metric_snapshots` |

## Cuándo y cómo se recoge

`collect-snapshots` corre a las **12:00 UTC** (`SOCIAL_ANALYTICS_DAILY_AT`). Meta y YouTube Analytics definen el día en `America/Los_Angeles`, que cierra entre las 07:00 y las 08:00 UTC; a las 12:00 UTC el día anterior ya está cerrado. TikTok usa UTC. `Days::closed($network)` calcula el último día cerrado por red (`config/social.php` → `analytics.timezones`).

Un snapshot se da por asentado (`settled_at`) cuando tiene los flujos diarios esperados. Si falta algo (latencia de YouTube de 1–3 días, caídas, límites de uso), se reintenta durante `SOCIAL_ANALYTICS_SETTLE_DAYS` (3) días. Pasada la ventana se acepta incompleto.

Las publicaciones se refrescan cada 6 h (`15 */6 * * *`) durante las primeras 72 h (`SOCIAL_ANALYTICS_REFRESH_WINDOW`) y en el job diario para las de los últimos 30 días (`SOCIAL_ANALYTICS_RECENT_POST_DAYS`).

## Histórico inicial al conectar

| Red | Días | Motivo |
|---|---|---|
| Instagram | 30 | límite de 30 días por consulta de insights |
| Facebook | 90 | rangos de <= 30 días, 3 llamadas por rango |
| YouTube | 365 | un informe `dimensions=day` por tramo de 90 días |
| TikTok | 0 | la Display API no da serie histórica |
| Bluesky | 0 | solo hay stocks (seguidores y posts): no existe el valor de un día pasado |

## Métricas: lo medido en el spike

Instagram: `impressions` ya no existe; se usa `views`. Facebook: `page_impressions` y `page_fans` están retiradas; se usan `page_media_view`, `page_post_engagements`, `page_follows`. `follower_count` de IG no admite `metric_type=total_value` y se pide como serie diaria. Detalle en [decisiones por red](../referencia/decisiones-por-red.md).

Regla de producto (CA-10): una métrica que la red no ofrece se muestra como "no disponible", nunca como 0. El periodo por defecto termina en el último día ya cerrado y recogido de las redes de la marca (no «ayer» si aún no se ha recogido), en la zona horaria de la marca; web y MCP usan la misma regla. Los dashboards tienen periodo, comparación (anual de igual longitud) y KPIs por publicación calculados en SQL sobre todas las del periodo, con un top N explícito (`posts_total`, `posts_limit`, `posts_truncated`).

TikTok: solo lo que permitan `user.info.stats`/`video.list`; el `external_id` de un borrador es el `publish_id`, no un ID de vídeo (`progress.external_id_kind = publish_id`), y no se usa para leer métricas.

## Bluesky

`BlueskyInsights` lee del AppView **público** (`BLUESKY_APPVIEW`, `public.api.bsky.app`, sin sesión: así una sesión caída no rompe las métricas). ⚠️ El host se confirma en el smoke (T-30).

- **Cuenta** (`app.bsky.actor.getProfile`): `followersCount` → `followers` y `postsCount` → `post_count` (STOCK nuevo; `video_count` sigue significando «vídeos»). `EXPECTED_FLOWS['bluesky'] = []`, como TikTok: no hay flujos diarios. Solo se lee el último día cerrado (zona UTC).
- **Post** (`app.bsky.feed.getPosts`, lotes de 25 URIs con `uris` repetido): `likeCount` → `likes`, `replyCount` → `comments`, `repostCount + quoteCount` → `shares` y la suma → `interactions`. `views`, `reach` y `saved` son `null` («no disponible», nunca 0). Un post borrado queda con todo `null`.
- `AnalyticsService::SUPPORTED['bluesky']` = seguidores y publicaciones.

## Trabajo manual pendiente

M-03: dejar correr el job con cuentas reales ~30 días y revisar los gráficos. Ver [pendientes](../referencia/pendientes-manuales.md).

## Campañas (C-03)

Una **campaña** es una etiqueta por marca (nombre, color, fechas, archivada o no) que se asigna al **post entero**: todos sus destinos cuentan. Al borrar la campaña los posts se conservan, sin campaña. Solo se admiten campañas de la marca del post (web y MCP).

El panel (`/campaigns/{id}`) lee de las mismas tablas que el dashboard y reutiliza `AnalyticsService::withLatestSnapshot`, así que ambos ven el mismo «último valor» de cada destino. `CampaignAnalytics` suma el **último snapshot de cada destino publicado** de la campaña:

- Una métrica que ningún destino informa es `null` y se muestra «No disponible», nunca 0. Si solo informan algunos destinos, la suma es la de los que informan.
- El **alcance** se rotula «alcance acumulado»: es la suma del alcance de cada publicación, no usuarios únicos.
- El engagement usa solo los destinos con visualizaciones e interacciones.
- La serie diaria del panel de campaña se agrega en SQL: cada día suma los snapshots leídos ese día, sin arrastrar el último valor de los destinos que no se leyeron (su último punto puede no coincidir con los KPIs, que usan el último snapshot de cada destino; la UI lo avisa). La lista de posts se recorta a 100 (con el total y un aviso).
- **Límite de 30 días:** las métricas de una publicación solo se refrescan durante `SOCIAL_ANALYTICS_RECENT_POST_DAYS` (30) días; pasado ese plazo quedan congeladas con el último dato leído. El panel lo avisa.

## Todas las marcas (C-03b)

La página `/dashboard/todas-las-marcas` (`DashboardController::allBrands`) y `get_stats` sin marca (MCP) leen de `app/Services/AggregateAnalytics.php`, que suma lo que `AnalyticsService::summary()` calcula para cada marca del usuario. No relee los snapshots por su cuenta: cada marca usa las mismas consultas acotadas de la paridad 2 (sin recorrer publicaciones ni hidratar modelos), así que el agregado y el dashboard por marca no pueden discrepar. Las consultas crecen con el número de marcas, no con el de snapshots ni cuentas (lo fija `AllBrandsFlowTest`).

- **Suma con `null`:** cada métrica es la suma de las marcas que la tienen; si ninguna la tiene es `null` y se muestra «No disponible», nunca 0. Bluesky no da visualizaciones ni alcance, y Threads dará visitas al perfil, no visualizaciones: esas redes aparecen en el desglose como no disponibles.
- **Acumulado:** seguidores, vídeos y publicaciones son *stocks*. Se suma el último valor de cada cuenta; no son usuarios únicos (una misma persona puede seguir varias redes) y así se rotula en la página.
- **Días UTC:** sin marca no hay zona horaria, así que el periodo (y su valor por defecto) se calcula en días UTC, como los snapshots. Los snapshots se suman por su fecha, así que con fechas explícitas el total coincide con el de cada marca; solo el periodo por defecto (que termina en el último día cerrado) se calcula en UTC y no en la zona de cada marca, y puede terminar un día antes que el de una marca en otra zona.
- **Caché:** el resultado se guarda `SOCIAL_ALL_BRANDS_CACHE_MINUTES` (5; 0 la desactiva) por usuario y periodo. Un snapshot nuevo tarda ese tiempo en reflejarse.
- **Solo marcas propias:** se parte de las marcas del usuario; las de otros no se suman ni aparecen.
