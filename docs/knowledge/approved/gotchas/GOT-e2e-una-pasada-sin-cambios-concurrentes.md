---
category: GOTCHA
content_origin: agent
estado: aprobado
evidencia: validated_case
fuentes:
  - docs/roadmap/2026-11-03-creative-social-suite/retro.md
  - docs/roadmap/2026-11-03-creative-social-suite/tasks.md (cierre T-22)
  - playwright.config.ts (webServer serve.cjs en el puerto 8080)
id: metricool.GOT-005
proposed_by: retro
tags:
  - area:testing
  - area:e2e
version: 1
---
# E2E: una sola pasada de Playwright y sobre código congelado

`playwright.config.ts` levanta su propio servidor (`tests/e2e/isolated/serve.cjs`) en el puerto fijo 8080 con `reuseExistingServer: false`. Por eso dos ejecuciones a la vez chocan. Además, una pasada completa (unos 1,5 h) que coincide con otro proceso haciendo `npm run build` o cambiando PHP da rojos que no son defectos. En T-22 hubo 6 rojos así, y la repetición sobre el HEAD congelado dio 772/0. Comprueba que el 8080 está libre, no compiles ni edites durante la pasada, y haz la pasada final después del último commit.
