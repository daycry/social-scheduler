# Competidores de Instagram

Seguimiento de cuentas **profesionales** públicas de Instagram con Business Discovery y comparativa con la cuenta propia de la marca.

| Pieza | Ruta |
|---|---|
| Conector | `app/Connectors/Meta/BusinessDiscovery.php` |
| Servicio | `app/Services/CompetitorService.php` |
| Jobs | `CollectCompetitorSnapshots` (diario, `SOCIAL_COMPETITORS_DAILY_AT`) → `CollectCompetitorSnapshot` |
| UI | `CompetitorController`, `resources/js/Pages/Competitors/Index.vue` |

El alta comprueba el usuario al momento. Cada día se guarda un snapshot (seguidores y publicaciones). La comparativa muestra el último valor y el crecimiento a 7 y 30 días. El dato propio de **publicaciones** sale del `media_count` de Instagram, que el snapshot de cuenta guarda como `video_count` (stock); los seguidores, de `followers_count`. Máximo `SOCIAL_COMPETITORS_MAX` por marca.
