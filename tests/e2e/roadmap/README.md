# Pruebas integradas del roadmap original (T-43)

Ejecutar desde la raíz del proyecto, con las dependencias PHP/Node y Chromium de Playwright ya instaladas:

```powershell
npx playwright test -c playwright.roadmap.config.ts
```

Para depurar un caso en escritorio:

```powershell
npx playwright test -c playwright.roadmap.config.ts --project=desktop --grep E2E-05
```

La prueba de vídeo requiere `ffmpeg` y `ffprobe`; genera y sube un MP4 real de 901 segundos para comprobar el límite de 900 del reel. La prueba de informes usa `pdftotext` para comprobar el contenido del PDF descargado. Estas herramientas deben estar en `PATH` (o configuradas en el caso de FFmpeg). El runner no instala herramientas ni contacta con proveedores sociales.

Cada ejecución crea `storage/framework/testing/t43/run-<fecha>-<pid>/` con:

- `db.sqlite`: base nueva y migraciones de la aplicación.
- `media/`, `cache/`, `views/` y `logs/`: almacenamiento de esa ejecución.
- `externals.json`: registro persistente de la red simulada, compartido entre procesos web y de cola.
- `results.json`: resultado Playwright apto para la puerta `qa-gate.py` del plugin.
- `artifacts/`: capturas, trazas y PDF/CSV descargados por las pruebas.

Los artefactos permanecen después de la ejecución para la revisión independiente. Los escenarios restablecen únicamente sus usuarios y filas dentro de esta base desechable.

El router PHP de pruebas se escucha en `localhost:8081`, rechaza clientes fuera de loopback e inyecta conectores simulados después de arrancar la aplicación. La CLI rechaza otros entornos, bases de datos y rutas fuera del directorio desechable. Ambos cargan el entorno desde el directorio desechable, sin leer el `.env` real. Las solicitudes HTTP de backend no simuladas se rechazan. Los controladores, servicios, validadores, jobs, dispatcher, worker y generadores de informes son los reales de la aplicación.

Este runner no carga la sesión demo ni los bookends del runner general. `playwright.config.ts` excluye `roadmap/`; usar siempre la configuración específica para estos casos. Los manuales con cuentas reales y la aprobación visual del usuario siguen siendo comprobaciones separadas.
