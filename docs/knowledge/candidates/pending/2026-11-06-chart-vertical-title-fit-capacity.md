---
project: metricool
scope: studio-chart-axis-title
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:fabric
  - topic:typography
---

# Ajustar un título vertical contra las dimensiones adecuadas

**Síntoma.** Valores solicita18px pero se exporta a8,53px en el gráfico800×500. El título ocupa una franja alta y estrecha, aunque se mide como línea horizontal.

**Causa probada.** El fit horizontal usa29,76px de ancho para una línea nativa de62,802px. La reducción uniforme produce8,53px. Rotar después de ese ajuste conserva el tamaño reducido. Intercambiar las dimensiones disponibles para la línea antes de rotarla−90° permite18px en la fixture y diez variantes analizadas.

**Qué hacer.** Mantener la caja semántica y centrar la línea rotada, ajustando contra alto/ancho de la franja. Conservar escala uniforme y avisos que tengan en cuenta esa orientación. No aumentar la fuente mínima a costa de overflow ni desplazar plot/barras/ticks. La transformación raíz se compone con la anotación interna.

**Evidencia.** [Contrato](../../../roadmap/2026-11-03-creative-social-suite/t12-chart-axis-title-contract.md), diagnóstico F1–F3 privado con métricas históricas y pruebas públicas studio-chart-scene/studio-chart-canvas: RED3 previo al producto, GREEN41/41; diez hashes de escena ajenos al título permanecen iguales. La medición Fabric del unit está controlada; no prueba tinta, shaping, layout nativo ni matrices compuestas. La aceptación SVG/PNG/PDF nueva sigue pendiente. Candidato para knowledge-curator, sin promoción.
