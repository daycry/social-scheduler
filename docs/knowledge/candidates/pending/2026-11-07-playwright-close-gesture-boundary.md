---
project: metricool
scope: studio-presentation-tests
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:playwright
  - topic:observation-boundary
---

# La marca de cierre debe seguir al gesto real, no al inicio de la automatización

**Síntoma.** El caso nativo de presentación AV rechaza cinco eventos de reproducción supuestamente posteriores al cierre, aunque los medios terminan pausados.

**Causa probada.** La prueba registra `performance.now()` antes de llamar al clic de Playwright. Las esperas de visibilidad, estabilidad y desplazamiento ocurren después de esa marca; la reproducción sigue siendo legítima durante ellas. La traza sitúa los cinco eventos rechazados al menos35.084ms antes de `performing click action`. La regresión pura ejecuta el bloque real del spec y reproduce la confusión con un evento a10ms durante una espera de50ms.

**Qué hacer.** Armar un listener de captura sobre el botón sin marcar aún el cierre. Registrar tiempo e índice de eventos cuando llegue el clic confiable, antes del handler existente; verificar que esa marca ocurrió. Mantener la comprobación temporal y añadir la frontera de índice para detectar eventos posteriores con el mismo timestamp. No borrar eventos ni tomar el corte después de resolver el clic: ocultaría carreras reales durante el cierre.

**Evidencia.** `tests/frontend/studio-presentation-close-observer.test.mjs` ejecuta el bloque real de `tests/e2e/studio-presentation-media.spec.ts` y el helper de captura. RED del falso positivo previo y RED de resolución temporal igual; GREEN6/6 puro. [Prueba temporal original](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/presentation-native-2026-11-07/close-boundary-proof.json). La revisión del fix y la aceptación nativa posterior siguen pendientes al proponer esta entrada. El intento original permanece fallido1/23noejecutados; no se reclasifica como PASS. Pendiente de knowledge-curator, sin promoción automática.
