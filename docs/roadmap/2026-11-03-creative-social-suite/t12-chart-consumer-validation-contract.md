# T12: validación corregida del consumidor compartido

Estado: plan adoptado tras diagnóstico3/3 de HTTP real. La autorización del usuario de continuar de forma independiente permite corregir el contrato de prueba que contradecía C2, conservando la funcionalidad solicitada. No se cambian C2 ni el producto para forzar un aborto de transporte. Los tres intentos errors anteriores permanecen NO-PASS y no se relanzan.

[Diagnóstico real](testing/t12/hold-runtime-diagnostic/report.md): ruta real/export?format=json&revision=1; cancelar la única espera produce AbortError local, entrega intacta y caché completada; dispose del registry sí produce ERR_ABORTED y client-cancelled. La sonda no prueba la UI. Las expectativas históricas de tres abortos y nueve eventos pertenecen al harness anterior y quedan sustituidas sólo para la nueva validación consumidor-local.

## Flujo público completo

E2E28.03 conserva tabla/tipos/lexemas, negativos, teclado/foco, precisión safe/approximate, estilo, Inspector, B4 y tres axe completos. El bloque de carga retenida usa GET/export y comprueba query exacta format=json/revision1 mediante la respuesta real observada. No hace otro GET para obtener los bytes ni simula respuestas.

Antes de liberar: modal cerrado, foco en Tablas de datos y documento vacío. Tras liberar explícitamente: respuesta terminada sin error y cuerpo real guardado con tamaño/SHA iguales a captured/released; modal sigue cerrado, foco restaurado, documento/capas vacíos. Después se reabre la misma referencia completada y se espera su preview listo, se comprueba tabla original y se vuelve a cancelar: ningún segundo GET/export, documento intacto. Asentamiento por response.finished/body y estado real de preview, sin sleeps.

Esta UI no afirma observar directamente el AbortError del consumidor; ese dato se acreditó en la sonda de registry real. Comprueba efectos observables y ausencia de aplicación tardía. Red estricta: issues=[], expected_http=[] y cero abortos intencionales/inesperados para este modo. El control independiente de dispose conserva la prueba de aborto físico.

## Artefactos por caso

Se mantienen viewport, tres axe, tres capturas, foco, B4 y font-responses vacío. `delayed-real-response.json` contiene exactamente captured+released del journal real, mismo token/GET/ruta/200/bytes/SHA, deadline_release=false y cancelled_delivery=false. Journal del grupo completo: seis eventos, sin client-cancelled. Query observada y bytes se contrastan con los dos artefactos siguientes.

`consumer-response-body.json` son los bytes originales de response.body(), sin parsear y reserializar. `consumer-cancellation.json` usa este esquema obligatorio, con valores obtenidos de observaciones/aserciones reales:

```json
{
  "mode": "consumer-local",
  "method": "GET",
  "request_target": "/brands/{brand}/studio/datasets/{dataset}/export?format=json&revision=1",
  "delivery": {"status": 200, "mime": "application/json", "finished": true, "bytes": 289, "sha256": "64hex", "body_file": "consumer-response-body.json"},
  "ui": {"closed_before_release": true, "focus_before_release": true, "element_count_before_release": 0, "closed_after_delivery": true, "focus_after_delivery": true, "element_count_after_delivery": 0, "layer_count_after_delivery": 0},
  "cache": {"requests_before_reopen": 1, "requests_after_reopen": 1, "preview_ready": true, "table_cells": ["textos td realmente observados"], "closed_after_reopen": true, "element_count_after_reopen": 0}
}
```

289 es el tamaño observado en la fixture actual; el contador compara el tamaño real de archivo/recibos, no acepta un número declarado sin bytes. MIME puede contener charset válido. Query debe tener exactamente format=json y revision=1, sin duplicados. table_cells debe coincidir con category+s0 de las cuatro filas originales, con tipos y lexemas; se verifica de forma independiente desde el cuerpo real, sin importar lógica del producto.

## Cleanup y pruebas antes del nativo

El helper añade `cleanup()`: escribe siempre el marcador de liberación; si aún no hubo captura devuelve captured=false/released=false con eventos observados, sin esperar ni fabricar release. Si hubo captura, espera el release real y devuelve captured=true/released=true/eventos. Es idempotente. La liberación explícita del escenario sigue exigiendo el evento real y rechaza deadline; no se debilita por el modo cleanup.

afterEach guarda primero todos los recibos posibles, incluso si hubo error de cleanup, y conserva ese error para fallar después; nunca pierde viewport/red/fonts por una liberación fallida. Escribe `hold-cleanup.json`, lista de resultados reales. Para un caso completo la lista tiene un elemento captured/released verdaderos y los mismos dos eventos.

TDD del helper: test significativo antes de añadir cleanup, con rojo real de API ausente o comportamiento incorrecto; pruebas de uncaptured, captured/released e idempotencia. No llamar a eso HTTP real. Nuevo contador en carpeta propia: RED del contador histórico frente al modo consumidor, luego positivos/negativos de ruta/query/bytes/SHA/UI/cache/cleanup/abortos/eventos, conservando axe/foco/B4/PNG/isolation y fallos parciales. No reescribir los35tests históricos.

## Ejecución independiente y puertas

Nuevo operador privado `storage/framework/testing/t12-chart-consumer-errors-qa1`, salida única `out/consumer-execution1`, modo consumer-local, JSON original+blob, E2E28.03 íntegro×375/768/1440. No qa4, merge ni matriz global. Un worker/retries0/maxFailures1; Node512/PHP256 y Job3GiB COMMIT, preflight6/piso3 cada100ms, mismo900s incluyendo finalize. Guard y pendientes de otras redes/VPS no cambian.

Root revisa código y contrato, comprueba scope, aplica lentes frescas y autoriza un manifiesto flat actual. Build5 se reutiliza sólo si sus62inputs/77assets permanecen idénticos; los cambios de harness ajenos a compilación no justifican repetirlo. Preparador no ejecuta navegador ni PHP. Tras READY congelado, una ejecución root y cierre/inspección/integridad antes de aceptar únicamente este bloque. T12 y el resto del roadmap permanecen abiertos.
