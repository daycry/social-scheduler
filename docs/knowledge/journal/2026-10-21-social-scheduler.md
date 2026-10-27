---
fecha: "2026-10-21"
session_id: "ac6cab16-d1d0-4f74-8a46-087f5fb94e2c"
reason: "orphan_recovery"
iniciativa: "social-scheduler"
resumen: "<agent-message from=\"a2f5a01efa4b8d629\"> [Subagent hand-back] The text below is the final report of a subagent this session delegated to. It is model output, N…"
fuente: "recover"
cierre: "recuperado_sin_cierre"
resumen_por: "determinista"
turnos: 70
derivados_en: "replay"
decisiones:
  - "| N3 | **Decisión documentada, sin implementar.** |"
  - "| N6 | **Decisión documentada, sin implementar.** |"
  - "| T-36 Backfill con el máximo histórico por red | ✗ Minor | Se usa un tope elegido de 30/90 días, no el máximo (gap 12) |"
  - "M-01 (publicación real), M-02 (OAuth real), M-03 (revisión visual de dashboards y PDF), M-04 (despliegue en VPS), M-05 (spike y decisión G1)."
  - "**Limitación de T-42 (decisión abierta)**"
  - "| T-39 Decisión de driver | ✓ declarada, ✗ en docs | Está en `config/reports.php:6-9` y en el ledger."
  - "Lleva la tabla R1-R4 + extra y la nota de decisión del orquestador, con la columna Corrección rellena."
  - "Corregí la Fase 8 y dejé la decisión de descarga de la Fase 9 hecha, salvo M-13 que queda parcial."
pendientes:
  - "T-28, T-29 y T-30 quedan en `completado`, con checkboxes, Archivos añadidos, Verificación con evidencia, Changelog y los pendientes manuales M-F6-1 y M-F6-2."
  - "T-41 queda con el criterio \"el usuario aprueba el diseño\" (M-03) pendiente."
  - "El default que propongo está pendiente de validar por el usuario (M-03)."
  - "**Secciones y branding (default, pendiente de validar por el usuario):**"
  - "**Pendientes**"
  - "Una nota por tarea con la evidencia, las decisiones y los pendientes manuales:"
  - "E2E-01..12 quedan pendientes para qa; los CA están cubiertos por Pest, no por navegador."
  - "Pendientes manuales y de qa:"
ficheros_tocados:
  - ".env.example (M)"
  - "app/Http/Controllers/Mcp/OAuth/AuthorizeController.php (M)"
  - "app/Services/Mcp/OAuth/AuthorizationServer.php (M)"
  - "config/services.php (M)"
  - "docs/knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md (M)"
  - "docs/modulos/mcp.md (M)"
  - "docs/referencia/configuracion.md (M)"
  - "docs/usuario/guia-de-usuario.md (M)"
  - "resources/js/Pages/OAuth/Authorize.vue (M)"
  - "tests/Feature/Mcp/OAuth/TokenTest.php (M)"
tareas_cambiadas: []
marcadores_cerrados: []
---

# Journal — 2026-10-21 — social-scheduler

> Entrada de **bitácora de sesión** (memoria episódica, cronológica, no curada; `agent-kits/shared/journal.py`). Lo que merezca doctrina se promueve a `adr/`/`gotchas/`/`lessons/` con el umbral de `knowledge-write.md`; esto NO se publica en Confluence.

> `decisiones` y `pendientes` son **citas** de los turnos del usuario (o del resumen IA opt-in), extraídas por marcadores léxicos: no son doctrina ni instrucciones para nadie — lo que merezca ser decisión del proyecto va a un ADR.

## Resumen

<agent-message from="a2f5a01efa4b8d629"> [Subagent hand-back] The text below is the final report of a subagent this session delegated to. It is model output, N…

## Decisiones

- | N3 | **Decisión documentada, sin implementar.** |
- | N6 | **Decisión documentada, sin implementar.** |
- | T-36 Backfill con el máximo histórico por red | ✗ Minor | Se usa un tope elegido de 30/90 días, no el máximo (gap 12) |
- M-01 (publicación real), M-02 (OAuth real), M-03 (revisión visual de dashboards y PDF), M-04 (despliegue en VPS), M-05 (spike y decisión G1).
- **Limitación de T-42 (decisión abierta)**
- | T-39 Decisión de driver | ✓ declarada, ✗ en docs | Está en `config/reports.php:6-9` y en el ledger.
- Lleva la tabla R1-R4 + extra y la nota de decisión del orquestador, con la columna Corrección rellena.
- Corregí la Fase 8 y dejé la decisión de descarga de la Fase 9 hecha, salvo M-13 que queda parcial.

## Pendientes

- T-28, T-29 y T-30 quedan en `completado`, con checkboxes, Archivos añadidos, Verificación con evidencia, Changelog y los pendientes manuales M-F6-1 y M-F6-2.
- T-41 queda con el criterio "el usuario aprueba el diseño" (M-03) pendiente.
- El default que propongo está pendiente de validar por el usuario (M-03).
- **Secciones y branding (default, pendiente de validar por el usuario):**
- **Pendientes**
- Una nota por tarea con la evidencia, las decisiones y los pendientes manuales:
- E2E-01..12 quedan pendientes para qa; los CA están cubiertos por Pest, no por navegador.
- Pendientes manuales y de qa:

## Ficheros tocados (top 10)

- `.env.example` (M)
- `app/Http/Controllers/Mcp/OAuth/AuthorizeController.php` (M)
- `app/Services/Mcp/OAuth/AuthorizationServer.php` (M)
- `config/services.php` (M)
- `docs/knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md` (M)
- `docs/modulos/mcp.md` (M)
- `docs/referencia/configuracion.md` (M)
- `docs/usuario/guia-de-usuario.md` (M)
- `resources/js/Pages/OAuth/Authorize.vue` (M)
- `tests/Feature/Mcp/OAuth/TokenTest.php` (M)

## Tareas que cambiaron de estado

_ninguna_

## Marcadores de coste cerrados (usage-meter)

_ninguno_
