# `docs/knowledge/` — memoria técnica del proyecto

Índice de entrada. Un fichero por entrada: `adr/` (decisiones, `ADR-NNN-<slug>.md`), `gotchas/` (trampas, `GOT-NNN-<slug>.md`) y `lessons/` (lecciones, `LES-NNN-<agente>-<slug>.md`). Quien añade una entrada actualiza esta tabla en el mismo cambio.

## Índice

| Entrada | ID | Tipo | Área | Estado | Fuente |
|---|---|---|---|---|---|
| [`adr/ADR-001-stack-laravel-nativo-hestia.md`](adr/ADR-001-stack-laravel-nativo-hestia.md) — "Stack Laravel 13 monolito nativo en HestiaCP con cola en MariaDB/MySQL." | ADR-001 | Decisión | Arquitectura / stack y despliegue | propuesta | `2026-10-19-social-scheduler/design.md` |
| [`adr/ADR-002-pdf-dompdf.md`](adr/ADR-002-pdf-dompdf.md) — "PDF de informes con DomPDF (PHP puro) en lugar de Chromium/Browsershot." | ADR-002 | Decisión | Informes / exportación PDF | propuesta | `2026-10-19-social-scheduler/design.md` |
| [`adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md`](adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md) — "2FA con TOTP propio sobre Breeze y passkeys con laravel/passkeys aislado tras un gateway." | ADR-003 | Decisión | Seguridad de cuenta / autenticación | aceptada | `2026-10-22-account-2fa-passkeys/design.md` |
| [`adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md`](adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md) — "Servidor de autorización OAuth 2.1 del MCP propio sobre Sanctum y login social con Socialite." | ADR-004 | Decisión | Seguridad de cuenta / autorización MCP | propuesta | `2026-10-22-social-login-mcp-oauth/design.md` |
| [`approved/gotchas/GOT-001-meta-permisos-por-caso-de-uso.md`](approved/gotchas/GOT-001-meta-permisos-por-caso-de-uso.md) — "Meta: los permisos se añaden dentro del caso de uso de la app." | metricool.GOT-001 | Gotcha | OAuth / Meta | aprobado | `spike-report.md` |
| [`approved/gotchas/GOT-002-meta-paginas-portfolio-me-businesses.md`](approved/gotchas/GOT-002-meta-paginas-portfolio-me-businesses.md) — "Meta: las Páginas de portfolios empresariales no salen en me/accounts." | metricool.GOT-002 | Gotcha | OAuth / Meta | aprobado | `spike-report.md`, `MetaConnector.php` |
| [`approved/gotchas/GOT-003-tiktok-creator-info-cuerpo-objeto-vacio.md`](approved/gotchas/GOT-003-tiktok-creator-info-cuerpo-objeto-vacio.md) — "TikTok: creator_info/query exige un objeto JSON vacío {}." | metricool.GOT-003 | Gotcha | API / TikTok | aprobado | `spike-report.md`, `TikTokPublisher.php` |
| [`approved/gotchas/GOT-004-meta-impressions-retiradas-usar-views.md`](approved/gotchas/GOT-004-meta-impressions-retiradas-usar-views.md) — "Meta: impressions y page_fans retiradas; usar views y page_media_view." | metricool.GOT-004 | Gotcha | Analítica / Meta | aprobado | `spike-report.md`, `Insights.php` |
