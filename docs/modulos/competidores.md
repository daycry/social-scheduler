# Competidores de Instagram

Seguimiento de cuentas **profesionales** públicas de Instagram con Business Discovery y comparativa con la cuenta propia de la marca.

| Pieza | Ruta |
|---|---|
| Conector | `app/Connectors/Meta/BusinessDiscovery.php` |
| Servicio | `app/Services/CompetitorService.php` |
| Jobs | `CollectCompetitorSnapshots` (diario, `SOCIAL_COMPETITORS_DAILY_AT`) → `CollectCompetitorSnapshot` |
| UI | `CompetitorController`, `resources/js/Pages/Competitors/Index.vue` |

El alta comprueba el usuario al momento. Cada día se guarda un snapshot (seguidores y publicaciones). La comparativa muestra el último valor y el crecimiento a 7 y 30 días. El dato propio de **publicaciones** sale del `media_count` de Instagram, que el snapshot de cuenta guarda como `video_count` (stock); los seguidores, de `followers_count`. Máximo `SOCIAL_COMPETITORS_MAX` por marca.

## Comparativa por rango, ranking y publicaciones (T-20)

- **Rango**: `from`/`to` (UTC, por defecto 30 días, máximo 366, nunca en el futuro). Cada cuenta muestra seguidores al inicio y al final del rango, crecimiento absoluto y %, variación de publicaciones e interacciones medias de lo recogido. Sin dos snapshots en el rango el crecimiento es `null` («—»), nunca 0.
- **Ranking**: `sort` = `followers`, `growth`, `growth_pct`, `media` o `avg_interactions`; la cuenta propia entra en el ranking y lo que no tiene dato queda sin posición.
- **Publicaciones destacadas**: `CollectCompetitorPosts` (diario, `SOCIAL_COMPETITORS_POSTS_AT`) o el botón «Actualizar publicaciones» leen `business_discovery.media` (últimas 25) en la tabla `competitor_posts`. Los me gusta y comentarios que la cuenta oculta quedan `null`; no se inventa ninguna métrica que la API no dé (alcance, impresiones y guardados no existen para terceros). El top es por me gusta + comentarios visibles.
- **CSV**: `GET /competitors/export.csv?brand_id&from&to&sort` con `CsvWriter` (BOM, separador según `reports.csv_locale`, neutralización de fórmulas).
- **MCP**: `compare_competitors` (`stats:read`) con los mismos parámetros y `top_posts`; los textos de las publicaciones llevan `untrusted: true`.
- **Fuera**: otras redes (solo Instagram ofrece datos públicos de terceros por API oficial) y métricas de alcance o impresiones de competidores.
