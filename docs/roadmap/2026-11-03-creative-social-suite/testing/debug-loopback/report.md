# Rechazos TCP del runner local: diagnóstico y corrección

Este diagnóstico afecta al servidor desechable de Playwright en Windows. No acredita un defecto del servidor de producción ni altera la lógica de publicación, métricas o autorización.

## Reproducción mínima

Las dos tandas de QA conservaron los errores de consola: primero 9/18 y después 14/18 casos pasados. La segunda usó un build estable, descartando la compilación concurrente como explicación suficiente. Hubo fallos tanto en precargas como en un PATCH de guardado y un GET de dashboard.

La reproducción reducida fue el caso `Studio: design persists...`, proyecto `mobile-360`, con `--repeat-each=3 --max-failures=1`, sin reintentos ni filtros de errores. El primer caso falló con `ERR_CONNECTION_REFUSED` (`6bd136`, run `run-general-f58f9f10-68aa-4044-8a63-413ae13e6f86`). La instrumentación NetLog se guardó fuera de Git y solo se conserva su [resumen de transporte](before.json).

## Aislamiento y primera hipótesis

PHP escuchaba únicamente en `[::1]:8080`. Una petición al mismo archivo con curl IPv4 falló y con IPv6 obtuvo HTTP 200. NetLog registró 346 conexiones IPv6 y un intento **127.0.0.1:8080**, source 1484, que terminó en **net_error -102**; la petición asociada era `NetworkChip-D2R70ZI3.js`. No era un error de media privada ni una respuesta 4xx del controlador.

Se fijaron listener y resolución de Chromium al mismo IPv4, conservando el origen `http://localhost:8080` y el RP ID de passkeys. Esto eliminó el rechazo por familia equivocada, pero no resolvió todo: la segunda repetición falló con **ERR_ADDRESS_IN_USE / -147**, aun conectando siempre a IPv4. [Resumen de esa condición](after.json). Por ello no se dio por terminada la corrección con el cambio de IP.

## Segunda hipótesis y prueba

Las respuestas de assets servidas por PHP tenían `Connection: close`: 195 observadas en la traza reducida. Un recorrido abrió 351 sockets TCP del navegador. La condición comprobable era que el servidor de desarrollo creaba una conexión corta por recurso, y esta carga de transporte producía fallos locales. No se atribuye al agotamiento de todo el rango de puertos: se leyó un rango de 16384, pero no se midió su ocupación completa.

El runner ahora usa un frontend HTTP local con keep-alive para archivos públicos y transmite las rutas de aplicación a un PHP propio en un puerto efímero de 127.0.0.1. El navegador conserva localhost:8080, el Host original, cookies, cuerpo y respuestas de Laravel. Solo se sirven archivos dentro del directorio público real; no se sirve código PHP como asset y el listener es de loopback.

La prueba controlada pide veinte assets y exige **un solo socket**, además de comprobar POST, Host y Set-Cookie. El mismo E2E móvil se repitió tres veces: **3/3 correctos** (`b36841`). NetLog del último recorrido pasó a **6 conexiones TCP, cero errores**: [resumen](keepalive.json). No se deshabilitaron prefetch, errores de consola, guardas de aislamiento ni aserciones del producto.

## Revisión del transporte y regresión

La revisión independiente del frontend detectó otro caso: si PHP interrumpía una respuesta después de sus cabeceras, el cliente podía quedarse esperando. La prueba nueva obtuvo `hanging` en lugar de `aborted` (**RED `1c7a5f`**). El frontend propaga ahora abortos/errores al cliente y cancela el upstream si el navegador cierra; antes de cabeceras devuelve 502. **GREEN `d4605e`: 3/3**, incluyendo keep-alive, corte de streaming y guarda de aislamiento.

Código: [frontend](../../../../../tests/e2e/isolated/http-server.cjs), [pruebas](../../../../../tests/e2e/isolated/http-server.test.cjs), [lanzador](../../../../../tests/e2e/isolated/serve.cjs), [configuración](../../../../../playwright.config.ts). La configuración NetLog temporal no forma parte de la aplicación ni del runner final.

La tercera tanda amplia pasó **18/18** con el primer frontend. Tras el arreglo de interrupciones se ejecuta otra tanda independiente sobre la última versión; el resultado canónico vive en [QA final](../qa-final/report.md), que conserva los intentos previos y su gate real. Una tanda anterior roja no se convierte en verde borrando errores del reporte.
