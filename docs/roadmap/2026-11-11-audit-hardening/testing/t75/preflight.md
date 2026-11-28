# T-75: verificación de ejecución previa al cierre

Estado: implementación candidata aislada; **revisión final Claude Opus 5.5 pendiente** por cuota, sin cierre ni integración.

- Pest completo: **4287 passed, 9 skipped, 28817 assertions**.
- Node completo final: **778/778**, sin fallos.
- TypeScript estricto: cero errores; Pint, ESLint focal y build de producción correctos.
- Navegador: **13 passed, 2 skipped**, cero fallos y cero flaky; `qa-gate` VERDE. Las dos omisiones son el enlace del sidebar de escritorio en los dos proyectos móviles.
- SQLite y MySQL 8.4.3 aislado: **25 pruebas focales, 215 assertions** cada uno. Última medida local de consultas: 60.000 filas, 20.000 visibles, seis consultas, **23,62 ms**; no es una garantía de capacidad.
- Se volvieron a verificar los SHA-256 de los **1729 archivos de código y configuración** de la copia ejecutada después de finalizar navegador y Node: ninguna diferencia.

El primer navegador reveló que la copia de CI carecía de Tailwind/PostCSS. Se restauraron las configuraciones reales; no se cambió el producto para compensarlo. La siguiente ejecución detectó con axe una tabla desplazable sin foco de teclado. Se añadió una región etiquetada y enfocable con indicador de foco; el E2E verifica el desplazamiento con ArrowRight. La repetición final pasó en escritorio, 375 y 360 px.

La primera revisión real de Claude detectó una atribución de marcas inferida e insegura; se eliminó toda inferencia y todo backfill. Ahora las operaciones aportan únicamente contexto comprobado, el registrador vuelve a comprobar su propiedad y los registros antiguos/sin contexto permanecen personales. Las referencias ambiguas no se muestran. La prueba con MCP real reprodujo también una colisión entre marcas del mismo propietario antes de corregirla.

Los recibos de las revisiones anteriores y de la limitación de cuota se conservan en TEMP. El paquete final pedirá criterios de aceptación, corrección, seguridad, rendimiento y **calidad del código**. La cuota no constituye aprobación. No se ha usado un revisor sustituto.

`verification.json` contiene rutas exactas, recuentos, SHA del reporte y límites; `source-hashes.json` identifica los archivos ejecutados.

## Recuperación de esquema — 2026-11-18

MySQL 8.4.3 desechable verificó down/up de la migración: se conserva la fila de auditoría, desaparece y reaparece brand_id, se restauran los tres índices y la FK vuelve a dejar brand_id nulo al borrar la marca. Tras reaplicar, la fila histórica sigue personal; no hay backfill por inferencia. La instancia se apagó en finally. Resultado exacto en migration-recovery.json. La revisión final de Claude se ha reintentado tras medianoche, con esfuerzo alto y el modelo exigido; sigue en ejecución, sin veredicto aún.

## Correcciones de revisión verificadas — 2026-11-18

La revisión real R2 encontró un encabezado con U+FFFD (Important), estado mutable de auditoría de API, atribución inconsistente de errores MCP, DDL frágil dentro de un test y cobertura incompleta (Minor), además de pedir una medida con poca visibilidad. Todos se trataron con evidencia en el ledger. El nuevo test de encabezado falló realmente antes del fix; la prueba de estado API y tres estados HTTP MCP también fallaron antes del fix.

Resultado final tras corregir: **4294 passed, 9 skipped, 28843 assertions** en Pest completo; **32/241** focales en SQLite y MySQL; **778/778** Node; tipado, estilo y build verdes; navegador **13 passed, 2 skipped, 0 failed, 0 flaky**, qa-gate VERDE, incluida la nueva aserción en los tres tamaños. Los 1729 archivos congelados siguen idénticos después del navegador. No quedan caracteres de reemplazo en ninguno de los 21 archivos del candidato.

Falta el cotejo independiente de Opus sobre las correcciones antes de integrar o cerrar. La tabla de revisión y los recibos distinguen el resultado de R2 de ese veredicto pendiente.
