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
| Servicio | `app/Services/AnalyticsService.php` |
| Lectores por red | `Meta/Insights.php`, `Google/Analytics.php`, `TikTok/Display.php` |
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

## Métricas: lo medido en el spike

Instagram: `impressions` ya no existe; se usa `views`. Facebook: `page_impressions` y `page_fans` están retiradas; se usan `page_media_view`, `page_post_engagements`, `page_follows`. `follower_count` de IG no admite `metric_type=total_value` y se pide como serie diaria. Detalle en [decisiones por red](../referencia/decisiones-por-red.md).

Regla de producto (CA-10): una métrica que la red no ofrece se muestra como "no disponible", nunca como 0. Los dashboards tienen periodo, comparación (anual de igual longitud) y KPIs por publicación calculados en SQL sobre todas las del periodo, con un top N explícito (`posts_total`, `posts_limit`, `posts_truncated`).

TikTok: solo lo que permitan `user.info.stats`/`video.list`; el `external_id` de un borrador es el `publish_id`, no un ID de vídeo (`progress.external_id_kind = publish_id`), y no se usa para leer métricas.

## Trabajo manual pendiente

M-03: dejar correr el job con cuentas reales ~30 días y revisar los gráficos. Ver [pendientes](../referencia/pendientes-manuales.md).
