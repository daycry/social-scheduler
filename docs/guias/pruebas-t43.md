# Ejecutar las pruebas aisladas de T-43

Última actualización: 2026-10-31.

T-43 valida los doce flujos originales de Social Scheduler y los bloques MCP API-01 a API-03. El [plan de pruebas](../roadmap/2026-10-19-social-scheduler/test-plan.md) define sus aserciones; el [ledger](../roadmap/2026-10-19-social-scheduler/tasks.md) registra el resultado verificable.

## Qué configuración elegir

| Configuración | Entorno | Comando |
|---|---|---|
| `playwright.roadmap.config.ts` | SQLite nueva por ejecución, conectores simulados y usuarios `e2e-sec-roadmap-*`; servidor en `http://localhost:8081` (`E2E_ROADMAP_PORT` lo cambia) | `npm run test:e2e:roadmap` (equivale a `npx playwright test -c playwright.roadmap.config.ts`) |
| `playwright.config.ts` | Batería general; SQLite y recursos desechables, cuatro usuarios ficticios y servidor propio en `http://localhost:8080`, o `E2E_PORT`, o un puerto libre si 8080 está ocupado | `npm run test:e2e` |

Para validar T-43, elige la configuración explícita `playwright.roadmap.config.ts`. La batería general usa ahora su propio adaptador aislado en `tests/e2e/isolated/`: tres usuarios para los escenarios mutables y un cuarto de solo lectura con marca y cuentas ficticias. Sus bookends operan únicamente sobre la SQLite desechable. No necesita las credenciales de la cuenta demo.

La configuración general excluye `**/roadmap/**` y `**/isolated/**`. Conserva sus 498 casos originales y añade seis regresiones de fecha (dos escenarios por los tres proyectos): el inventario actual contiene 504 casos. Las guardias de su adaptador se ejecutan por separado y no incrementan ese conteo.

## Preparar el entorno

Usa las dependencias del proyecto y los binarios siguientes:

| Dependencia | Uso | Fuente |
|---|---|---|
| PHP ≥ 8.3 y extensión `pdo_sqlite` | Aplicación, CLI de fixtures y BD desechable | `composer.json`, `tests/e2e/roadmap/bootstrap.php` |
| Extensión GD | Imágenes de muestra | `tests/e2e/roadmap/cli.php` |
| Node 22 y dependencias npm | Playwright y assets | `package.json`, `.github/workflows/ci.yml` |
| Chromium de Playwright | Navegador de los tres proyectos | `playwright.roadmap.config.ts` |
| `ffmpeg` y `ffprobe` accesibles por el proceso | MP4 real de muestra y comprobación de sus metadatos | `tests/e2e/roadmap/cli.php`, `config/media.php` |
| `pdftotext` accesible por el proceso | Comprobar el contenido del PDF descargado | `tests/e2e/roadmap/roadmap.spec.ts`, E2E-11 |

Con las dependencias instaladas, compila los assets:

```bash
npm run build
```

Comprueba el descubrimiento sin arrancar servicios:

```bash
npx playwright test -c playwright.roadmap.config.ts --list
```

Ejecuta primero un proyecto durante la depuración:

```bash
npx playwright test -c playwright.roadmap.config.ts --project=desktop
```

La ejecución sin `--project` selecciona escritorio, móvil de 375 px y móvil de 360 px. El runner arranca y detiene su propio servidor; no necesita un worker sobre la cola de uso real.

## Cómo aísla los datos

`playwright.roadmap.config.ts` crea `storage/framework/testing/t43/run-<fecha>-<pid>/` y configura allí `db.sqlite`, las vistas compiladas y la caché de configuración. `bootstrap.php` comprueba ese entorno antes de usar la base.

El router `server.php` y la CLI `cli.php` inyectan conectores simulados después del bootstrap de Laravel. No registran esos conectores en los providers de producción. `Http::preventStrayRequests()` rechaza peticiones HTTP no simuladas.

La CLI migra únicamente su SQLite desechable. Los jobs de renovación, publicación, snapshots e informes y el worker usan esa base. Media, caché, sesiones y correo también pertenecen al entorno de prueba. El correo usa el transporte `array`; los tokens de recuperación de los usuarios desechables quedan en `mailbox.jsonl` dentro de la ejecución.

`harness.spec.ts` comprueba cuatro condiciones: rechazo de `APP_ENV=production`, rechazo de una BD fuera del directorio de la ejecución, creación de usuarios dedicados sin cuentas reales y aislamiento de caché, límites y locks entre dos ejecuciones. La última inspecciona los almacenes reales resueltos por Laravel antes de escribir y comprueba la persistencia entre procesos de un mismo run. Los escenarios funcionales tienen sus propias aserciones.

El adaptador general crea una carpeta `storage/framework/testing/t43/run-general-<UUID>/`. Su launcher entrega a HTTP y a la CLI solo un manifiesto de variables ficticias, con las rutas de BD, almacenamiento, configuración, sesiones, caché, locks y PsySH dentro de esa carpeta. Rechaza `E2E_NO_SERVER`, una URL diferente de `http://localhost:8080` y recursos ajenos. Los conectores y las peticiones externas se simulan; el simulador Bluesky conserva el contrato del formulario de credenciales real. La clave de Giphy queda vacía para el escenario negativo; los positivos inyectan sus simulaciones existentes.

Sus tres guardias reales se ejecutan con `npx playwright test -c tests/e2e/isolated/guard.config.ts`. Comprueban conservación de una DEMO ficticia protegida y otro usuario ajeno, rutas efectivas iguales por HTTP y CLI, recursos fuera del run rechazados y aislamiento de caché/límites/locks entre dos ejecuciones con persistencia entre procesos del mismo run. El resultado de estas guardias no sustituye la batería general.

## Dónde encontrar los resultados

Cada directorio `run-*` conserva:

| Archivo o carpeta | Contenido |
|---|---|
| `results.json` | Reporte JSON de Playwright para esa ejecución |
| `artifacts/` | Capturas, descargas adjuntas y trazas de fallos |
| `logs/laravel.log` | Excepciones de la aplicación de prueba |
| `db.sqlite` | Datos de la ejecución desechable |
| `mailbox.jsonl` | Recuperación de contraseña de los usuarios de prueba |

El [ledger de T-43](../roadmap/2026-10-19-social-scheduler/tasks.md) identifica qué ejecución respalda cada resultado. Un `results.json` antiguo o los tests descubiertos con `--list` no acreditan una nueva pasada completa.

## Qué está verificado

La suite PHP local final del 2026-10-31 pasa **2.461 tests y 14.549 aserciones**, tras añadir tres casos literales de Bluesky. El JUnit está en `storage/framework/testing/t43/phpunit-final-p3.xml`, con copia en `testing/raw/` del plan. Se conserva además la primera pasada de 2.458 tests. La revisión focalizada de aislamiento, cifrado y API keys pasa **111 tests y 573 aserciones** tras remediar el flash de una API key sin cifrar.

La QA independiente del 2026-10-31 pasa **57 tests**, sin fallos, flaky ni omitidos: doce E2E, tres bloques API y cuatro comprobaciones del harness en cada uno de los tres proyectos. `qa-gate.py` devuelve `VERDE`, exit 0. El [informe](../roadmap/2026-10-19-social-scheduler/testing/report.md), el JSON conservado en su carpeta `raw/` y el ledger identifican la ejecución que respalda el resultado. Los manuales con cuentas reales y VPS siguen pendientes.

La regresión general actual del 2026-10-31 termina **VERDE automático**: **502 passed y dos skips previstos de 504 casos**, sin fallos, flaky, interrumpidos ni casos sin ejecutar por errores. Playwright y `qa-gate.py` devuelven exit 0. Escritorio pasa 168 casos; cada móvil pasa 167 y omite el shell de escritorio. Los dieciséis archivos controlados conservan sus hashes antes y después de los 42,6 minutos de ejecución. El [informe general](../roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.md), su [PDF](../roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.pdf) y el JSON exacto de `attempt-2/raw/` conservan la evidencia.

Se mantiene la pasada histórica del 2026-10-27 (496 pasados y dos omitidos). El primer intento aislado del 2026-10-31 terminó **NO-VERDE**: 442 passed, tres fallos en la aserción de fecha del compositor y 53 skipped, de los que 51 no se ejecutaron por la dependencia serial y dos eran los históricos del shell móvil. El [informe rojo](../roadmap/2026-10-26-metricool-parity-3/testing/report.md) y su JSON no se sobrescriben.

La adaptación aislada pasa tres guardias y muestras de 44 y 32 casos en escritorio. La corrección de la aserción conserva la inaccesibilidad de ayer: dentro del mes debe estar deshabilitado; en el mes anterior debe estar ausente y la navegación a ese mes, deshabilitada. Añade dos escenarios reales por tamaño y conserva los 498 originales: **504 casos en 21 specs**. Antes de la nueva QA general pasaron el mínimo de nueve casos y el compositor completo de 81. Estos resultados tienen sus propios JSON; no sustituyen ni se mezclan con los 57 casos de T-43.

Las pruebas con cuentas reales y VPS continúan en [Pendientes manuales](../referencia/pendientes-manuales.md). El runner aislado no verifica los smokes de Bluesky o Threads. La aprobación de diseño y secciones de T-41 la ha dado el usuario el 2026-10-31 sobre el PDF de muestra; la validación con métricas reales sigue separada.
