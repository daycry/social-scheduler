---
project: metricool
scope: studio-chart-dialog
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:chromium
  - topic:accessibility
---

# Contraste comprobable en selects y datos desplazados del diálogo

**Síntoma.** El primeraxe del modal de gráficos devolvía cero violaciones y16nodos de contraste incompleto: tresselects por imagen de fondo, doceceldas y unstatus por depender del fondo del diálogo fuera de su rectángulo visible.

**Causa probada.** Dos efectos independientes en la versión instalada: la flecha CSS impide determinar el fondo del select; el primer fondo opaco del grupo de datos era el diálogo y no abarcaba todos los textos desplazados. La sonda reconstruida con CSS real reproduce12nodos incompletos, baja a10 al usar select nativo, a2 al dar fondo blanco a la sección de datos y a0 al combinar ambas medidas.

**Qué hacer.** Mantener el select nativo sin imagen de fondo y hacer explícito el mismo blanco heredado en la sección que agrupa tabla, navegación ystatus. Conservar colores, contenido, opciones, scroll y foco. En la prueba local el control gana1px de alto; no afirmar igualdad geométrica total. Mantener completas las reglas y la exigencia de incomplete vacío.

**Evidencia.** [Diagnóstico nativo4estados](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/select-contrast-diagnostic/report.md) y [errors3](../../../roadmap/2026-11-03-creative-social-suite/testing/t12/errors-focused-qa3/report.md). Tras el cambio, el primeraxe real del modal tiene violations e incomplete vacíos y el flujo supera teclado/foco. La ejecución completa falla después en el hook de respuesta retenida; no se atribuye aceptación del escenario entero o del roadmap. Entrada propuesta para knowledge-curator, sin promoción automática.
