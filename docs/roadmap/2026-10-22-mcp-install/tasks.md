---
verificacion: obligatoria
---

# Checklist de Tareas — Instalación del MCP en Claude Code, Claude Desktop, Codex y OpenCode

| | |
|---|---|
| **Estado** | completado |
| **Fecha** | 2026-10-22 |
| **Spec** | — (vía rápida) |
| **Plan** | vía rápida (sin `improvement-plan.md`): petición del usuario «que la instalación del mcp sirva para cli y app desktop de claude, codex y opencode» |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|---|---|---|---|
| Fase 1 — Instalación guiada del MCP | 3 | 3 | 100% | — | — | — | — |
| **TOTAL** | **3** | **3** | **100%** | — | — | — | — |

---

## Fase 1 — Instalación guiada del MCP

**Estado**: completado · **Estimado**: —

### T-01 — Permisos predefinidos al crear la API key

- [x] T-01
- **Descripción**: En «Nueva API key», tres atajos que marcan los permisos: «Solo lectura» (todas las `*:read`), «Publicar» (lectura + `posts:write` + `reports:write`) y «Todo». Las casillas siguen editables. Los conjuntos salen de `App\Mcp\Abilities::presets()` (fuente única, sin duplicar en el front).
- **Changelog**: Al crear una API key, atajos «Solo lectura», «Publicar» y «Todo» para marcar los permisos de un clic.
- **Estado**: completado
- **Dependencias**: ninguna
- **Tipo**: full-stack
- **Archivos**: `app/Mcp/Abilities.php`, `app/Http/Controllers/ApiKeyController.php`, `resources/js/Pages/ApiKeys/Index.vue`, `tests/Feature/Mcp/*`
- **Criterios de aceptación**:
  - [x] `presets()` devuelve `read`, `publish`, `all`, con abilities válidas y `read` ⊂ `publish` ⊂ `all`.
  - [x] La página recibe los presets por Inertia y al pulsar uno las casillas quedan exactamente con ese conjunto.
- **Verificación**: `php artisan test --filter=McpAuth` → passed

### T-02 — Configuración lista para copiar por cliente

- [x] T-02
- **Descripción**: Tras crear la key, pestañas Claude Code (CLI), Claude Desktop, Codex y OpenCode con el comando o fichero relleno con la URL del endpoint y la key recién creada, botón «Copiar» y la ruta del fichero de cada cliente. Claude Desktop usa el puente `npx mcp-remote <url> --header Authorization:${AUTH_HEADER} --transport http-only` (Desktop no acepta cabeceras en conectores remotos; la forma sin espacios evita el fallo de escape de Windows). Codex y OpenCode leen la key de `SCHEDULER_MCP_KEY` (`bearer_token_env_var` / `{env:…}` con `oauth: false`). Sin key recién creada, la sección muestra las mismas plantillas con `<TU_API_KEY>`.
- **Changelog**: La página de API keys explica cómo conectar Claude Code, Claude Desktop, Codex y OpenCode, con la configuración ya rellena y un botón para copiarla.
- **Estado**: completado
- **Dependencias**: T-01
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/ApiKeys/mcpClients.js`, `tests/e2e/*`
- **Criterios de aceptación**:
  - [x] Las cuatro pestañas generan configuración válida (JSON/TOML parseable) con la URL del endpoint.
  - [x] La key solo aparece en las plantillas inmediatamente después de crearla; nunca se reenvía al servidor ni se guarda en el navegador.
- **Verificación**: `npm run build` → exit 0; E2E de la página de API keys → passed

### T-03 — Botón «Probar conexión»

- [x] T-03
- **Descripción**: Con la key recién creada, el navegador hace `initialize` + `tools/list` contra `/mcp` (mismo origen, permitido por `McpGuard`) y muestra «Conexión correcta: N herramientas» o el error legible (401 key inválida/caducada, 403 origen, 429, red).
- **Changelog**: Botón «Probar conexión» que comprueba la API key recién creada contra el servidor MCP y explica el error si falla.
- **Estado**: completado
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/ApiKeys/mcpClients.js`, `tests/e2e/*`
- **Criterios de aceptación**:
  - [x] Con una key válida muestra el número total de herramientas del servidor (todas las páginas de `tools/list`); los permisos de la key se aplican al usar cada herramienta (`SchedulerTool::handle`, auditado), no al listarlas.
  - [x] Con 401 muestra un mensaje que indica key inválida o caducada.
- **Verificación**: E2E «probar conexión» → passed

**Notas (2026-10-22)**: `Abilities::presets()` es la fuente única de los atajos (read ⊂ publish ⊂ all). Plantillas en `resources/js/Pages/ApiKeys/mcpClients.js`, formatos comprobados en la documentación oficial de cada cliente (Codex `config-reference`: `url` + `bearer_token_env_var`; OpenCode `mcp-servers`: `type: remote`, `headers` con `{env:…}`, `oauth: false`; `mcp-remote` README: `Authorization:${AUTH_HEADER}` sin espacios por el fallo de escape de Windows y `--transport http-only`). Claude Desktop guarda la key en su `claude_desktop_config.json` (no admite cabeceras en conectores remotos; la vía sin copiar claves es OAuth, fuera de alcance). La prueba de conexión hace `initialize` + `tools/list` desde el navegador con `credentials: 'omit'`. Verificación: `php artisan test` → 796 passed; `testConnection` contra el servidor real por ngrok → válida `{ok, tools: 15}` (antes de la revisión: solo 1.ª página; tras A1 → 17), inválida → mensaje 401, sin red → «No se pudo contactar»; las 4 plantillas parsean (JSON.parse / tomllib); E2E `API keys` + `pages` + axe en desktop/375/360 → 9 passed (axe pidió `tabindex` en los bloques de código desplazables).

## Revisión de dos lentes intento 1: Fase 1 (T-01..T-03) — 3 Important corregidos, Minors anotados

Lentes A (conformidad), B (robustez) y C (seguridad, activada por `authorization-header` en `mcpClients.js`). Puerta `scope-check.py --base HEAD`: solo marca ficheros sin seguimiento ajenos al cambio (`.serena/`, `docs/roadmap/2026-10-20-metricool-parity/.claude/usage-state.json`), que no se versionan.

| # | Lente | Grado | Gap | Resolución |
|---|---|---|---|---|
| A1 | A | Important | «Probar conexión» contaba solo la 1.ª página de `tools/list` (15 de 17) y decía «que la key puede usar» aunque el servidor no filtra por permiso | corregido: sigue `nextCursor` (tope 20 páginas) → 17 contra el servidor real; mensaje «el servidor ofrece N herramientas (los permisos de la key se aplican al usar cada una)» y criterio de T-03 reescrito con el motivo |
| A2 | A | Important | La key (y las plantillas) quedaba en `history.state` de Inertia: Atrás la volvía a mostrar | corregido: la vista la toma a un `ref` y borra la prop con `router.replaceProp('newKey', null)` tras la visita (en montaje y en cambio de prop); E2E nuevo: tras navegar y volver, sin `new-key` y con `<TU_API_KEY>` |
| B1 | B | Important | Respuesta 2xx no JSON (p. ej. aviso HTML de ngrok-free) mostraba el error crudo de `JSON.parse` | corregido: cabecera `ngrok-skip-browser-warning` y mensaje legible «El servidor no devolvió una respuesta MCP…» |
| A3 | A | Minor | Verificación de T-01 con `--filter=ApiKey` no ejecutaba tests | corregido: `--filter=McpAuth` |
| A4 | A | Minor | Sin evidencia de `npm run build` | corregido: evidencia abajo |
| A5 | A | Minor | Parseo de plantillas y 401 sin test automatizado | corregido en parte: el E2E hace `JSON.parse` de Desktop y OpenCode; TOML y 401 verificados con script node (sin runner JS unitario en el proyecto) |
| B2 | B | Minor | `body.error` inalcanzable: los 4xx/5xx perdían el motivo | corregido: con `!ok` se usa `error.message` del cuerpo si no hay mensaje propio para el estado |
| B3 | B | Minor | Falso «0 herramientas» (cuerpo vacío, sin `tools`, SSE con notificación al final o `data:` multilínea) | corregido: SSE por eventos, elige el del `id` pedido, une líneas `data:`; sin `tools` array → error |
| B4 | B | Minor | Cuerpo `null` clasificado como fallo de red | corregido: solo el `fetch` que lanza es «No se pudo contactar»; el resto, mensajes propios |
| B5 | B | Minor | «Copiar» fallaba en silencio sin Clipboard API | corregido: aviso «selecciona el texto y pulsa Ctrl+C» |
| B6 | B | Minor | El E2E depende de que `E2E_BASE_URL` sea APP_URL o esté en `MCP_ALLOWED_ORIGINS` | anotado: comentario en el E2E; el servidor de pruebas se arranca con `APP_URL=http://127.0.0.1:8080` |
| C1 | C | Minor | `setx`/`export`/`claude mcp add --header` dejan la key en el historial de la shell (CWE-532/312) | anotado: es el mecanismo que piden los propios clientes; se revoca desde esta página |

**Evidencia (tras corregir)**: `testConnection` contra el servidor real por ngrok → `{ok: true, tools: 17}`, key inválida → mensaje 401; simulación con fetch falso (paginado 17, HTML 200, 400 con motivo, `null`, 202 vacío, sin `tools`, SSE multilínea con notificación, red caída, 401) → mensajes esperados; `npm run build` → exit 0; E2E `API keys` + `pages` + axe (desktop/375/360) → 9 passed.

## Revisión de dos lentes intento 2: Fase 1 (T-01..T-03) — gaps del intento 1 cerrados, 2 Minor nuevos corregidos

Lentes A+B re-evalúan solo lo corregido: A1, A2, A3, A4, A5, B1, B2, B3, B4, B5 → ✓ cerrados (A1 contrastado con `CursorPaginator` del vendor; A2 con `replaceProp`/`handleBackForward` de `@inertiajs/core` 2.3.29).

| # | Lente | Grado | Gap | Resolución |
|---|---|---|---|---|
| N1 | B | Minor | Al revocar la key recién creada seguía mostrándose (aviso, plantillas y «Probar conexión»), porque `router.delete` conserva el estado de la vista | corregido: al revocar con éxito la key cuyo texto plano empieza por `<id>|`, se retira de la vista; crear otra key reinicia también el resultado de la prueba |
| N2 | A | Minor | Las Notas citaban `tools: 15`, en contradicción con el criterio reescrito (17) | corregido: nota aclarada |

**Evidencia (intento 2)**: `npm run build` → exit 0; E2E `API keys` + `pages` + axe (desktop/375/360) → 9 passed; `qa-gate.py testing/results.json` → VERDE (9 passed, 0 failed, 0 flaky); `php artisan test` → 796 passed. Sin gaps Critical/Important abiertos: el bucle cierra en el intento 2.
