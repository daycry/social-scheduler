# T12: errores y accesibilidad, validación focal

Estado histórico: tres ejecuciones independientes NO-PASS conservadas. El supuesto de aborto físico de este harness se contradijo con el contratoC2 y la [sondaHTTP real3/3](testing/t12/hold-runtime-diagnostic/report.md). El protocolo vigente para corregirlo es [consumidor-local](t12-chart-consumer-validation-contract.md): conserva C2 y todos los checks de UI/bytes/caché, con recibos nuevos explícitos. Las secciones siguientes describen las ejecuciones anteriores, sin reetiquetarlas. Servidor FOCAL-PASS3/3 conserva su evidencia; seis matrices completas agotadas intactas. T12 sigue abierta.

## Escenario íntegro y salida

Ejecutar únicamente E2E28.03 del spec público, sin modificar su cuerpo, en los tres proyectos actuales: desktop1440, mobile375 y mobile-360768px. Selector fijo enumerado, un worker, retries0, maxFailures1, timeout de caso240s intacto. Nueva salida inmutable `storage/framework/testing/t12-chart-errors-qa1/out/errors-execution1`; ningún relanzamiento automático. Grupos client/server/dense rechazados antes de capacidad o UUID.

El escenario comprueba tabla accesible y lexemas originales, proporciones negativas inválidas, safe/approximate para9007199254740993, estilo guardado/editado, cancelación y ausencia de aplicación tardía, teclado/foco visible y restaurado, Inspector y revisión histórica vacíaB4 mientras la revisión actual contiene filas.

## Evidencia obligatoria

JSON original de Playwright y blob oficial, exactamente3tests E2E28.03 de los proyectos previstos, un resultado passed por test, sin retries/flaky/skips/interrupciones/errores globales. Cualquier caso no ejecutado conserva NO-PASS; no se completan registros sintéticos ni se mezclan huellas distintas.

Por cada caso completo:

- Un viewport real del proyecto esperado, sin duplicados.
- Tres recibos axe (`chart-modal`, `inspector`, `empty-historic`), con passes/reglas presentes, violations e incomplete vacíos.
- Tres capturas reales PNG (`errors-table-modal`, `inspector`, `empty-historic`), firma/tamaño/SHA; inspección visual explícita de las nueve.
- Indicador de foco con outline o shadow realmente visible, además de las aserciones DOM originales.
- Una respuesta GET real retenida del dataset seleccionado: captura200 con bytes/SHA, cancelación del cliente y liberación cancelada sin deadline, en orden y con identidad/bytes/SHA coherentes. Los tres recibos deben coincidir con el journal HTTP pasivo. Ninguna respuesta de éxito simulada.
- Red sin issues y exactamente un aborto intencional confirmado por método/ruta del hold. Sin ampliar allowlists o ignorar otros abortos.
- B4 con current_revision2, historical_rows0, referencia antes/después idéntica y fijada a1, estilo idéntico, category=category y series=[s0].
- font-responses presente y vacío: este escenario usa fuente local y no sube tipografía privada.

El recuento es adicional a las aserciones completas del escenario; no las sustituye. No corresponde ejecutar observadores de SVG/PNG exportado, glifos privados, PDF o materialización del servidor. Los ejecutables globales conservan sus cardinalidades originales.

## Aislamiento, revisión y presupuesto

Revisión fresca del nuevo operador, manifiesto flat completo y build3 vigente antes del nativo. PHP256MiB, Node512MiB, runtime propio SQLite/almacenamiento/cachés con prueba anterior al seed, entorno OS permitido sin .env, sólo loopback y ninguna cuenta/proveedor/DB real. Un slot pesado.

Job agregado3GiB COMMIT con killOnClose y sin breakaway; preflight físico conocido≥6GiB antes de UUID/servidor y piso3GiB cada100ms. Plazo único900s incluyendo núcleo, gates, recibos, hashes y clausura; Playwright global850s. No se detiene el watchdog antes de los gates. Estimación60–180s aún no medida; no es un resultado.

Cierre separado sólo después de PID del núcleo muerto y Job cerrado, con guard limitado al tiempo restante del mismo plazo900s y prueba actual de8080IPv4/IPv6libres. Cualquier aborto o deriva invalida un verde previo. PDF, si se genera, se ejecuta después en Job separado. Evidencias originales y fallos permanecen inmutables.

Antes de READY: pruebas puras VFS de rechazo por registros/recibos incompletos, errores, abortos extra, incoherenciaB4/hold/SHA; AST de conservación del escenario/reporters; colección real3 sin setup/PHP/DB/browser y con storage0. No confundir esos checks con ejecución E2E.

## Aceptación y continuación

FOCAL-PASS sólo acredita E2E28.03×3 con los recibos anteriores, integridad y cierre. No acredita densidad, variantes cliente, exportaciones, AV, otras familias o el roadmap global. E2E28.04 tendrá su contrato y revisión propios. Los resultados históricos no se agregan para inventar12casos. TítuloY pequeño continúa pendiente de corrección independiente.

## Regresión tras la corrección de la carrera, ejecución2

La primera salida queda sellada NO-PASS1casofallido/2noejecutados. Se probó y corrigió la generación compartida entre viewData y preview200ms; REDVue→GREEN1+54vecinos y revisión A1/B1 limpias. Build4 ha terminado sin deriva:62inputs/77assets,2,1324s y picoCOMMIT1183080448B bajo Job3GiB. Build3 permanece histórico.

Se prepara únicamente NUEVO operador `t12-chart-errors-qa2`, salida inmutable `out/errors-execution2`, build4 y frase propia `revision T12 focal errors correccion aprobada; ejecuta tres viewports una vez`. No se modifica la salida anterior ni el cuerpo público del escenario. Recuento, tres viewports, cardinalidades, red/aislamiento,49checks heredados y guard900s/3-6-3/100ms/PHP256/Node512 conservan sus criterios. Sólo se vuelven a comprobar los enlaces nuevos y la colección real; los checks heredados se identifican como tales, sin contarlos como nuevas ejecuciones.

Revisión fresca del adaptador y de sus dependencias actuales, manifiesto flat completo con las fuentes revisadas del fix y assets build4 antes del nativo. Cualquier fallo se conserva sin relanzamiento automático. El watchdog y finalize comparten el mismo plazo900s. No ejecutar dense, client o server ni ampliar los controladores globales agotados.

## Regresión3 tras el diagnóstico de contraste

Errors2 pasó la lectura de tabla y falló en el primeraxe por16nodos incompletos. Los colores no se declararon insuficientes. La sonda nativa reconstruida separó imagen de fondo y fondo de datos heredado fuera del rectángulo del diálogo; ambos candidatos juntos eliminan las incidencias de esa fixture, con sus limitaciones publicadas. La aplicación conserva todas las reglas y rechaza cualquier incomplete.

Se prepara sólo NUEVO operador `t12-chart-errors-qa3`, salida inmutable `out/errors-execution3`, build5 y frase `revision T12 focal errors contraste aprobada; ejecuta tres viewports una vez`. Son tres adaptaciones del ejecutor; las otras cuatro fuentes auxiliares permanecen idénticas. No se modifica el cuerpo público de E2E28.03 ni se omiten tabla, teclado, foco, cancelación real, precisión, Inspector o B4.

Antes de ejecutar: revisión fresca del CSS/wrapper, build5 real sin deriva, preparación con colección3/storage0 y controles de cableado nuevos, revisión del adaptador y manifiesto flat completo actual. Los39checks heredados no se contabilizan como reejecutados. Mismo Job3GiB/6preflight/3floor/100ms/PHP256/Node512 y plazo900s compartido con cierre. Una salida y sin reintento automático; si falla se conserva y se vuelve a diagnóstico y planificación explícita. No se amplían los controladores globales ni se agregan JSON de huellas distintas.
