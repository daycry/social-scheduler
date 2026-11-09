# Workers de cola, healthcheck y plan B

## Workers systemd

`scheduler-worker@.service` es una unidad plantilla: la instancia es la cola que atiende
(`publish`, `poll`, `metrics`, `reports`). Parametros: `--max-time=3600 --max-jobs=500` (el worker se recicla
solo para evitar fugas de memoria), `Restart=always`, `RestartSec=5`. La unidad va sandboxeada
(`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=strict`, `ProtectHome=read-only`): solo escribe en `storage/` y `bootstrap/cache`
(`ReadWritePaths=`). El PHP CLI se fija con `Environment=PHP_BIN=` (mismo valor que `PHP_BIN` en `deploy.sh`).

### Instalacion [VPS]

```bash
sudo cp deploy/systemd/scheduler-worker@.service /etc/systemd/system/
# Ajustar User=, Group=, WorkingDirectory=, ReadWritePaths= y PHP_BIN (p. ej. php8.4) a los reales del dominio en Hestia
sudo sed -i 's#USUARIO#<usuario hestia>#g; s#DOMINIO#<dominio>#g; s#PHP_BIN=php#PHP_BIN=<php8.4>#' /etc/systemd/system/scheduler-worker@.service
sudo systemctl edit --full scheduler-worker@.service   # revisar
systemd-analyze verify /etc/systemd/system/scheduler-worker@.service   # sin errores
sudo systemctl daemon-reload
sudo systemctl enable --now scheduler-worker@publish scheduler-worker@poll scheduler-worker@metrics scheduler-worker@reports
```

Con varios workers sobre la misma cola, exigir MariaDB >= 10.6 / MySQL >= 8.0 (`SKIP LOCKED`);
`php artisan app:health` lo indica. Con una version anterior, un solo worker por cola.

Para exportaciones estáticas con varias páginas, Studio utiliza PHP `ZipArchive` cuando está disponible. Si falta, el empaquetado usa `scripts/studio/archive.py` con el Python configurado por `STUDIO_PYTHON_BINARY` y su biblioteca estándar: no requiere instalar el modelo de imágenes ni sus paquetes. El fallback escribe ZIP64 por bloques, valida archivos del directorio privado y conserva los límites del renderer. Debe poder ejecutarse ese Python desde el proceso PHP; los fallos de empaquetado conservan la exportación en error y no entregan un ZIP parcial. Las pruebas locales no sustituyen la comprobación del VPS a cargo del usuario.

### Operacion

| Accion | Comando |
|---|---|
| Estado | `systemctl status scheduler-worker@publish` |
| Logs | `journalctl -u scheduler-worker@publish -f` |
| Reinicio limpio tras deploy | `php artisan queue:restart` (lo hace `deploy/hestia/deploy.sh`; systemd relanza el worker) |
| Prueba de extremo a extremo | `php artisan app:queue-probe --wait=60` (encola un job por cola y espera a que lo procesen) |
| Jobs fallidos | `php artisan queue:failed` / `queue:retry all` |

## Worker audiovisual de Studio (preparado, sin instalar)

`scheduler-studio.service` es una unidad dedicada a la cola `database/studio`, separada de las colas actuales y del modelo de quitar fondos. Requiere cgroupv2 con `cgroup.kill` (Linux≥5.14), usuario sin privilegios y delegación de su propio subárbol. `MemoryMax=3G` limita todo el worker/guardián/procesos de fase; `MemorySwapMax=0`, `OOMPolicy=kill` y `KillMode=control-group` cierran el árbol completo ante OOM o parada de la unidad. No reserva3GiB al arrancar.

`Delegate=yes` permite crear únicamente subgrupos UUID en la raíz de esa unidad. Por ello su `ProtectControlGroups=no` difiere del worker genérico; los permisos kernel de usuario siguen impidiendo tocar los cgroups de otras unidades. El supervisor verifica propietario, límite heredado y disponibilidad de kill antes de abrir Node. La limpieza usa `cgroup.kill` del subgrupo propio, nunca nombres de procesos ni un kill global. Los workers existentes no cambian.

Antes de instalar, ajustar User/Group/rutas/PHP_BIN y disponer de Node nativo, FFmpeg/ffprobe, Chromium de Playwright y los bundles construidos. El timeout del job es 100 s, inferior al worker de 120 s y al retry_after de 180 s. El árbol de trabajo conecta jobs Laravel y handlers web/API/MCP de MP4/GIF e incluye el diálogo audiovisual. La [QA integral local de T-07/T-11](../../docs/roadmap/2026-11-03-creative-social-suite/testing/t11/final-qa3/report.md) está aceptada; esta plantilla sigue sin instalar y no acredita despliegue. El usuario ejecutará las comprobaciones del VPS; las pruebas WSL del supervisor no las sustituyen. No instalar ni iniciar esta unidad por copiar la documentación de desarrollo.

Para validar la plantilla preparada, `systemd-analyze verify deploy/systemd/scheduler-studio.service` comprueba su sintaxis. No ejecuta el worker ni acredita permisos de la instalación real.

Las pruebas de cgroup son de integración y requieren `STUDIO_AV_CGROUP_TEST=1` dentro de una unidad temporal delegada y limitada. Sin esa opción se omiten explícitamente; no cuentan como aceptación Linux. Un ejecutable Python mínimo verifica el contrato del supervisor/exec; no sustituye una exportación real mediante Node/FFmpeg/Chromium. Las pruebas del VPS siguen a cargo del usuario.

## Healthcheck

1. **Interno / deploy**: `php artisan app:health` (exit 0 = BD, cola, almacenamiento y latido del scheduler correctos, con avisos si hay jobs fallidos recientes; `--json` para scripts;
   `--skip-scheduler` en el deploy porque el latido tarda hasta 1 minuto en aparecer).
2. **Externo**: `GET https://<dominio>/health` con cabecera `X-Health-Token: <HEALTHCHECK_TOKEN>` (generar con `openssl rand -hex 32`; el endpoint no usa sesion y esta limitado a 30 peticiones/min) -> 200 si todo esta bien o solo degradado (`"status":"degraded"`: p. ej. jobs fallidos en las ultimas 24 h, informado en el JSON), 503 si algo esta caido (BD, cola, disco o scheduler),
   404 si el token falta o es incorrecto (o `HEALTHCHECK_TOKEN` vacio). Configurarlo en UptimeRobot / Healthchecks.io cada 5 minutos.
   `/up` (Laravel) solo prueba que PHP responde.
3. **Latido del scheduler**: el cron `schedule:run` actualiza cada minuto una marca en cache; si tiene mas de 180 s, el check `scheduler` falla.

## Plan B: sin systemd (cron con `--stop-when-empty`)

Es la opción que usa el despliegue actual, configurada desde el panel de Hestia (CRON): comandos exactos y explicación en `deploy/hestia/README.md` → 5.1. Las rutas de abajo asumen la app en `app/`; si está en `public_html`, cámbialas.

Si no se puede usar systemd, crear el directorio de locks del usuario (no usar `/tmp`: ruta predecible y compartida) y anadir al cron de Hestia con `flock` para evitar solapes:

```bash
mkdir -p -m 700 ~/.cache/sched
```

```cron
* * * * * cd /home/USUARIO/web/DOMINIO/app && flock -n $HOME/.cache/sched/publish.lock php artisan queue:work database --queue=publish,poll --stop-when-empty --max-time=55 --timeout=120 --tries=3 >> /dev/null 2>&1
*/5 * * * * cd /home/USUARIO/web/DOMINIO/app && flock -n $HOME/.cache/sched/metrics.lock php artisan queue:work database --queue=metrics,reports --stop-when-empty --max-time=280 --timeout=120 --tries=3 >> /dev/null 2>&1
```

Latencia maxima de publicacion = 1 minuto. Es suficiente para el uso personal, pero preferible usar systemd. En cron, usar la ruta absoluta del PHP deseado en lugar de `php`.

## Estado de verificacion

| Elemento | Estado |
|---|---|
| Config de cola/disco, `app:health`, `app:queue-probe`, `/health` | verificado en local (tests `Infra` + worker real sobre MySQL 8.4) |
| `systemd-analyze verify` y arranque/reinicio del worker | pendiente: requiere el VPS |
| Disco `r2` contra Cloudflare R2 real | pendiente: requiere bucket y credenciales (en tests, mock/fake) |
