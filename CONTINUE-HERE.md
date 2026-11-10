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
