# T12: diagnóstico del hold y corrección del contrato de prueba

Estado: F3 nativo confirmado3/3 y plan adoptado para [validación consumidor-local](t12-chart-consumer-validation-contract.md), tras conservar tres ejecuciones errors NO-PASS. No autoriza otro intento de los controladores agotados ni una qa4 automática. El producto mantiene el contrato C2 aprobado de lecturas compartidas. Las condiciones posteriores de este plan ya se observaron en la sonda, pero no sustituyen la UI completa pendiente.

## Evidencia antes de cambiar el harness

Errors3 supera tabla, negativos, primeraxe completo, teclado y primera cancelación con retorno del foco y documento vacío. El siguiente hold se arma para GET/table; el registry obtiene la revisión desde GET/export?format=json&revision=1. El hook compara pathname exacto. Control sin claimed/journal y preview ya disponible son coherentes con esa diferencia; todavía se requiere contraprueba de transporte.

waitFor cancela la espera de un consumidor; el fetch tiene un controlador propio. El registry permanece vivo hasta completarse, fallar, timeout o dispose. La unidad existente de dos consumidores pasó1/1 con fetch simulado; no demuestra HTTP ni el último consumidor. Forzar dispose al cerrar un modal cambiaría la vida del registry compartido y podría afectar al editor.

## Sonda causal autorizada para preparar

Una salida nueva de diagnóstico y tres condiciones: ruta/table frente a/export con segunda lectura de caché; ruta/export correcta y cancelación del único consumidor; ruta/export correcta y dispose del registry como control del transporte. Registry/parser/frontend/hook reales en Chromium, backend Node con289bytes originales/SHA exacto, dos puertos0 de loopback y resolver cerrado.

Sin Laravel/PHP/DB/.env/build/proveedores. Node512MiB, Job3GiB COMMIT, preflight6GiB, piso3GiB/100ms y90s. Registrar URL/query reales, captura200, bytes/SHA, estado de promesa, señal de fetch, cierre de cliente, release y caché por separado. Fuentes/puertos/procesos comprobados. Preparador no ejecuta navegador; root revisa READY y ejecuta una vez. Resultado parcial si no reproduce, sin ampliar casos o timeouts. La sonda no acredita UI o noLateApply.

## Decisión posterior condicionada a F3

Si se confirma cancelación local con transporte compartido vivo, corregir explícitamente SPEC/harness, helper, afterEach y contador juntos. No cambiar C2 para satisfacer la expectativa errónea de ERR_ABORTED. La red seguirá rechazando cualquier aborto inesperado. Mantener un control independiente de dispose para el aborto físico.

La futura regresión UI deberá observar: modal cerrado y foco restaurado antes de liberar; respuesta real retenida y luego entregada con bytes/SHA idénticos, sin deadline; fin de esa respuesta y contenido original comprobados; documento/canvas/selección conservados después; reapertura de la misma referencia ya completada desde caché, sin un segundo GET/export. El asentamiento debe depender de eventos reales y estado observable, sin sleeps ni afirmaciones de que el consumidor recibió un AbortError si ese dato sólo se probó en la sonda.

Para ese modo de consumidor, los recibos esperados serían captured+released, cancelled_delivery=false y cero eventos client-cancelled/abortos intencionales. Se redactarán primero criterios y pruebas positivas/negativas del nuevo contador; el contador histórico de35checks y los tres resultados anteriores permanecerán inmutables. Si F3 observa otra semántica, este plan se revisa antes de aplicar cambios.

El cleanup conservará la evidencia aunque no se haya capturado un hold; no fabricará capturas ni releases. El flujo completo continuará con todos sus checks de precisión, estilo, Inspector, B4 y accesibilidad. Una nueva validación necesita plan, revisión fresca y manifiesto actual. Build5 sólo se repite si cambian entradas reales de compilación. T12 y el roadmap permanecen abiertos.
