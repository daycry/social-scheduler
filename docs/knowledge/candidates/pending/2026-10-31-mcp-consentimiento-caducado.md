---
proposed_by: implementer
estado: propuesta
category: GOTCHA
evidencia: validated_case
fuentes:
  - docs/roadmap/2026-10-22-social-login-mcp-oauth/testing/smoke-ca29.md
  - docs/roadmap/2026-10-22-social-login-mcp-oauth/testing/ca29-evidence/authorization-retry.json
tags:
  - area:testing
  - feature:mcp-oauth
  - iniciativa:2026-10-22-social-login-mcp-oauth
---

# Un consentimiento MCP caducado requiere un flujo nuevo

## Síntoma

Claude Code termina con `Authentication timeout` y OpenCode con `Authentication expired`, sin token emitido. El usuario también informa que no se ha abierto ninguna pestaña de OpenCode.

## Causa comprobada y límites

Los procesos agotaron su espera de autenticación. Repetirlos y completar el consentimiento con el flujo vigente permite emitir ambos tokens. El mensaje del CLI que solicita autorizar en el navegador no demuestra que exista una pestaña visible. No se ha medido aquí la duración máxima de cada cliente ni se atribuye el fallo POST transitorio posterior de OpenCode a estos timeouts.

## Qué hacer en su lugar

Conservar el identificador del proceso y comprobar si sigue activo antes de relanzarlo. Si ha terminado por caducidad, generar un flujo nuevo y completar su consentimiento inmediatamente. Si el usuario no ve una pestaña, cancelar solo el intento propio y abrir el enlace recién generado en una ventana visible; no reutilizar enlaces antiguos. Mantener el consentimiento a cargo del usuario y excluir code, state, verifier y tokens de la evidencia.

## Evidencia

La [traza del reintento](../../../roadmap/2026-10-22-social-login-mcp-oauth/testing/ca29-evidence/authorization-retry.json) conserva los errores anteriores y la repetición autorizada. Las llamadas reales de [Claude Code](../../../roadmap/2026-10-22-social-login-mcp-oauth/testing/ca29-evidence/claude/readonly-result.json) y [OpenCode](../../../roadmap/2026-10-22-social-login-mcp-oauth/testing/ca29-evidence/opencode/readonly-result.json) completan `list_brands` una vez cada una, con exit 0. OpenCode necesitó además el acceso ChatGPT autorizado por el usuario y el servicio conectado. No se cambian los timeouts ni el código del servidor.

Es un candidato pendiente de curación, no conocimiento aprobado.
