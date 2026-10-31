# Registro del smoke CA-29 — clientes MCP con OAuth

| Campo | Estado |
|---|---|
| Fecha de preparación | 2026-10-31 |
| Tarea | [T-11 del ledger](../tasks.md#t-11--smoke-ca-29-con-claude-code-claude-desktop-codex-y-opencode-adelantado-al-final-de-c-02) |
| Escenarios | [M-03 a M-06 del plan de pruebas](../test-plan.md#m-03--claude-code-cli-sin-api-key-ca-29) |
| Resultado global | **PARCIAL: Codex, Claude Code y OpenCode verificados (3/4); Claude Desktop pendiente** |
| Alcance ejecutado | OAuth real de tres CLI; cada uno completa una llamada real a `list_brands`; OpenCode utiliza el acceso de ChatGPT autorizado por el usuario |

**Decisión vigente del usuario (2026-10-31): Claude Desktop se deja «de momento» y se continúa con lo demás.** M-04 queda aplazado, sin aprobar ni cerrar T-11. No se inicia otro consentimiento de Desktop mientras siga este aplazamiento; las autorizaciones y resultados previos de los tres CLI se conservan.

El usuario autorizó recuperar ngrok y los flujos OAuth de los clientes, y completó los consentimientos de Codex, Claude Code y OpenCode. Los tres ejecutan `list_brands` una vez cada uno sin API key MCP. OpenCode 2.0.18 usa `openai/gpt-6.1-sol` mediante el acceso de ChatGPT que el usuario acaba de autorizar, con override solo para esa invocación. No se publicaron contenidos ni se cambiaron marcas o cuentas. Claude Desktop sigue pendiente.

## URL MCP verificada

El túnel autorizado está activo hacia `https://metricool.test`, con verificación TLS del upstream e inspector desactivado. Los metadatos públicos de recurso y de autorización devuelven 200 y coinciden con el origen ngrok. [Evidencia pública](ca29-evidence/public-metadata.json), comprobada el 2026-10-31 a las 18:08:56 UTC. Antes de arrancarlo, la misma URL devolvía 404; ese diagnóstico se conserva más abajo. No se modifica el VPS de `social-scheduler.daycry.ovh`.

La URL utilizada en el smoke es la siguiente. No copiar una API key ni cabeceras Bearer: CA-29 prueba la autenticación mediante el navegador.

```powershell
$McpUrl = 'https://workable-humorous-rat.ngrok-free.app/mcp'
```

## Matriz de clientes

| Cliente / escenario | Versión observada | Comando o configuración preparada | Resultado comprobado | Callback observado |
|---|---|---|---|---|
| Claude Code CLI — M-03 | **2.1.314** | `claude mcp add --scope local --transport http roadmap_ca29_claude <URL>`, `claude mcp login roadmap_ca29_claude` y sesión `claude -p` restringida | **VERIFICADO REAL**: estado `Connected`, token emitido y `list_brands` completada una vez; sesión exit 0 | `http://localhost:46319/callback`, sin query ni fragmento |
| Claude Desktop — M-04 | **4.4212.0.0**, paquete instalado y ventana activa; «Acerca de» pendiente | Personalización → Conectores → Tuyos → Añadir conector personalizado; formulario `Social Scheduler - CA29 local` con URL ngrok | **GUI Y FORMULARIO VERIFICADOS**: detecta OAuth y DCR; «Añadir» del agente rechazado por revisión automática, usuario completa el paso manual. Aviso de cuota hasta «2 nov a las 7:00» | **PENDIENTE** del consentimiento real del nuevo conector |
| Codex CLI — M-05 | **0.162.1** | Override temporal `mcp_servers.roadmap_ca29_codex.url`, `mcp login roadmap_ca29_codex` y `codex exec` de solo lectura | **VERIFICADO REAL**: login exit 0, estado `o_auth` y una llamada `list_brands` completada; ejecución exit 0 | `http://127.0.0.1:54748/callback/qX3zqfvzQ-Pf`, sin query ni fragmento |
| OpenCode — M-06 | **2.0.18** | Configuración de proyecto `mcp.servers.roadmap_ca29_opencode`, `opencode mcp auth roadmap_ca29_opencode` y `opencode run --model openai/gpt-6.1-sol --agent ca29_readonly --format json …` mediante el servicio local | **VERIFICADO REAL**: login exit 0, estado `connected` y `list_brands` completada una vez; ejecución exit 0 | `http://127.0.0.1:60041/callback`, sin query ni fragmento |

Codex sí se ha ejecutado con el override temporal descrito; el ejemplo histórico `mcp add` no se usó para preservar la lista global de servidores. Claude Code usa una entrada de alcance `local`, privada para este proyecto: su CLI la guarda en `~/.claude.json` bajo la raíz Git `C:/laragon/www/metricool`, aunque se invoque desde el subdirectorio desechable. No se cambia la lista global de servidores. OpenCode 2 usa `mcp.servers.roadmap_ca29_opencode` en otro proyecto desechable, sin `--global`. Ambos flujos están autorizados por el usuario. Desktop sigue pendiente.

## Evidencia local ejecutada

| Prueba de solo lectura | Resultado |
|---|---|
| `claude --version` | Exit 0; `2.1.314 (Claude Code)` |
| `claude mcp --help` | Exit 0; enumera `add`, `login` y los demás comandos de gestión |
| `claude mcp add --help` | Exit 0; admite `--transport http`, `<name> <commandOrUrl>` y `--callback-port <port>` |
| `claude mcp login --help` | Exit 0; admite `<name>` y `--no-browser`; este último imprime la URL en lugar de abrir el navegador |
| `codex --version` | Exit 0; `codex-cli 0.162.1` |
| `codex mcp --help` | Exit 0; enumera `add`, `login`, `get`, `list`, `remove` y `logout` |
| `codex mcp add --help` | Exit 0; admite `<NAME> --url <URL>`; expone `--oauth-client-registration <AUTO\|CIMD\|DCR>` y `--oauth-resource <RESOURCE>` |
| `codex mcp login --help` | Exit 0; admite `<NAME>`, `--no-browser` y `--oauth-client-registration <AUTO\|CIMD\|DCR>` |
| `opencode --version` | Exit 0 con acceso al host; `opencode v2.0.18` |
| `opencode mcp --help` | Exit 0 con acceso al host; enumera `list`, `add`, `auth` y `logout` |
| `opencode mcp add --help` | Exit 0 con acceso al host; admite `<name>`, `--url`, `--global` y otros flags |
| `opencode mcp auth --help` | Exit 0 con acceso al host; admite `<name>` |
| `Get-AppxPackage '*Claude*'` | Exit 0; paquete `Claude`, versión `4.4212.0.0` |

OpenCode no pudo abrir su archivo de log en el primer intento dentro del sandbox y terminó con exit 1. Se repitieron únicamente versión y ayudas con acceso al host autorizado, con exit 0. Codex emitió avisos de acceso denegado a sus temporales de arranque en el sandbox, pero mostró versión y ayudas completas y terminó con exit 0. Estos avisos no son fallos de conexión al MCP: no se intentó ninguna conexión. Los CLI pueden gestionar sus temporales o logs de arranque; no se modificó configuración ni autenticación de clientes.

No se añaden flags `rmcp_client` a los ejemplos de Codex: las ayudas comprobadas admiten los comandos indicados, pero no se ha demostrado aquí la necesidad de activar esa opción. Si el login real solicita otro ajuste, registrar el error exacto antes de cambiar la configuración.

## Ejecución manual y registro por completar

Para cada cliente, usar la cuenta propia solo para autenticar y autorizar el acceso. Después solicitar una llamada de lectura a `list_brands` y anotar éxito o error sin copiar nombres de marcas ni otros datos personales al registro. No se modifica ningún dato de la cuenta de uso real.

| Cliente | Fecha y versión de la prueba real | URL MCP confirmada | Configurado sin API key | OAuth / consentimiento | Tool de lectura | Callback exacto sin código ni state | Error o incidencia |
|---|---|---|---|---|---|---|---|
| Claude Code | 2026-10-31; 2.1.314 | URL ngrok verificada | SÍ, entrada HTTP de alcance local | Completado; token emitido a las 19:55:42 UTC | `list_brands` completada una vez; exit 0 | `http://localhost:46319/callback` | Intentos anteriores caducados; repetición con consentimiento inmediato correcta |
| Claude Desktop | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| Codex | 2026-10-31; 0.162.1 | URL ngrok verificada | SÍ, URL sin Bearer pegado | Usuario autoriza; login exit 0; DCR registrado | `list_brands` completada una vez; registro sin datos de marcas | `http://127.0.0.1:54748/callback/qX3zqfvzQ-Pf` | Primer contexto sin llamada; definitivo con servidor obligatorio y descubrimiento de herramientas |
| OpenCode | 2026-10-31; 2.0.18 | URL ngrok verificada | SÍ, configuración de proyecto sin cabeceras | Completado; login exit 0 y token emitido a las 19:57:13 UTC | `list_brands` completada una vez a las 20:06 UTC; exit 0 | `http://127.0.0.1:60041/callback` | Puerto reservado corregido; enlace nuevo abierto en Edge; límite del modelo gratuito superado usando ChatGPT autorizado; ejecución definitiva mediante servicio conectado |

Al registrar el callback, conservar solo su origen y ruta: excluir parámetros como `code`, `state`, tokens y secretos. Si la URI es rechazada, registrar primero el error y contrastar la política `MCP_OAUTH_REDIRECT_ALLOWED`; esta preparación no cambia esa política.

## Refresh token y CIMD

| Cuestión | Conclusión actual | Evidencia necesaria |
|---|---|---|
| ¿Algún cliente exige refresh token? | Los tres CLI **no lo exigen para el login inicial y la llamada observada** | Servidor anuncia solo `authorization_code`; tres tokens emitidos y tres tools reales. Renovación posterior y Desktop no probados |
| ¿Algún cliente exige CIMD? | Los tres CLI completan DCR y una tool sin CIMD | Metadatos sin CIMD, registros y tokens consultados por campos seguros; Desktop pendiente |
| ¿Se ha comprobado DCR con estos cuatro clientes? | **Tres CLI y tres tools reales (3/4)** | Registros y callbacks de Codex, Claude Code y OpenCode en `ca29-evidence/` |
| ¿Hace falta ampliar el servidor o cambiar la política de callbacks? | Ninguno de los tres flujos iniciales lo ha requerido; conclusión global pendiente de Desktop | No se modifica el servidor, sus tiempos de expiración ni la política de redirects para resolver los intentos caducados |

La ayuda de Codex enumera `auto`, `cimd` y `dcr`; la prueba real usa `auto` y completa DCR con este servidor. No se ha implementado refresh token ni CIMD. La conclusión de los cuatro clientes permanece pendiente.

## Checklist de cierre de CA-29

- [x] M-03: Claude Code conecta sin API key y llama a una herramienta; callback anotado.
- [ ] M-04: Claude Desktop conecta sin API key y llama a una herramienta; versión de UI y callback anotados.
- [x] M-05: Codex conecta sin API key y llama a una herramienta; callback anotado.
- [x] M-06: OpenCode conecta sin API key y llama a una herramienta; callback anotado.
- [ ] Conclusión fundada sobre refresh token y CIMD, con evidencia por cliente.
- [ ] Decisión del usuario si una exigencia del cliente requiere ampliar el servidor o su política.
- [ ] Revocar desde la aplicación los tokens OAuth creados por este smoke al terminar.

**T-11 continúa `en-progreso`.** M-03, M-05 y M-06 están acreditados con llamadas reales; M-04 (Desktop), conclusión global y limpieza final siguen pendientes.

## Reanudación: recuperar el endpoint de ngrok

Comprobaciones de solo lectura del 2026-10-31:

| Comprobación | Resultado |
|---|---|
| `APP_URL` de la aplicación local | `https://workable-humorous-rat.ngrok-free.app` |
| HTTPS local `https://metricool.test/up` | **200**, con la validación TLS del host; la primera sonda dentro del sandbox no pudo conectar y no se interpreta como caída de la app |
| Procesos ngrok y API local `127.0.0.1:4040/api/tunnels` | No se observa proceso ngrok; API local no disponible |
| Metadatos públicos `https://workable-humorous-rat.ngrok-free.app/.well-known/oauth-protected-resource/mcp` | **404**; no se ha iniciado OAuth con esta respuesta |
| CLI ngrok instalada | **3.35.0**; ayuda local confirma `--url`, `--host-header`, `--inspect` y `--upstream-tls-verify` |

La aplicación local está operativa; falta recuperar el endpoint público para el smoke. El arranque propuesto usa el dominio ya configurado y dirige a HTTPS local:

```powershell
ngrok http https://metricool.test --url=https://workable-humorous-rat.ngrok-free.app --host-header=metricool.test --inspect=false --upstream-tls-verify=true
```

Se propone gestionarlo como proceso oculto, con logs de advertencias en el directorio ignorado `storage/framework/testing/roadmap-continuation/mcp-runtime/`, sin capturar solicitudes en el inspector. **El túnel expondría la aplicación local completa a Internet, con sus pantallas protegidas por el login de la aplicación; no solo `/mcp`.** No modifica el VPS.

El primer arranque fue rechazado por la revisión automática por falta de autorización explícita para esa exposición pública; no se ejecutó ni se reintentó por otra vía. El usuario respondió **«autorizo»**. Después se arrancó ngrok (PID 30484) a las 17:43:54 UTC y se comprobaron ambos metadatos en 200 antes del consentimiento de Codex. El proceso sigue activo en la comprobación de las 18:08:56 UTC.

## Resultado real de Codex — M-05

El usuario completó el consentimiento en el navegador. `codex mcp login roadmap_ca29_codex` terminó exit 0 y `mcp list --json` informó `auth_status=o_auth`. La [evidencia del login](ca29-evidence/codex/login-result.json) no contiene tokens. El [callback y las fechas](ca29-evidence/codex/callback-metadata.json) se consultaron por campos explícitos, sin leer ni exportar hashes o secretos. La credencial observada caduca el 2027-01-29; no se ha probado ese vencimiento.

La llamada se ejecutó en una sesión efímera con `--ignore-user-config`, `--disable apps` y sandbox `read-only`. Se configuró el servidor por `-c` solo para esa invocación, con `required=true`, `enabled_tools=["list_brands"]` y `mcp_optional_startup_grace_ms=0`. Se permitió descubrir la herramienta antes de invocarla. Estos ajustes están en la [documentación oficial de Codex](https://learn.chatgpt.com/docs/extend/mcp?surface=cli); el primero evita tratar como éxito la ausencia de un servidor opcional al iniciar la sesión.

El [resultado saneado](ca29-evidence/codex/readonly-result.json) y los [eventos](ca29-evidence/codex/events-sanitized.jsonl) muestran una llamada `list_brands` iniciada y completada, cero herramientas inesperadas y exit 0. Devuelve una marca; no se guardan su nombre ni sus datos. La primera sesión efímera no invocó herramientas y devolvió `success=false`; no se contabiliza como smoke aprobado ni se atribuye a un defecto del servidor. La segunda cumple M-05.

## Incidencias anteriores y repetición de las autorizaciones

Claude Code reconoce el servidor HTTP preparado en `.mcp.json` del proyecto de prueba. La revisión automática ha rechazado **iniciar su OAuth y guardar credenciales** porque la autorización explícita recibida cubría el túnel y Codex, no ese cliente distinto. No se ejecutó el comando rechazado.

El usuario respondió **«autorizo»** a la solicitud conjunta para Claude Code, OpenCode y Claude Desktop al mismo MCP, sin API key, con lectura, almacenamiento de credenciales y consentimiento manual. Esa autorización cubre los tres flujos; no se vuelve a solicitar. La conexión y la llamada real siguen siendo requisitos separados.

Claude Code reconoce el servidor de alcance local privado. El primer intento sin terminal falla porque requiere entrada interactiva; los siguientes con PTY terminan con exit 1, `Authentication timeout`, mientras no se completa el consentimiento. OpenCode termina con `Authentication expired`. Se comprueba que ambos procesos han terminado antes de repetirlos. Estos mensajes demuestran que agotaron la espera; no se atribuyen a una incompatibilidad del servidor.

OpenCode 2 usa `mcp.servers`, según la [documentación oficial](https://opencode.ai/v2/docs/mcp-servers). Su primer intento termina con exit 1 antes de OAuth: `Timed out waiting for background service to start`. La causa se verifica mediante logs de servicio y bind local: el puerto 49374 pertenece al rango reservado por Windows 49352–49451 y devuelve `AccessDenied`, sin listener propietario. `opencode service set port 57789` y `service start` recuperan el servicio en `127.0.0.1`; no se modifican reservas de Windows. La [corrección de puerto](ca29-evidence/opencode/service-port-fix.json) es una incidencia de arranque distinta de OAuth.

El usuario solicita relanzar ambos flujos y completa Claude Code. Al indicar que no existe pestaña de OpenCode, se cancela solo ese intento y se abre el enlace recién generado en una ventana visible de Edge; OpenCode termina exit 0. No se introducen credenciales ni se automatizan los consentimientos. El aviso de SDK de Claude Code sobre una credencial guardada sin sello `issuer` se conserva como diagnóstico: el flujo definitivo y la tool funcionan, sin atribuirle un fallo no demostrado.

La [consulta anterior al reintento](ca29-evidence/additional-clients-before-retry.json) no tenía tokens para estos clientes. La [consulta posterior](ca29-evidence/additional-clients-status.json) acredita los nuevos registros, tokens y callbacks. Se conservan el [timeout anterior](ca29-evidence/claude/login-timeout.json) y la [traza saneada de reanudación](ca29-evidence/authorization-retry.json). La corrección es operativa: TDD n/a para consentimiento y documentación; no se cambia código del producto.

## Resultado real de Claude Code — M-03

`mcp get` muestra `Connected`. El [token y callback](ca29-evidence/claude/callback-metadata.json) corresponden al registro de las 19:55:38 UTC y token de las 19:55:42 UTC. La sesión definitiva utiliza `--restricted`, `--strict-mcp-config`, herramientas integradas desactivadas, permiso explícito solo para `mcp__roadmap_ca29_claude__list_brands`, `dontAsk` y `--no-session-persistence`. El [resultado](ca29-evidence/claude/readonly-result.json) y los [eventos](ca29-evidence/claude/events-sanitized.jsonl) acreditan una llamada completada, cero herramientas MCP inesperadas y exit 0 a las 19:56:26 UTC. `StructuredOutput` solo estructura el resultado local del cliente.

## Resultado real de OpenCode con ChatGPT — M-06

El [login](ca29-evidence/opencode/login-result.json) acredita exit 0 y estado posterior `connected`. El [registro y callback](ca29-evidence/opencode/callback-metadata.json) corresponden al cliente de las 19:57:10 UTC y token emitido a las 19:57:13 UTC. El modelo gratuito inicial devuelve `Free usage limit reached`. Después el usuario autoriza ChatGPT en OpenCode; su proveedor `openai` aparece en el inventario y se usa el modelo disponible `openai/gpt-6.1-sol` solo en la invocación de prueba.

Los [intentos anteriores](ca29-evidence/opencode/readonly-prior-attempts.json) se conservan: en modo `--standalone` la herramienta no estuvo disponible y esas respuestas `success=false` no cuentan como éxito. `mcp list` mostró transitoriamente un fallo POST `Bad request`, y después mostró `connected`. Se probó `protocol=auto`, sin obtener conexión verificada en esa sonda, y se restauró el ajuste original. La causa exacta de esos estados anteriores no queda demostrada. Una instrumentación local temporal no capturó excepción porque su sonda ya conectó; se retiró y `bootstrap/app.php` recuperó exactamente su SHA-256 anterior antes de la ejecución definitiva. No se afirma que la instrumentación corrigiera nada.

La ejecución definitiva utiliza el servicio local ya conectado, sin `--standalone`, y un agente temporal `ca29_readonly` que deniega todas las acciones salvo `roadmap_ca29_opencode_list_brands`. El [resultado](ca29-evidence/opencode/readonly-result.json) y los [eventos](ca29-evidence/opencode/events-sanitized.jsonl) muestran una llamada completada, cero herramientas inesperadas, `success=true` y exit 0 entre las 20:06:12 y 20:06:20 UTC. No se conservan nombres ni datos de marcas. La prueba acredita M-06 mediante el servicio; no demuestra que el modo standalone funcione ni explica por sí sola el fallo POST anterior.

## Preparación real de Claude Desktop — M-04

El usuario abre la aplicación tras caducar la aprobación de su lanzamiento por Computer Use. La ventana activa corresponde al paquete 4.4212.0.0. Se observa un aviso de límite de uso hasta «2 nov a las 7:00», sin zona horaria explícita; no se compra ni activa uso adicional. Se navega por Personalización → Conectores → Tuyos. Hay un conector previo «Social scheduler» conectado a `https://social-scheduler.daycry.ovh/mcp`; no se modifica y no acredita el smoke del endpoint local de ngrok.

Se prepara una entrada distinta, `Social Scheduler - CA29 local`, con la URL ngrok autorizada. El segundo paso detecta «Iniciar sesión ahora» y «Registrar automáticamente» (DCR); la opción de identidad publicada (CIMD) no es obligatoria. No se añaden cabeceras ni API key. Al pulsar «Añadir», la revisión automática rechaza la acción antes de ejecutarla porque crea acceso OAuth persistente y exige confirmación en ese momento, pese a la autorización anterior. El formulario queda preparado para que el usuario pulse el botón y complete el consentimiento manualmente. La [evidencia de preparación](ca29-evidence/desktop/preparation.json) conserva solo esos datos; no se exporta el árbol de accesibilidad, contenido de otras conversaciones o capturas.

La [guía oficial de conectores remotos](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp) confirma que esta conexión se realiza desde la infraestructura de Anthropic y permite registro automático. La selección detectada en el formulario no demuestra todavía emisión de token, callback completado ni llamada a una tool; M-04 permanece pendiente.
