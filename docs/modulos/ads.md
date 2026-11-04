# Meta Ads (solo lectura)

Cuentas publicitarias, campañas y métricas básicas (gasto, impresiones, clics, CTR, CPC) por periodo. **Solo lectura**: no crea ni modifica anuncios.

La conexión origen pertenece a la marca seleccionada y al propietario. La caché separa propietario, marca, conexión y versión de credenciales; cambiar el periodo conserva `brand_id`. Meta devuelve las cuentas publicitarias accesibles a esa conexión: todavía no hay una asignación persistente de cada cuenta publicitaria a una marca.

| Pieza | Ruta |
|---|---|
| Conector | `app/Connectors/Meta/Ads.php` |
| Servicio | `app/Services/AdsService.php` (caché `SOCIAL_ADS_CACHE_MINUTES`) |
| UI | `AdsController`, `resources/js/Pages/Ads/Index.vue` (`/ads`) |

Requiere el permiso `ads_read`: actívalo en la app de Meta, añádelo a `META_EXTRA_SCOPES` y reconecta. Sin él la página explica qué activar (nunca un 0 engañoso). Las cuentas se leen con `me/adaccounts` y el token va siempre en `Authorization: Bearer`.
