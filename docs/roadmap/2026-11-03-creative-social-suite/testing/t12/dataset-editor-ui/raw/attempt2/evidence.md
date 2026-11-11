# UI revisión intento2 — correcciones A1/B1

2026-11-06. Base exacta: `t12-dataset-editor-ui-review/attempt1-final/manifest.json`, siete fuentes + dos dependencias core verificadas antes de tocar. Ownership limitado a diálogo/testdel diálogo. Baselines `before/`, candidatas `after/`, delta completo `delta.diff`. No cambios en cliente, Index/Editor, E2E, core, contratos, ledger, build ni BD.

## B1: estado dirty cacheado tras guardar — RED → GREEN

Test focal nuevo `dialog save invalidates a rendered dirty cache after an applied cell edit without renaming`, SFC compilado real, Vue/ref/computed y núcleo tipado real. Carga revisión2/nombreDatos; lee dirtyfalse, aplica celda numérica -0.0, lee dirtytrue **antes de guardar**, como la plantilla. Cliente de fixture confirma una única escritura con nombre sin cambiar/revisión esperada2/lexema -0.0 y devuelve revisión3. Revisión/status yaactualizados; RED genuino: dirty seguíatrue frente a false, exit1. No error de importación ni fixture.

`red-dirty-cache.log/.json` preservan comando y fallo real. Después se añadió únicamente `historyVersion.value++` al establecer baseline savedTable/savedName tras éxito. La baseline usa la invalidación explícita ya usada en carga, reset y operaciones del historial; ahora el guardado la invalida también. No cambia transporte, semántica de versiones, undo/redo ni carreras.

Mismo focal GREEN1/1,exit0 (`green-dirty-cache.log/.json`), incluye dirtytrue al deshacer, dirtyfalse al rehacer y cierre sin alerta de descarte después de guardar. El fixture de cliente no acredita TCP/BD nuevos: prueba la reactividad real del componente con la respuesta de revisión correcta.

## A1: etiquetas de tipo

TDD n/a: estilo declarativo. Único cambio de clase del tipo de celda: text-[9px]/slate400 → text-xs/slate600 (0.75rem,12px nominales con raíz16px). SHAfooter slate600/text-xs ya ajustado por root permanece intacto. `contrast.mjs` usa colores de Tailwind instalado y luminancia sRGB relativa: antes2,564:1 blanco/2,338:1 violet50; después**7,578:1 y6,909:1**, ambos≥4,5. No es un test que replique clases ni aceptación de axe renderizado.

Primera calculadora auxiliar no expandía #fff abreviado, produjo NaN/null sobre blanco y passfalse; se conserva `contrast-first-invalid-3hex.*` como fallo de harness, no fallo del producto ni REDTDD. Calculadora corregida expande tres dígitos; `contrast.json` final pass=true, comando node exit0. El build y axe/UI siguen a cargo de root/QA.

## Verificación y conservación

`run-neighbors.py`/`verification.json`: **38/38 PASS**,0fail/cancelled/skipped/todo,exit0,32testspropioscliente/dialog+6vecinoscaptions/exportpanel. Nueve SHA iguales antes/después; las otras siete fuentes del manifiesto previo permanecen exactas (cinco fuentesincluidoE2E + doscore). No regresión amplia PHP o nativa nueva.

`original-artifacts-preserved.json` comprueba los138 artefactos de la entrega original intactos y manifiesto original sin reescribir. Su frase de CRLF se conserva como evidencia histórica errónea: root ya verificó baselinesIndex/Editor LF y restauró LF. Aquí se reconoce el error, sin retocar esa evidencia ni esas páginas.

Fuentes congeladas para A2/B2. No commit/push/build/browser/server/env/DB/proveedor/ONNX. UI y T12 siguen sin aceptación global; root registra ledger.
