# Continuación del roadmap

Actualizado: 2026-11-01. Fuente canónica del estado de cada tarea: `docs/roadmap/*/tasks.md`.

## Decisiones vigentes del usuario

- Continuar el roadmap con el flujo de custom-agents y sus puertas de verificación.
- **Claude Desktop queda aplazado** («lo dejamos de momento»). No retomar su formulario, pedir otro consentimiento ni marcar M-04 como aprobado. Este aplazamiento no pausa el resto del trabajo.
- Las pruebas del **VPS** las ejecuta el usuario («eso ya lo probaré yo»). No volver a pedir SSH para T-09/T-10.
- Los smokes reales de **Bluesky y Threads** siguen a cargo del usuario; todavía no ha comunicado resultados.
- El diseño y las secciones del informe PDF están aprobados; T-41 del roadmap principal ya está completada. No confundirla con T-41 de post-composer.
- Las autorizaciones de Codex, Claude Code, OpenCode y el acceso ChatGPT de OpenCode ya están dadas. No volver a solicitarlas al reanudar las mismas comprobaciones.

## Estado comprobado de los ledgers

### Compositor y audio: T-11/T-12 verificadas localmente

En `feature/metricool-composer-parity`, audio por red y buscador T-01–10 conservan su verificación anterior. La ampliación autorizada incorpora paneles cuidados de ajustes globales y por cuenta, colaboradores e IA de Instagram, título de Facebook y errores plegables. [Spec](docs/roadmap/2026-10-31-metricool-composer-parity/spec.md), [ledger](docs/roadmap/2026-10-31-metricool-composer-parity/tasks.md), [guía e inventario observado](docs/guias/ajustes-compositor.md) y [QA actual](docs/roadmap/2026-10-31-metricool-composer-parity/testing/presets-ui/report.md).

**11/12 completadas; solo T-06 real permanece abierta.** Suite actual PHP: **2.511 tests/14.763 aserciones**, cero fallos/errores/skips. Build y tipado correctos. Regresión E2E de 54 casos correcta, qa-gate VERDE y revisión independiente A+B+D, intento 2, sin gaps pendientes. T-06/CA-10 (publicación real) sigue a cargo del usuario. Los informes previos de audio y buscador son históricos y no sustituyen el nuevo cierre. El usuario ha autorizado expresamente **commits, push y PR al terminar**, sin nueva pregunta de permiso; aún pendientes. PR #16 de la rama anterior sigue abierta: la nueva PR se apilará sobre `feature/metricool-parity-3`.

No se declara paridad total con Metricool. Acortador, modo manual y automatización de SmartLinks siguen identificados en el inventario como pendientes. El backlog amplio incluye X. Threads tiene código, pero faltan credenciales locales; no se cambia `.env`. El usuario dejó la comparación de catálogos musicales como estaba y probará servidor y publicaciones reales.

Las cifras siguientes corresponden a los ocho ledgers previos; no incluyen ni dan por completada esta ampliación.

Corrección del buscador (2026-11-01, T-10): Meta devuelve cursores `after` sin `paging.next`; antes se perdían. Web/MCP ya continúan por páginas, con resultados acumulados/deduplicados y cursores ligados a cuenta, consulta y filtro. Escribir actualiza la búsqueda; cambios/cierre invalidan respuestas atrasadas. Comprobación real: 25+25=50 pistas distintas. La [investigación inicial de Aipocalipsis / Libre](docs/roadmap/2026-10-31-metricool-composer-parity/testing/catalogo-aipocalipsis-libre.md) conserva solo los hechos de esas consultas; no demuestra ausencia general de la canción ni descarta defectos del buscador. Sin publicaciones reales.

**264/280 tareas completadas; 16 abiertas.** La revisión de las tareas abiertas no encuentra otra implementación pendiente independiente de sus verificaciones reales o de sus cierres dependientes. No se cierran por tener pruebas simuladas en verde.

| Iniciativa | Completadas | Abiertas |
|---|---:|---|
| [social-scheduler](docs/roadmap/2026-10-19-social-scheduler/tasks.md) | 33/43 | T-09, T-10, T-12, T-21, T-22, T-23, T-26, T-27, T-37, T-43 |
| [metricool-parity](docs/roadmap/2026-10-20-metricool-parity/tasks.md) | 15/15 | — |
| [account-2fa-passkeys](docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md) | 20/20 | — |
| [mcp-install](docs/roadmap/2026-10-22-mcp-install/tasks.md) | 3/3 | — |
| [social-login-mcp-oauth](docs/roadmap/2026-10-22-social-login-mcp-oauth/tasks.md) | 28/29 | T-11: Desktop aplazado; tres CLI verificados |
| [post-composer](docs/roadmap/2026-10-23-post-composer/tasks.md) | 41/43 | T-41 smoke real; T-42 cierre dependiente |
| [metricool-parity-2](docs/roadmap/2026-10-24-metricool-parity-2/tasks.md) | 50/50 | —; conserva su checklist manual de usuario |
| [metricool-parity-3](docs/roadmap/2026-10-26-metricool-parity-3/tasks.md) | 74/77 | T-30 Bluesky, T-64 Threads y T-77 cierre dependiente |

## Próxima evidencia necesaria

| Bloque | Tareas abiertas | Qué permite avanzar |
|---|---|---|
| Operación real del VPS y R2 | Principal T-09, T-10 | Resultados del usuario sobre cron, workers, reinicio y almacenamiento real |
| OAuth de redes y publicación real | Principal T-12, T-21, T-22, T-23, T-26, T-27 | Conexión/renovación y enlaces o IDs de las publicaciones exigidas, con la visibilidad aprobada |
| Analítica real | Principal T-37 | Comparación con datos reales del periodo previsto; no sustituir por el PDF ficticio aprobado |
| Compositor | Post-composer T-41 | Una publicación real por red, Publicar ahora y ausencia de duplicados según el [registro preparado](docs/roadmap/2026-10-23-post-composer/testing/smoke-real.md) |
| Bluesky y Threads | P3 T-30, T-64 | Resultados del usuario y enlaces/IDs según sus M-01/M-02 |
| Cliente MCP de escritorio | OAuth T-11 | Solo cuando el usuario retome Desktop: consentimiento, callback, tool y conclusión final; limpieza de tokens al terminar el smoke completo |
| Cierres dependientes | Principal T-43; post-composer T-42; P3 T-77 | Evidencias anteriores, revisión del ledger y actualización de spec/plan cuando sus condiciones se cumplan |

El detalle operativo y los restantes manuales de tareas ya completadas están en [pendientes-manuales.md](docs/referencia/pendientes-manuales.md). Si el usuario aporta un resultado, contrastarlo con su criterio concreto, registrar evidencia saneada y cerrar solo lo que quede acreditado. Si aparece un defecto reproducible, corregirlo con las puertas del flujo custom-agents. No crear nuevas funciones para sustituir pruebas pendientes.

## Verificaciones existentes: preservar, no repetir sin motivo

Se han vuelto a leer los archivos de evidencia el 2026-10-31; las siguientes cifras corresponden a esas ejecuciones, no a una nueva ejecución de suites.

- PHP: **2461 tests, 14549 aserciones**, cero errores/fallos/omitidos. [JUnit](docs/roadmap/2026-10-19-social-scheduler/testing/raw/phpunit-final-p3.xml).
- T-43 aislado: **57 pasados**, cero fallos/flaky/omitidos. [Informe](docs/roadmap/2026-10-19-social-scheduler/testing/report.md). JSON SHA-256: `ac554df539a373dbca70f3bc5065da457a7d7ac20cb26c740c76545283b95e6a`.
- Playwright general: **502 pasados y dos omitidos previstos de 504**, cero fallos/flaky/errores del runner. [Informe definitivo](docs/roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.md). JSON SHA-256: `b7db16de01b7bdf7cd240ce03f0e6d9159d72471090b22ba4595bac88776ce7e`.
- MCP: **Codex 0.162.1, Claude Code 2.1.314 y OpenCode 2.0.18** completan OAuth y una llamada `list_brands` cada uno, sin API key MCP. OpenCode usa el servicio local y ChatGPT (`openai/gpt-6.1-sol`). [Registro y evidencia](docs/roadmap/2026-10-22-social-login-mcp-oauth/testing/smoke-ca29.md).
- Ninguna de estas pruebas acredita publicaciones reales ni el despliegue de los cambios locales en producción. El usuario realizará el despliegue y las pruebas del servidor; publicar los commits y la PR no acredita ese despliegue.

## Reanudación técnica

- Trabajar en la rama existente y conservar los cambios de otros chats. `docs/knowledge/journal/README.md` estaba modificado antes de esta continuación; no sobrescribirlo.
- Los helpers y checkpoints temporales están bajo `storage/framework/testing/roadmap-continuation/`, ignorado por Git. La vista local generada del roadmap está en `dashboard.html` dentro de esa carpeta; es regenerable con la skill `custom-agents:roadmap-dashboard`.
- Ngrok se autorizó expresamente hacia `https://metricool.test` con dominio `https://workable-humorous-rat.ngrok-free.app`, inspector desactivado y TLS verificado. Antes de usarlo otra vez, comprobar su estado; no asumir que sigue activo ni volver a iniciar procesos duplicados. Los datos del proceso propio están en `mcp-runtime/tunnel-owned.json`.
- No hay una sesión CLI OAuth de prueba pendiente de observación: los tres flujos terminaron. No reabrirlos por haber perdido contexto.
- La instrumentación temporal de diagnóstico de OpenCode fue retirada; `bootstrap/app.php` recuperó exactamente su hash anterior. No se demostró la causa del fallo POST transitorio ni la compatibilidad del modo standalone; el éxito acreditado usa el servicio local.
