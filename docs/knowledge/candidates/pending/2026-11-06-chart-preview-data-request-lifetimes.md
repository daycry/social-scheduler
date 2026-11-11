---
project: metricool
scope: studio-chart-dialog
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:vue
  - topic:request-lifetime
---

# La vista previa y la tabla del gráfico necesitan cancelaciones independientes

**Síntoma.** Al pulsar Ver datos inmediatamente después de abrir el diálogo, la vista previa aparece pero la tabla de la revisión guardada queda ausente. La primera validación real de E2E28.03 falló al localizarla.

**Causa probada.** viewData y refreshPreview compartían sequence y la señal del preview. El debounce de200ms arrancaba refreshPreview, invalidaba esa generación y descartaba una tabla todavía pertinente. La reproducción con Vue compilado, reloj controlado y una lectura pendiente recibe dataTable=null después del tick; sin cambiar el nombre accesible ni esperar al preview.

**Qué hacer.** Dar a la lectura su propia generación y AbortController. Cancelar al reemplazarla, cerrar, desmontar o cambiar contexto; contrastar id/revision/SHA antes de publicar. Los cambios de estilo y el inicio del preview conservan la lectura solicitada. Mantener también las garantías independientes del preview y del registry compartido.

**Evidencia.** Regresión permanente `tests/frontend/studio-charts-dialog.test.mjs::opening source data survives scheduled preview startup`, RED1→GREEN1 y54tests vecinos; [fallo nativo original sellado](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/errors-focused-qa1/report.md). La revisión A/B del fix no encontró hallazgos y build4 compiló sin deriva. La regresión UI posterior todavía está pendiente al proponer esta entrada; no se declara aceptación E2E ni del roadmap. Pendiente de knowledge-curator, sin promoción automática.
