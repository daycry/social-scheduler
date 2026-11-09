# T12: estado accesible de una revisión vacía

Plan adoptado tras consumer2 y [diagnóstico nativo3/3](testing/t12/empty-table-diagnostic/report.md). El axe completo del caso histórico tiene cero violaciones y un incomplete th-has-data-cells; no es un problema de contraste. Una tabla aislada reconstruida confirma que el footer de estado vacío resuelve esa regla manteniendo las cabeceras y cero filas en tbody. No acredita todavía el escenario completo ni el diálogo real modificado.

Cuando la revisión original no tiene filas, el diálogo debe conservar la tabla, su caption y las cabeceras Fila y columnas seleccionadas. Añadirá un tfoot visible con una celda de ancho displayedColumns.length+1 y el mensaje «Esta revisión no contiene filas.». El footer comunica el estado de toda la tabla; no representa una fila del dataset. El tbody seguirá teniendo cero filas. El contador de filas y las referencias de revisión/SHA conservarán sus valores originales.

La condición debe usar la colección original vacía, no sólo la página visible vacía. Con datos no aparecerá el footer; cabeceras, valores tipados, paginación y selección de columnas permanecerán iguales. No ocultar la tabla/cabeceras, alterar roles, inventar filas de datos ni excluir reglas de axe.

Antes del cambio funcional, una prueba pública del diálogo real compilado debe fallar por ausencia de este estado accesible. Después comprobará footer/colspan/mensaje y tbody vacío, además de la ausencia del footer con datos. El RED/GREEN y los vecinos se registran en tasks.md. La sonda previa es diagnóstico, no reemplaza el RED público ni la aceptación UI.

Alcance: StudioChartsDialog.vue y sus pruebas existentes. No cambia C2, formato de tablas, renderer, API/MCP ni E2E28.03. Tras revisión y build actual, una nueva validación independiente conservará el escenario completo, nueve axe vacíos y los tres viewports. Consumer1/2 y todos los intentos anteriores siguen inmutables. T12 permanece abierta.
