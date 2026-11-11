# T12: validación focal tras detener las matrices completas

Estado: servidor focal ejecutado y sellado, FOCAL-PASS3/3. [Informe](testing/t12/server-focused-qa1/report.md), nueve descargas reales, PDF5páginas/4figuras inspeccionadas y370fuentes estables pre/postPDF. No autoriza otro intento de los controladores agotados ni cierra T12. Los seis intentos de las dos tandas anteriores conservan sus resultados y sus artefactos. El objetivo vigente del usuario permite continuar con correcciones y pruebas locales independientes hasta completar el roadmap.

## Primer grupo: exportación en servidor

Se ejecuta el escenario público E2E28.02 íntegro en los tres proyectos existentes:1440,375 y768px. El selector del operador será una enumeración fija, no un patrón arbitrario recibido del exterior. La primera autorización habilita únicamente este grupo. El código de los escenarios y todas sus aserciones permanecen completos; no se usan skip, fail, retries ni expectativas rebajadas.

El grupo acredita únicamente las tres ejecuciones reales del escenario servidor: SVG/PNG/PDF, revisión fijada archivada, página no seleccionada, fuente privada, permisos entre marcas/actores, descargas y materialización. No acredita los escenarios de errores/accesibilidad, densidad o las diez variantes de todos los viewports. Sus resultados se llaman FOCAL-PASS/NO-PASS, nunca aceptación global de la matriz ni del roadmap.

## Observación y evidencia

Se mantienen el JSON real de Playwright, blob oficial para trazabilidad posterior, política estricta de red y captura pasiva de archivos entregados. El helper observa tanto el evento como la finalización de la descarga. Las cabeceras no observables por el navegador se contrastan en el servidor propio junto con bytes/SHA; no se inventan eventos de response. La fuente y los archivos de referencia se leen después de la entrega.

Los observadores independientes se aplican a los archivos realmente previstos por este grupo: tres SVG/XML, tres PNG, tres comparaciones de glifos, tres PDF y tres comprobaciones de procedencia raster. Se reutilizan las funciones existentes. Sus ejecutables globales conservan las cotas36/33/30 de la matriz completa; no se cambian para aceptar un subconjunto. El recuento focal rechaza archivos/casos faltantes o extra, errores, skips, flaky, reintentos, abortos y deriva de fuentes.

## Aislamiento y límites

Antes de UUID, seed o servidor: revisión fresca, manifiesto actual completo, build3 vigente, puerto libre y capacidad física conocida≥6GiB. Cada ejecución usa Job agregado3GiB COMMIT con killOnClose y sin breakaway, piso3GiB cada100ms, Node512MiB y PHP256MiB. Sólo runtime ficticio propio y loopback; sin.env, MySQL, R2 ni proveedores reales. Se conserva un único slot pesado.

El grupo tendrá un plazo propio explícito igual o menor que900s, fijado antes de ejecutar. No implica otorgar cuatro plazos de900s a una futura validación global. El presupuesto y el método de una eventual tanda completa se planificarán expresamente después de cerrar los defectos que los grupos focales descubran.

Un grupo dispone de una salida inmutable y no se relanza automáticamente. Al terminar se prueban cierre de procesos/puertos e integridad antes/después; cualquier aborto invalida un verde anterior. El informe declara su alcance parcial. PDF e inspección, si se generan, usan un Job separado tras el cierre del núcleo.

## Continuación

Después del grupo servidor se decidirán, por separado, las validaciones de E2E28.03 y E2E28.04, todavía no alcanzadas en las matrices anteriores. Una futura integración necesita fuentes estables, revisión del protocolo y resultados reales completos. No se suman ensayos antiguos con huellas distintas para inventar12casos ni se transforma un conjunto parcial en verde mediante un JSON reconstruido.
