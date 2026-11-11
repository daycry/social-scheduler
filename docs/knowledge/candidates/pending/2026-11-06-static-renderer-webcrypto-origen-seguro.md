---
project: metricool
scope: studio-static-renderer
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:chromium
  - topic:webcrypto
---

# El origen sintético del renderizador necesita un contexto seguro para verificar SHA

**Síntoma.** Los gráficos funcionan en el editor local, pero el render estático en servidor termina en `failed`. El entrypoint real de Node muestra `Cannot read properties of undefined (reading 'digest')`.

**Causa observada.** El mapa cerrado de recursos se presentaba al navegador como `http://studio-renderer.invalid`. En esa navegación Chromium no expone `crypto.subtle`; el registry de datasets necesita `subtle.digest` para validar SHA. Una sonda con el registry real, mismo JSON291B y mismo SHA falla en HTTP y resuelve cuatro filas al usar HTTPS. El bloqueo de FontFaceURL por CSP no era la causa del camino de fuentes usado por la aplicación, que carga mediante fetch y ArrayBuffer.

**Qué hacer.** Usar el origen sintético `https://studio-renderer.invalid` y seguir satisfaciendo todas las solicitudes desde el mapa cerrado. Conservar CSP, comprobación SHA y política de solicitudes. Verificar el entrypoint real además de las pruebas de servicio que simulan el proceso Node.

**Evidencia.** [Diagnóstico y recibos nativos](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/static-secure-origin/report.md), script `scripts/studio/render.mjs:19` y regresión integrada permanente `tests/e2e/studio-charts.spec.ts`, escenario E2E28.02. La reproducción CLI anterior falla al calcular SHA; tras cambiar sólo el protocolo, SVG/PNG/PDF generan archivos reales con la misma fixture y fuente privada. La matriz completa sigue pendiente; esta entrada es una propuesta y no se promueve sin knowledge-curator.
