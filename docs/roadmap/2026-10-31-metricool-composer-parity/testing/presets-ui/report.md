# Ajustes del compositor — T-11/T-12

Fecha: 2026-11-01. Paneles globales y por red, colaboradores e IA de Instagram, título de Facebook y errores plegables. [Inventario observado y uso](../../../../guias/ajustes-compositor.md). No se declara paridad completa con Metricool.

## Verificación actual

- PHP: **2.511 tests, 14.763 aserciones, cero fallos/errores/skips**. JUnit en `raw/phpunit.xml`.
- Build Vite correcto, log en `raw/build.txt`; TypeScript exit 0 tras los cambios finales (sesión/chunk `7756b6`).
- Pruebas focales de web, servicio, MCP y publicadores: 21 tests/104 aserciones correctos antes de la suite integral.
- La regresión E2E final pasa **54/54**, cero fallos/flaky/skips/errores: presets, audio Instagram/TikTok y regresión de errores, fecha y notas publicadas en 1440×900, 375×812 y 360×740.
- Revisión A+B+D, intento 2, cerrada sin gaps pendientes; el único Minor documental fue corregido y releído. Snapshot inmutable de 27 fuentes en `review-attempt2.patch` y hashes `review-attempt2-sources.json`.

## RED y correcciones

T-11 empezó con RED por ausencia de Ajustes globales. El primer review detectó clipping real del calendario por overflow-hidden: se eliminó el clipping y se conservan radios en la cabecera. E2E comprueba con hit test que Listo recibe clic después de entrar en la ventana visible. La geometría a 360 px mostró formulario de 375 px dentro de viewport 360: se fijó min-w-0 al item del grid. Los tres escenarios nuevos pasan en 360 px, incluidos enlaces de error y foco del campo de fecha.

T-12 tiene RED de opciones ausentes en servidor/publicador y de colaboradores/summary en UI. El título de Facebook faltaba en finish; ahora se comprueba ese paso. Una ejecución intermedia tenía dos fallos por un literal de test mal codificado (T?tulo), corregido sin cambiar producción. El primer rojo de persistencia usaba una fixture de Reel sin vídeo válido; la fixture final contiene vídeo ficticio y conserva las validaciones.

## Comportamiento y límites

`details/summary` conserva valores montados y admite teclado. Los enlaces de error abren sus paneles antecesores antes de enfocar. Colaboradores máximo 3 e IA solo en imagen/Reel/carrusel, enviados solo al padre de carrusel; IA no se recuerda. Título Facebook máximo 255, independiente del texto. Web/MCP/worker validan antes de HTTP. Los tests usan datos y proveedores ficticios; no se enviaron publicaciones ni invitaciones reales.

T-06/CA-10 permanecen abiertos, a cargo del usuario. [Ledger](../../tasks.md), [smoke real](../smoke-real.md). Los informes anteriores de audio y buscador se conservan como históricos.

## Cierre y evidencia reproducible

`raw/playwright.json` conserva el resultado de la ejecución desechable completa y `raw/qa-gate.json` el veredicto **VERDE**. Run original: `run-general-6abb0760-9843-4295-ac2c-f0f5fc4fd434`. `validation-summary.json` fija hashes de fuentes, build y evidencias. La suite PHP final también acredita el invariante que prohíbe duplicar listas fijas de redes; la UI usa los selectores compartidos.

| Vista inspeccionada | Ajustes | Errores |
|---|---|---|
| Escritorio 1440×900 | [Captura](screenshots/composer-presets-desktop.png) | [Captura](screenshots/composer-errors-desktop.png) |
| Móvil 375×812 | [Captura](screenshots/composer-presets-mobile.png) | [Captura](screenshots/composer-errors-mobile.png) |
| Móvil 360×740 | [Captura](screenshots/composer-presets-mobile-360.png) | [Captura](screenshots/composer-errors-mobile-360.png) |

La revisión independiente leyó 27 fuentes y comprobó sus hashes. B ejecutó 29 límites PHP y poda Node; D ejecutó 100.000 iteraciones de opciones acotadas en 266,1 ms. No son medidas del proveedor ni sustituyen el smoke real.

El manifiesto distingue hashes de bytes locales y hashes de blobs de Git para que la normalización de saltos de línea en Windows no impida contrastar la evidencia tras clonar. El log de build se conserva en UTF-8/LF con sus mensajes y resultado. Los snapshots de diff conservan espacios de contexto literales, excluidos del chequeo de whitespace.
