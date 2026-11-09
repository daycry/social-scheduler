# T-11: selección, orden, escala y calidad audiovisuales

2026-11-05. Subbloque interno verificado; T-11 continúa abierta, 7/23 tareas completas.

El compilador admite selección y orden explícito de páginas, conserva los intervalos originales de música y su fase dentro de secuencias contiguas, y aplica crossfade en los saltos. Convierte v1 en memoria sin alterar el documento guardado. Escala antes de rasterizar y aplica calidad a CRF H264 y paleta GIF. La unión rechaza tramos con opciones distintas. Las transiciones inválidas por la nueva adyacencia se rechazan.

Revisión fresca A1/B1/D1 sin gaps pendientes. C=false por selector sobre este delta; no equivale a auditar la futura API. A comprobó nueve fuentes/dos bundles, reconstrucción idéntica y 15 pruebas focales; B ejecutó 25 focales, D ocho de selección y mediciones locales. D: 100 páginas/64 pistas, medianas baseline 2,69 ms, nueva selección completa 2,56 ms, 99 contiguas 2,37 ms, inversa 3,16 ms. Las 6.400 entradas virtuales comparten 64 envolventes y ocho fuentes. Proyecto de 3.500 rectángulos: baseline 12,4 ms, completa 11,3 ms, inversa 25,2 ms. No son medidas del VPS.

Integración ejecutada por root después de la revisión, sin atribuir QA independiente: build completo correcto; frontend **137/137**, 28 archivos, cero fallos/cancelados/skips; E2E **42/42**, 14 escenarios en desktop/375/360, cero fallos/flaky/skips/interrupted/errores del runner/reintentos y gate VERDE. Las nueve fuentes y dos bundles coinciden antes/después. Sesiones 6777/67766 terminales; raíz `run-general-4db4152c-a9be-430a-a9ee-1d40098c4d2b`. No repetir sin cambios o fallos nuevos.

Los nativos comprueban fotogramas y geometría escalados, transición slide, calidad real en bytes/paleta, rechazo de mezcla de calidades, póster v1 agotado y comparación PCM de chirp reordenado/con crossfade con RMS < 1e-7. Se conservan RED/GREEN y caracterizaciones en el ledger. Tres capturas actuales de audio inspeccionadas, controles accesibles y sin recorte horizontal en los tres viewports; las otras capturas del runner no se declaran inspeccionadas. No se repitió PHP sin cambios PHP.

[Manifiesto](manifest.json): nueve fuentes, dos bundles y 13 artefactos con SHA-256. OpenAPI sin cambios. El manifiesto del motor anterior acredita su snapshot histórico; este bloque lo amplía y no reatribuye las cifras anteriores.

Sigue pendiente: integración de snapshots/checkpoints/jobs, presupuesto total y límite OS/RSS efectivo, progreso/cancelación/reintento, registro/borrador y web/API/MCP. El [contrato de jobs](../../../t11-job-contract.md) es diseño previo, todavía no implementación. No se habilitan MP4/GIF públicamente ni se cierra T-11. Sin ONNX, VPS o publicaciones reales.
