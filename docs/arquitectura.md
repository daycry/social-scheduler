# Arquitectura

Monolito Laravel 13 servido por el PHP-FPM de HestiaCP. Una base MariaDB/MySQL guarda datos y cola. Workers `queue:work` bajo systemd y un cron `schedule:run` cada minuto hacen el trabajo asíncrono. La decisión está en [ADR-001](knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md) (opción O1 de [design.md](roadmap/2026-10-19-social-scheduler/design.md)).

## Vista general

```
Navegador (Inertia/Vue) ──► Nginx/PHP-FPM ──► Controladores ──► Servicios ──► Modelos (UserScope)
Cliente MCP (Bearer msk_) ─► /mcp ───────────► Herramientas MCP ─┘                │
                                                                                   ▼
cron (cada minuto) ─► schedule:run ─► DispatchDuePosts ─► cola `publish` ─► PublishPostTarget ─► Conector de la red
                                   └► RefreshTokens / CollectSnapshots / reports:prune        └► PollPublishStatus (cola `poll`)
Navegador ──PUT prefirmado──► Cloudflare R2 (media)       Workers systemd: publish, poll, metrics, reports
```

## Capas

| Capa | Ruta | Responsabilidad |
|---|---|---|
| Controladores / Inertia | `app/Http/Controllers/`, `resources/js/Pages/` | Web: marcas, conexiones, compositor, calendario, dashboards, informes, API keys |
| Servicios | `app/Services/` | Lógica única compartida por web y MCP (`PostService`, `CalendarService`, `AnalyticsService`, `ReportService`…) |
| Conectores | `app/Connectors/{Meta,Google,TikTok}/` | OAuth, publicación, sondeo e insights por red, tras los contratos de `app/Connectors/Contracts/` |
| Jobs | `app/Jobs/` | Publicar, sondear, refrescar tokens, snapshots, informes |
| MCP | `app/Mcp/`, `routes/ai.php` | 14 herramientas sobre los mismos servicios |
| Modelos | `app/Models/` | `Brand`, `SocialAccount`, `Post`, `PostTarget`, `MediaAsset`, `PublishAttempt`, `AccountSnapshot`, `PostMetricSnapshot`, `Report`, `AuditLog` |

## Aislamiento por usuario

Cada modelo del dominio se filtra con el global scope `app/Scopes/UserScope.php`; las Policies (`app/Policies/`) autorizan cada acción. Un recurso ajeno devuelve 404. Los jobs y el scheduler, que no tienen usuario autenticado, usan `withoutUserScope()` de forma explícita (ejemplo: `DispatchDuePosts`). Los accesos entre usuarios se registran con `app/Support/CrossUserAccessLogger.php`. Hay pruebas en `tests/Feature/Isolation/`.

## Redes y tipos de contenido

`App\Connectors\Network` define Instagram, Facebook, YouTube y TikTok. `App\Enums\ContentType` define los 13 tipos (`instagram_image`, `instagram_carousel`, `instagram_reel`, `instagram_story`, `facebook_text`, `facebook_photo`, `facebook_video`, `facebook_reel`, `youtube_video`, `youtube_short`, `tiktok_video`, `tiktok_photo`). `tiktok_photo` solo se ofrece con `TIKTOK_VERIFIED_DOMAIN` definido (`ContentType::isOffered()`).

## Estados de una publicación

El estado vive por destino (`PostTarget`): una publicación puede ir a varias redes.

```
borrador ─► programada ─► publicando ─► procesando ─► publicada
                │  ▲           │   │          └──────► fallida
                │  └───────────┘   └────────────────► fallida   (fallida ─► programada | borrador)
```

Las transiciones válidas están en `App\Enums\PostStatus::allowedNext()`. `publicada` es final. `publicando → programada` es el reintento diferido (error transitorio o cuota).

## Tareas programadas (`routes/console.php`)

| Tarea | Frecuencia | Qué hace |
|---|---|---|
| `health-heartbeat` | cada minuto | Latido que lee `app:health` |
| `dispatch-due-posts` | cada minuto | Recupera colgadas y encola las vencidas |
| `refresh-social-tokens` | cada 30 min | Renueva tokens antes de caducar |
| `media-prune` | cada hora | Borra subidas abandonadas |
| `collect-snapshots` | diaria, 12:00 UTC (`SOCIAL_ANALYTICS_DAILY_AT`) | Snapshot por cuenta |
| `refresh-post-metrics` | `15 */6 * * *` | Métricas de publicaciones recientes |
| `reports:prune` | diaria 04:10 | Purga informes pasada la retención |
| `queue:prune-failed` | diaria | Elimina jobs fallidos de más de 720 h |

## Colas

Cinco colas en `config/queue.php` (`QUEUE_NAMES`): `publish`, `poll`, `metrics`, `reports` y `media` (importación de media desde URL o Drive, con su propio worker `ss-media`). Plazos encadenados: `$timeout` del job (110 s) < `--timeout` del worker (120 s) < `retry_after` (180 s, `DB_QUEUE_RETRY_AFTER`). Ver [publicación](modulos/publicacion.md).
