# Punto de reanudación — 2026-11-27

- Proyecto: `C:\laragon\www\metricool`, rama existente `feature/audit-hardening`.
- Ledger actual: `docs/roadmap/2026-11-11-audit-hardening/tasks.md` (manda sobre los resúmenes).
- **92/108 cerradas (T-93 incluida). T-81 cerrada e integrada el 2026-11-20** (rojo R60 resuelto, nits R2 aplicados, R3 aprobado sin gaps; evidencias en `testing/t81/`). Migraciones pendientes aplicadas en la MySQL de desarrollo con copia `storage/backups/pre-migrate-20261120-133549.sql`.
- Próximo: T-94 (Estudio: animaciones por objeto) y el resto. En el VPS hay que activar el worker `exports` de T-84. Las migraciones de T-88 a T-92 (`2026_11_27_090000_add_rss_source_to_autolists`, `2026_11_27_100000_create_short_links_tables`, `2026_11_27_110000_add_calendar_feed_to_users`, `2026_11_27_120000_add_watermark_to_studio_brand_kits`, `2026_11_27_130000_add_recycling_to_posts_table`) están pendientes en la MySQL de desarrollo: copia de seguridad antes de `migrate`.
- No usar bucles `until grep …; do sleep` para esperar a Pest: ejecutarlo en segundo plano y esperar el aviso (dos bucles quedaron colgados 17 h el 2026-11-26).
- La copia aislada no tiene `.env`: para Pest en `.cache/t8x-physical` hace falta `APP_KEY` efímera por proceso y `storage/framework/{views,cache/data,sessions}`.
- Pest completo en local: `php artisan config:clear; php -d memory_limit=1G vendor/bin/pest --compact` (con `artisan test` el proceso hijo hereda el 512M del php.ini y se agota hacia el test 4500). Pasarlo antes de cerrar cada tarea: el CI de T-84 cayó por dos tests estáticos que el Pest focal no cubre.
- Una sola ejecución Playwright a la vez; nunca modificar su copia física mientras ejecuta. Sin Docker/proveedores reales, `.env` privado, producción ni usuario 28. Commit y push a `feature/audit-hardening` al cerrar cada tarea (pedido por el usuario el 2026-11-20); merges del usuario. Conservar cambios existentes del workspace.

---

## Resumen histórico anterior (2026-11-10)

# Continuar la suite social y creativa

Actualizado: 2026-11-10. El [ledger canónico](docs/roadmap/2026-11-03-creative-social-suite/tasks.md) manda sobre este resumen.

## Estado

- **23/23 tareas implementadas** en la rama `feature/creative-social-suite`, con PR abierta contra `main`. La PR no se mergea sin el usuario.
- Verificación final sobre el código congelado (2026-11-10):
  - Pest: 3572 passed, 9 skipped.
  - E2E: 772 passed, 2 skipped, 0 failed en desktop, 375 y 360, con axe.
  - `qa-gate` VERDE.
  - Evidencias en `docs/roadmap/2026-11-03-creative-social-suite/testing/t22/`.
- Auditoría global con Claude Code CLI de la aplicación y la documentación: hallazgos corregidos y verificados (secciones de T-22 y T-23 del ledger).
- Todo lo que depende de proveedores reales se ha probado **solo con simulaciones**.

## Pendiente del usuario

1. **Smokes reales**: la lista CS-M-01..08 está en [pendientes manuales](docs/referencia/pendientes-manuales.md).
   - LinkedIn y Pinterest: apps, scopes y tablero.
   - Opciones de Instagram: ubicación y etiquetas.
   - IA del estudio: claves de OpenAI y Google.
   - Canva: registrar la app con redirect `/canva/callback` y return `/canva/return`, y probar con una cuenta Free y otra Pro.
   - Inbox de Threads, YouTube y Bluesky.
2. **Despliegue**: seis colas, con el worker `studio` (cgroupv2) y el worker `media`. Además Chromium/Playwright, Python con el modelo de fondos y las variables nuevas. Ver [despliegue](docs/modulos/despliegue.md) y [configuración](docs/referencia/configuracion.md).
3. **Decisiones abiertas**: X, Google Business Profile y Twitch (ver `reference-inventory.md`).
4. **Revisar y mergear la PR**. También siguen abiertas las PRs anteriores #15, #16, #18 y #19.

## Límites conocidos (documentados)

- **Canva**: no se verifica la firma JWKS del JWT de retorno; el PDF se entrega solo como enlace temporal de 24 h; Brand Templates y Autofill están desactivados con su motivo.
- **IA del estudio**: modelos por defecto `gpt-image-2.5-sunburst` (OpenAI cerró `gpt-image-1` el 2026-10-23) y `gemini-nano-banana-2.1`. Un timeout ambiguo no se reintenta.
- **Series**: las fechas que pasan mientras una serie falla no se recuperan; el error queda visible y se notifica.
