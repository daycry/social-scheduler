---
project: metricool
scope: studio-chart-empty-table
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:chromium
  - topic:accessibility
---

# Informar el estado vacío conservando asociaciones de cabecera

**Síntoma.** Una revisión histórica sin filas conserva correctamente sus columnas, pero axe devuelve un incomplete th-has-data-cells; las cabeceras no tienen celdas asociadas. No es una violación confirmada ni un fallo de contraste.

**Causa probada.** La tabla tiene thead y tbody vacío. El comprobador instalado inspecciona todas las secciones y devuelve undefined cuando no encuentra celdas asociadas. En Chromium aislado, el baseline reconstruido produce un incomplete; añadir tfoot con un mensaje de estado y colspan para todas las columnas hace que la regla se ejecute y pase. El tbody conserva cero filas.

**Qué hacer.** Mostrar «Esta revisión no contiene filas.» en una celda de footer cuyo colspan sea displayedColumns.length+1, contando la columna Fila. Usar la colección original vacía para decidirlo. No confundir una página visible sin datos con una revisión vacía, ocultar cabeceras ni desactivar reglas.

**Evidencia.** [Sonda nativa3/3](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/empty-table-diagnostic/report.md), [contrato](../../../roadmap/2026-11-03-creative-social-suite/t12-chart-empty-table-contract.md) y prueba pública `tests/frontend/studio-charts-dialog.test.mjs`: RED por footer ausente antes del cambio, GREEN23/23 incluidos controles de selección dinámica de columnas y página vacía con datos originales. Sólo se ha modificado el HTML del footer. La sonda es una tabla reconstruida y una sola regla; la aceptación del diálogo real completo tras el cambio sigue pendiente. Candidato para knowledge-curator, sin promoción.
