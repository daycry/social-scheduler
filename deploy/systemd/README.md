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

### Operacion

| Accion | Comando |
|---|---|
| Estado | `systemctl status scheduler-worker@publish` |
| Logs | `journalctl -u scheduler-worker@publish -f` |
| Reinicio limpio tras deploy | `php artisan queue:restart` (lo hace `deploy/hestia/deploy.sh`; systemd relanza el worker) |
| Prueba de extremo a extremo | `php artisan app:queue-probe --wait=60` (encola un job por cola y espera a que lo procesen) |
| Jobs fallidos | `php artisan queue:failed` / `queue:retry all` |

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
