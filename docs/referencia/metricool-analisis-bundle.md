# Metricool: análisis estático del frontend (2026-10-24)

Inventario de lo que la aplicación web de Metricool **declara en su código** y que Social Scheduler no tiene. Complementa [metricool-analisis-ui.md](metricool-analisis-ui.md) (recorrido visual) y [metricool-analisis-fuentes-publicas.md](metricool-analisis-fuentes-publicas.md) (ayuda, API y MCP).

## Método

- Herramienta: REA (`analyze_web_bundle` e `inspect_web_page`) sobre la pestaña de Planning · Calendar de la sesión propia del usuario en Edge (CDP local `127.0.0.1:9222`).
- Solo lectura: se parsearon **303 scripts** de `app.metricool.com` y `static.metricool.com` sin ejecutarlos, sin navegar ni hacer clic.
- Resultado: **81 rutas** del router y **204 endpoints candidatos** de su API.
- Límite: que una ruta o un endpoint exista en el código **no prueba** que esté activo en el plan del usuario ni que funcione. Las rutas construidas por concatenación pueden faltar.
- No se copia código, textos ni marca de Metricool: solo se usa como lista de funciones.

## Ya cubierto en Social Scheduler

Competidores, mejores horas, biblioteca, papelera, autolistas, inbox de comentarios IG/FB, SmartLinks, Meta Ads (lectura), IA de texto, informes PDF, notificaciones, 2FA/passkeys, API keys y MCP, compositor con texto por red, presets, UTM y notas internas.

## Lo que Metricool tiene y nosotros no

### Redes

| Red | Indicio en el código |
|---|---|
| LinkedIn (páginas y menciones) | `admin/linkedin-page-connection`, `scheduler/catalogs/linkedin/mentions`, `/evolution-linkedin` |
| Pinterest (tableros) | `scheduler/boards/pinterest`, `/evolution-pinterest` |
| X / Twitter | `admin/twitter-connection`, `actions/twitter/suggestions` |
| Bluesky | `actions/bluesky/suggestions` |
| Threads | `catalogs/threads/geo-gating/eligibility`, `catalogs/threads/share-as-instagram-story/scopes` |
| Google Business Profile (+ reseñas) | `admin/gmb-location-connection`, `inbox/reviews` |
| Twitch, blog/web | `/evolution-twitch`, `/evolution-blog`, `admin/detectwebsite` |

### Compositor

| Función | Indicio | Estado en Social Scheduler |
|---|---|---|
| Primer comentario | (ayuda pública §1.6) | Spec C-06, aplazado fuera del MVP; sin código |
| Textos guardados | `settings/users/saved-texts` | No existe |
| Sugerencias de hashtags | `actions/instagram/suggestions/hashtags` | No existe |
| Búsqueda de ubicación | `actions/facebook/search-location` | Spec C-07, aplazado |
| IG: productos y audio | `catalogs/products/instagram`, `catalogs/instagram/audio` | No existe |
| TikTok: canciones en tendencia, enlace a artículo | `catalogs/tiktok/trending-tracks`, `catalogs/tiktok/article-link/eligibility` | No existe |
| YouTube: categorías y listas | `catalogs/youtube/categories`, `catalogs/youtube/playlists` | Categoría sí; lista de reproducción a revisar |
| GIF | `api/gifs/search` | No existe |
| Media desde Canva, Google Drive o URL | `integrations/canva/*`, `integrations/google-drive/*`, `actions/normalize/image/url` | No existe |
| Programar en lenguaje natural (IA) | `integrations/ai/natural-language-scheduling` | Solo sugerencia de texto |
| Revisión, aprobaciones, recurrentes | `posts/approvals-config`, `/my-tasks/*` | Fuera a propósito (un solo usuario) |

### Planificación y analítica

- Vista de cuadrícula de Instagram con lo programado: `brands/connections/instagram/media-feed` (y `youtube/media-feed`).
- Vistas de calendario personalizadas: `scheduler/calendars`, `calendars/metadata` («Create view»).
- Campañas con panel propio: `/performance-dashboard/campaigns*`, `reporting/campaigns-dashboard`.
- Analítica agregada entre marcas: `analytics/aggregation`; ratio de engagement por marca: `brands/engagement-ratio`.
- Exportar a CSV: `api/csv/export/file`.
- Hashtag tracker: `hashtags-tracker/*` (fuera: requiere revisión de Meta).

### Informes, inbox y ads

- Informes a medida («Metrifacts» / Studio): `/metrifacts/create`, `reporting/studio/reports`, accesos directos.
- Inbox: DMs (`inbox/conversations`, fuera por revisión de Meta), reseñas (`inbox/reviews`), notas internas con menciones (`inbox/notes`).
- Ads: Google Ads y TikTok Ads (`/evolution-adwords`, `/evolution-tiktokads`); crear y editar campañas (`advertising/campaigns`, `adgroups`, `keywords`, `recommendations`).

### No aplica (uso personal)

Equipos y roles, agencia y marca blanca, facturación y suscripción, afiliados, backoffice comercial, enlace anónimo para que un cliente conecte sus cuentas (`connections/anonymous-link`).

## Prioridad propuesta

| # | Función | Por qué |
|---|---|---|
| 1 | Primer comentario y textos guardados | Bajo coste, uso diario; C-06 ya está especificado |
| 2 | Vista de cuadrícula de Instagram | Muy visible; usa la media ya programada |
| 3 | Campañas | Agrupar posts y medirlos juntos |
| 4 | Media desde URL o Google Drive | Quita fricción al subir |
| 5 | Red nueva (Bluesky o Threads) | API abierta (Bluesky) o misma app de Meta (Threads) |
