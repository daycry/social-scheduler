# Competidores (Instagram, Bluesky y YouTube)

Seguimiento de cuentas públicas de terceros y comparativa con la cuenta propia de la marca, **por red**: Instagram (cuentas profesionales, Business Discovery), Bluesky (API pública, sin cuenta propia) y YouTube (Data API, con cuota). La pantalla de Competidores ofrece las redes como chips y cada una muestra solo sus competidores.

| Pieza | Ruta |
|---|---|
| Contrato | `app/Connectors/Contracts/CompetitorSource.php` (una fuente por red) y `app/Connectors/CompetitorSources.php` (registro; solo las redes disponibles) |
| Fuentes | `Meta/InstagramCompetitors` (sobre `BusinessDiscovery`), `Bluesky/BlueskyCompetitors`, `Google/YouTubeCompetitors` |
| Servicio | `app/Services/CompetitorService.php` |
| Jobs | `CollectCompetitorSnapshots` (diario, `SOCIAL_COMPETITORS_DAILY_AT`) → `CollectCompetitorSnapshot` |
| UI | `CompetitorController`, `resources/js/Pages/Competitors/Index.vue` |

El alta comprueba el usuario al momento. Cada día se guarda un snapshot (seguidores y publicaciones). La comparativa muestra el último valor y el crecimiento a 7 y 30 días. El dato propio de **publicaciones** sale del `media_count` de Instagram, que el snapshot de cuenta guarda como `video_count` (stock); los seguidores, de `followers_count`. Máximo `SOCIAL_COMPETITORS_MAX` por marca.

## Bluesky y YouTube (T-25)

- **Contrato `CompetitorSource`**: `normalize()` (acepta `@usuario`, URL del perfil o del canal), `lookup()` (perfil: nombre, avatar, seguidores, publicaciones; `null` si la red no lo da) y `media()` (últimas publicaciones; likes y comentarios `null` si no se ven, nunca 0). `needsAccount()` indica si hace falta una cuenta propia conectada de esa red (Instagram y YouTube sí; Bluesky no). Los errores son los de la cola (`ReconnectRequired`, `QuotaExceeded`, `Transient`...) y `CompetitorNotFound` para una cuenta que no existe; `GuardsInsightsErrors` los reintenta igual que el resto de la analítica (cuota y transitorios con espera creciente y `Retry-After`; credenciales rechazadas marcan la cuenta para reconectar).
- **Bluesky**: `app.bsky.actor.getProfile` (`did`, `handle`, `displayName`, `avatar`, `followersCount`, `postsCount`) y `app.bsky.feed.getAuthorFeed` con `filter=posts_no_replies` (los reposts se descartan; me gusta = `likeCount`, comentarios = `replyCount`), ambos del AppView público. Perfil inexistente, suspendido o desactivado: «No encontramos esa cuenta…». El DID se guarda en `competitors.external_id`.
- **YouTube**: `channels.list` (`part=snippet,statistics`, por `forHandle` o `id`; suscriptores `null` si el canal los oculta) y, para las publicaciones, `playlistItems.list` de la lista de subidas (`UU` + id del canal sin `UC`) más `videos.list` (`statistics`). Se leen con el token de un canal propio conectado de la marca. Cada lista cuesta **1 unidad**: perfil = 1, publicaciones = 2.
- **Cuota de YouTube**: cada llamada se comprueba antes y se carga después en `YouTubeQuota` (cupo del proyecto, 10.000/día) y en `YouTubeCompetitorBudget` (tope diario propio de competidores, `SOCIAL_COMPETITORS_YOUTUBE_UNITS`, 300 por defecto, igual que el presupuesto del buzón) para no dejar sin cupo las subidas. Sin presupuesto no se llama a la API. `quotaExceeded`/`dailyLimitExceeded` (403) marcan el día como agotado y 429 respeta `Retry-After`: ambos son `QuotaExceededException` y los jobs de snapshot y publicaciones se liberan con espera creciente (tope de una hora). `QuotaPolicy` (reprogramar o fallar publicaciones) no interviene: es solo de publicación.
- **Datos**: `competitors.network` (por defecto `instagram`), `competitors.external_id` y `username` hasta 253 caracteres; único por marca + red + usuario. `competitor_posts.external_id` guarda el `rkey` de Bluesky o el id del video.
- **UI**: chips de red (mismo patrón que la analítica por red), formulario con el texto de cada red y la misma tabla, rango, ranking, CSV y publicaciones destacadas. Sin cuenta propia (Instagram o YouTube) se muestra el mismo estado vacío «Conecta … para comparar competidores».
- **MCP**: `compare_competitors` admite `network` (`instagram` por defecto, `bluesky`, `youtube`). Los informes siguen usando Instagram.

## Comparativa por rango, ranking y publicaciones (T-20)

- **Rango**: `from`/`to` (UTC, por defecto 30 días, máximo 366, nunca en el futuro). Cada cuenta muestra seguidores al inicio y al final del rango, crecimiento absoluto y %, variación de publicaciones e interacciones medias de lo recogido. Sin dos snapshots en el rango el crecimiento es `null` («—»), nunca 0.
- **Ranking**: `sort` = `followers`, `growth`, `growth_pct`, `media` o `avg_interactions`; la cuenta propia entra en el ranking y lo que no tiene dato queda sin posición.
- **Publicaciones destacadas**: `CollectCompetitorPosts` (diario, `SOCIAL_COMPETITORS_POSTS_AT`) o el botón «Actualizar publicaciones» leen `business_discovery.media` (últimas 25) en la tabla `competitor_posts`. Los me gusta y comentarios que la cuenta oculta quedan `null`; no se inventa ninguna métrica que la API no dé (alcance, impresiones y guardados no existen para terceros). El top es por me gusta + comentarios visibles.
- **CSV**: `GET /competitors/export.csv?brand_id&network&from&to&sort` con `CsvWriter` (BOM, separador según `reports.csv_locale`, neutralización de fórmulas).
- **MCP**: `compare_competitors` (`stats:read`) con los mismos parámetros y `top_posts`; los textos de las publicaciones llevan `untrusted: true`.
- **Fuera**: Facebook, TikTok, Threads, LinkedIn y Pinterest (sin API pública de datos de terceros adecuada), y métricas de alcance o impresiones de competidores.
