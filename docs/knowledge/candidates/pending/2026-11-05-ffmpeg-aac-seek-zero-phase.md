---
project: metricool
scope: studio-audiovisual
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:ffmpeg
  - topic:audio
---

# Seek explícito a cero puede adelantar el AAC de un MP4

**Síntoma.** El audio de dos tramos pierde continuidad: baja la amplitud del tono combinado aunque cada tramo contiene la frecuencia esperada. La comparación con una decodificación continua da RMS0,057647 en el primer tramo.

**Causa observada.** En la fixture MP4/H264 ultrafast sin B-frames y AAC de esta instalación, añadir `-ss 0` antes del input cambia la salida respecto a omitir seek. La lectura queda adelantada1024muestras; RMS0,116513 sin compensar y0,000003394 comparando con el origen adelantado. No se atribuye universalmente a todos los códecs/versiones ni se afirma un mecanismo interno no inspeccionado.

**Qué hacer.** Omitir seek0 corrigió solamente esa fixture; la revisión B1 reprodujo otros desfases al buscar posiciones no nulas en M4A/MP3 a44,1/48kHz. La solución general de este motor normaliza una vez cada origen a PCM48000 en disco y luego lee offsets enteros de muestras. Comparar cada tramo con una decodificación continua; no rellenar una lectura incompleta con su última muestra. El PCM derivado requiere presupuesto de disco y checkpoint de preparación.

**Evidencia.** Regresión permanente en `tests/frontend/studio-audiovisual-render.test.mjs`, caso `native MP4 assembly`, y casos comprimidos/truncado en `studio-audiovisual-audio.test.mjs`. RED inicial `6753a3`, GREEN parcial5/5 `083d37`; RED ampliado M4A/MP3 `045e1b` y GREEN normalización11/11 `892432`; fases y sondas en el ledger T-11. Esta entrada queda propuesta; no se promueve sin knowledge-curator.
