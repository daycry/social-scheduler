---
project: metricool
scope: studio-editor-readiness
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:vue
  - topic:initialization
---

# Bloquear la interacción con capas hasta que exista el lienzo

**Síntoma.** Tras recargar un diseño, pulsar una capa puede mostrar `Cannot read properties of undefined (reading 'select')` y dejar el Inspector sin selección.

**Causa probada.** Las capas proceden del documento y ya se muestran mientras createStudioCanvas sigue pendiente. La asignación de adapter ocurre después del await; el evento de selección puede ejecutarse antes. Editor.vue real compilado reproduce el fallo con una factory diferida; al resolver la misma promesa, la misma capa se selecciona y el documento permanece idéntico.

**Qué hacer.** Mantener inert nativo en el grid mientras ready=false, además de los bloqueos de automatización y tipografías. Liberar el bloqueo cuando termine la inicialización. Evitar optionalchain que descarte la acción y esperas arbitrarias en las pruebas.

**Evidencia.** [Consumer1 real, NO-PASS](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/consumer-local-qa1/report.md), [contrato adoptado](../../../roadmap/2026-11-03-creative-social-suite/t12-editor-readiness-contract.md) y `tests/frontend/studio-editor-readiness.test.mjs`: RED antes del cambio; GREEN3/3 con inicialización diferida, selección posterior y bloqueos conservados. La prueba compila script y template reales; no acredita Fabric ni interacción nativa de inert ni B4 completo. Esa validación sigue pendiente. Candidato para knowledge-curator, sin promoción automática.
