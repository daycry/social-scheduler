# T12 — corrección de búsqueda y preparación posterior a QA1

Revisión A3/B3/D3: **sin defectos introducidos pendientes** en el delta de 14 entradas. C no aplica según selector. SHA actuales y copias de revisión coinciden. El ledger contiene la tabla completa de criterios y conserva las aprobaciones anteriores.

La búsqueda inicial omitirá el parámetro opcional vacío; una consulta no vacía mantiene su texto literal. La causa del 422 quedó probada en el middleware, sin cambiar backend ni MCP. Regresión RED/GREEN y suite del cliente 8/8. La preparación de QA2 cambia selectores por roles accesibles, registra explícitamente su aislamiento de fuentes y exige negativos HTTP/cancelaciones afirmados uno a uno. No elimina errores de aplicación. Se exigirán 15 resultados completos en tres anchos y tres axe limpios.

El revisor B ejecutó 19 sondas puras; D verificó un caso acotado de 15 estados/45 lecturas de fixture (6.930 bytes) sin problemas. Esas sondas no acreditan la interfaz de la aplicación.

## Diagnóstico de descargas

La sonda propia loopback comprobó una descarga real de attachment mediante enlace download: 23 bytes y SHA correctos, sin fallo ni requestfailed. Navegador y servidor cerrados; proceso exit 0; Job Windows 3 GiB COMMIT, pico 219.877.376 bytes. No utilizó aplicación, env, BD ni proveedores.

La misma observación no recibió el evento page.response del attachment. El observador de descarga heredado de la spec esperaba ese evento; se corrige y verifica antes de autorizar QA2, conservando estado, cabeceras, bytes y SHA reales. Esta observación no es un defecto introducido por el delta revisado ni un PASS de producto.

**Aceptación real de la interfaz pendiente. T12 permanece abierta; 8/23 tareas cerradas.** QA1 interrumpida conserva su NO-VERDE y no se reconstruyen resultados ausentes. No se han ejecutado pruebas externas ni la auditoría final de Claude CLI.
