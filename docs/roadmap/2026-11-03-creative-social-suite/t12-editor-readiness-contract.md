# T12: interacción durante la inicialización del editor

Plan adoptado tras consumer-local1 NO-PASS: el gráfico vacío histórico se renderiza al recargar, pero seleccionar su capa puede preceder a la asignación del adaptador. No se modifica C2, B4 ni el escenario público para ocultar el error.

La reproducción pura compila script y template reales de Editor.vue y difiere sólo createStudioCanvas. Con ready=false ya existen capas, el grid no es inert y onSelect del LayerPanel real produce `Cannot read properties of undefined (reading 'select')`. Al resolver la misma promesa, ready=true y la misma capa se selecciona; documento sin cambios. Evidencia F1–F3 y RED en `storage/framework/testing/t12-chart-selection-readiness1/`.

El grid del editor debe impedir interacción de puntero y teclado mientras ready=false, mediante inert nativo. Al terminar la inicialización debe volver a ser interactivo y permitir seleccionar la misma capa, con el adaptador real ya asignado. Se conservan los bloqueos existentes de automationBusy/fontBusy. No se oculta la selección con optionalchain, no se añaden sleeps ni se cambia la prueba E2E para esperar artificialmente.

Prueba pública nueva `tests/frontend/studio-editor-readiness.test.mjs`: Editor real compilado, inicialización diferida, VNode de interacción inerte antes de resolver; liberación del bloqueo y selección original después; bloqueos existentes conservados. RED antes del cambio funcional y GREEN después, más vecinos pertinentes. Esta prueba pura no acredita comportamiento nativo de inert ni B4 completo: requieren nueva validación real revisada después del fix y build actual.

Alcance mínimo: Editor.vue, prueba y evidencia; nuevo build6 con mismos límites y configuración revisados, sin .env ni datos reales. Revisión fresca A+B sobre el delta y conservación literal del veredicto anterior; T12 permanece abierta. Consumer1 y todos los resultados históricos son inmutables. La siguiente validación tendrá salida propia, sin reintento automático ni ampliación de900s/3GiB/preflight6/piso3/100ms/Node512/PHP256.
