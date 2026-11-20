# Actividad

Actualizado: 2026-11-17.

Abre **Actividad** desde el menú de tu cuenta. El historial muestra las acciones que ya registra la aplicación, como el uso de herramientas MCP, peticiones de la API y creación o revocación de API keys. No es un registro de cada clic.

Puedes consultar tu actividad personal y la atribuida a marcas de las que eres propietario. La actividad personal de otros usuarios no aparece. Una acción sobre un recurso ajeno que fue denegada solo aparece en el historial personal de quien la intentó.

## Buscar acciones

Selecciona marca, usuario, acción o un intervalo de fechas y pulsa **Filtrar actividad**. Los filtros se combinan. «Siguiente» conserva la selección; «Primera página» vuelve al principio de ese mismo listado. Las acciones más recientes aparecen primero.

Sin seleccionar una marca, las fechas se interpretan y muestran en UTC. Si seleccionas una marca, se usa su zona horaria, incluidos los cambios de horario de verano. «Desde» y «Hasta» incluyen el día completo.

Cada fila muestra fecha, usuario, marca, acción, resultado y canal. No se muestran direcciones IP, identificadores de API keys ni detalles internos del registro.

En una pantalla estrecha puedes desplazar la tabla horizontalmente. Con teclado, enfoca «Historial de actividad» y usa las flechas.

## Conservación

El aviso de la pantalla refleja la configuración `RETENTION_AUDIT_LOGS_DAYS`: por defecto, 365 días. La limpieza programada se ejecuta diariamente, por lo que el plazo no es una garantía de borrado al segundo. Con valor 0 se desactiva la limpieza automática.

## Historial anterior a esta pantalla

Los registros anteriores a esta actualización permanecen en el historial personal. No permiten demostrar de forma segura qué marca se había consultado, aunque el recurso todavía exista.

Las acciones nuevas solo se asocian a una marca cuando la aplicación ha comprobado ese contexto. Las llamadas sin marca, sobre varias marcas o sin contexto verificable permanecen en el historial personal. También ocurre con los accesos denegados. Esto no significa que la acción no estuviera relacionada con una marca: puedes encontrarla quitando el filtro de marca.

Cuando una entrada se ha atribuido a una marca, esa asociación se conserva aunque después se elimine el recurso. Al eliminar una marca, la asociación se borra y las entradas se mantienen en el historial personal del actor.

Las acciones de otros usuarios sobre tus marcas no se atribuyen a la marca: quedan en el historial personal de quien las realizó y no aparecen en el tuyo.
