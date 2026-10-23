# Despliegue en HestiaCP

Runbook para publicar Social Scheduler en el VPS con HestiaCP. Todo lo versionable esta en `deploy/`.
Los pasos marcados **[VPS]** requieren acceso SSH/root y se ejecutan a mano una unica vez (M-04).

## 1. Prerrequisitos [VPS]

- HestiaCP con Nginx + PHP-FPM 8.4 (u 8.5) y MariaDB >= 10.6 / MySQL >= 8.0.
  Comprobar: `mysql --version` y `php -v`. Sin `SKIP LOCKED` (MariaDB < 10.6 / MySQL < 8.0) la cola `database` no admite varios workers.
- Extensiones PHP: `mbstring intl pdo_mysql bcmath gd curl xml zip`, y en el PHP de consola `pcntl` fuera de `disable_functions` (lo necesita `queue:work`; ver 5.1).
- Composer 2 y Node 22: `deploy.sh` compila los assets (`npm ci && npm run build`) y no da el deploy por bueno sin `public/build/manifest.json` (sin el, todas las paginas Inertia darian 500). Con `SKIP_BUILD=1` se omite la compilacion si el build llega por otro medio, pero el manifest sigue siendo obligatorio.
- Git y acceso de lectura al repositorio (deploy key).
- `ffprobe` (paquete `ffmpeg`) en el PATH del usuario de PHP-FPM y de los workers: se usa para leer duracion, fps y codec de los videos subidos (`FFPROBE_BINARY` si esta en otra ruta). Sin el, los videos se rechazan al subirlos.
- Media: **disco local** (`MEDIA_DISK=local`, por defecto en `.env.production.example`; ver `docs/modulos/despliegue.md` → Almacenamiento de media) o R2. Solo con R2: CORS del bucket (subida directa del navegador, T-17): permitir `PUT` desde el dominio de la app, cabeceras `Content-Type` y exponer `ETag` (lo necesita el multipart). Sin esto la subida falla en el navegador aunque las URLs prefirmadas sean correctas.

## 2. Dominio, BD y TLS [VPS]

```bash
# Dominio con plantilla PHP-FPM (docroot -> public/)
v-add-web-domain <USUARIO> <DOMINIO>
v-add-letsencrypt-domain <USUARIO> <DOMINIO>        # TLS Let's Encrypt
v-change-web-domain-tpl <USUARIO> <DOMINIO> laravel  # plantilla Laravel (si no existe, ver 2.1)
v-change-web-domain-proxy-tpl <USUARIO> <DOMINIO> default
v-add-database <USUARIO> scheduler scheduler <PASSWORD>   # crea <USUARIO>_scheduler
v-add-web-domain-redirect <USUARIO> <DOMINIO> https://<DOMINIO> 301   # opcional: forzar HTTPS
```

### 2.1 Plantilla Laravel

Hestia (>= 1.8) incluye la plantilla `laravel` (docroot `public/`). Si la version instalada no la trae, usar
`default` y fijar el docroot en `public_html` apuntando al directorio `public/` de la app:
clonar la app en `/home/<USUARIO>/web/<DOMINIO>/app` y enlazar `public_html -> app/public`.

## 3. Ajustes de Nginx / PHP-FPM versionados [VPS]

- `nginx-overrides.conf`: `client_max_body_size`, cabeceras de seguridad, bloqueo de ficheros ocultos y `/up` sin logs. Las instrucciones de copia estan en la cabecera del fichero. `nginx-http-map.conf` va aparte, a `/etc/nginx/conf.d/` (nivel http): evita que Nginx duplique la `Referrer-Policy` que ya envia PHP en `/oauth/authorize`.
- PHP-FPM (pool del dominio, `/etc/php/8.4/fpm/pool.d/<DOMINIO>.conf`): `upload_max_filesize = 512M`, `post_max_size = 512M`, `memory_limit = 256M`, `max_execution_time = 300` (con media local los vídeos pasan por PHP; con R2 bastan 64M y 120). Hestia permite fijarlos desde el panel (Editar dominio -> PHP) o con `v-change-web-domain-backend-tpl`.
- Tras cambiar: `systemctl reload nginx php8.4-fpm`.

## 4. Primera instalacion [VPS]

```bash
su - <USUARIO>
cd ~/web/<DOMINIO>
git clone <REPO> app && cd app
cp .env.production.example .env        # rellenar APP_URL, DB_*, HEALTHCHECK_TOKEN (openssl rand -hex 32), MAIL_*; R2_* solo si MEDIA_DISK=r2
chmod 600 .env                          # contiene secretos: solo legible por el usuario del dominio
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
```

## 5. Cron del scheduler y workers de cola [VPS]

El scheduler (`schedule:run`, cada minuto) solo **encola** el trabajo; los **workers** (`queue:work`) lo ejecutan. Hacen falta los dos: sin workers no salen las publicaciones ni se cargan las métricas.

### 5.1 Opción A: desde el panel de Hestia (cron), la de este despliegue

En **Hestia → usuario → CRON → Añadir tarea**, tres tareas. `<USUARIO>`/`<DOMINIO>` son los del dominio (aquí `webmaster` y `social-scheduler.daycry.ovh`) y la app está en `public_html`. Usa la ruta completa de PHP que te dé `which php` en tu sesión (aquí `/usr/bin/php`): así el cron usa el mismo PHP que tú.

**Antes de crear los workers: `pcntl` en el PHP de consola.** `queue:work` usa `pcntl_signal` para cortar un job colgado (`--timeout`) y pararse limpio. Hestia lo deja en `disable_functions`, y el worker falla con `Call to undefined function Illuminate\Queue\pcntl_signal()`. Se habilita **solo en el PHP de consola** (`cli`), no en el de la web (PHP-FPM), como root:

```bash
php --ini | grep "Loaded Configuration"                          # p. ej. /etc/php/8.4/cli/php.ini
sed -i -E '/^disable_functions/ s/pcntl_[a-z_]+,?//g' /etc/php/8.4/cli/php.ini
php -r 'var_dump(function_exists("pcntl_signal"));'              # bool(true)
```

Repite el `sed` si una actualización de PHP o de Hestia restaura el `php.ini`.

| # | Para qué | Minuto | Hora | Día | Mes | Día sem. |
|---|---|---|---|---|---|---|
| 1 | **Scheduler**: encola lo que toca (publicaciones vencidas, métricas, comentarios, autolistas, limpiezas) | `*` | `*` | `*` | `*` | `*` |
| 2 | **Worker de publicación**: colas `publish` y `poll` | `*` | `*` | `*` | `*` | `*` |
| 3 | **Worker de métricas e informes**: colas `metrics` y `reports` | `*/5` | `*` | `*` | `*` | `*` |

Comandos (uno por tarea, en una sola línea):

```text
# 1. Scheduler
cd /home/<USUARIO>/web/<DOMINIO>/public_html && /usr/bin/php artisan schedule:run >> /home/<USUARIO>/tmp/ss-schedule.log 2>&1

# 2. Worker de publicación
cd /home/<USUARIO>/web/<DOMINIO>/public_html && flock -n /home/<USUARIO>/tmp/ss-publish.lock /usr/bin/php artisan queue:work database --queue=publish,poll --stop-when-empty --max-time=55 --timeout=120 --tries=3 >> /home/<USUARIO>/tmp/ss-publish.log 2>&1

# 3. Worker de métricas e informes
cd /home/<USUARIO>/web/<DOMINIO>/public_html && flock -n /home/<USUARIO>/tmp/ss-metrics.lock /usr/bin/php artisan queue:work database --queue=metrics,reports --stop-when-empty --max-time=280 --timeout=120 --tries=3 >> /home/<USUARIO>/tmp/ss-metrics.log 2>&1
```

Qué hace cada opción:

| Opción | Por qué |
|---|---|
| `flock -n <lock>` | Si el worker anterior sigue trabajando, el nuevo no arranca: nunca hay dos a la vez sobre la misma cola. `~/tmp` existe en Hestia y es privado del usuario |
| `--stop-when-empty` | El worker termina al vaciar la cola, en vez de quedarse vivo |
| `--max-time=55` / `280` | Se corta antes del siguiente arranque del cron (1 min / 5 min) |
| `--timeout=120 --tries=3` | Igual que los workers de systemd: un job colgado se corta a los 120 s y se reintenta hasta 3 veces |

Latencia máxima: una publicación sale con hasta 1 minuto de retraso y las métricas o informes, con hasta 5. Para uso personal es suficiente.

Al actualizar no hay que tocar las tareas: `deploy.sh` ejecuta `queue:restart` y el siguiente arranque del cron ya usa el código nuevo (no hace falta `WORKER_UNITS`).

### 5.2 Opción B: systemd (procesos siempre vivos)

Requiere root. Sustituye a las tareas 2 y 3 (la 1, el scheduler, se mantiene). Ver `deploy/systemd/README.md`; si la app está en `public_html` y no en `app`, corrige `WorkingDirectory` y `ReadWritePaths` de la unidad.

### 5.3 Comprobar que los workers procesan

Como el usuario del dominio, en la carpeta de la app, un par de minutos después de crear las tareas:

```bash
php8.4 artisan schedule:list               # tareas programadas y su próxima ejecución
php8.4 artisan app:health                  # «scheduler» OK (el cron corre) y la cola sin atrasos
php8.4 artisan app:queue-probe --wait=360  # encola un job en cada cola y espera a que se procese (metrics tarda hasta 5 min con cron)
php8.4 artisan queue:failed | head         # sin fallos
php8.4 artisan tinker --execute='echo DB::table("jobs")->selectRaw("queue, count(*) n")->groupBy("queue")->get();'   # pendientes por cola
```

Pendientes que no bajan en una cola = su worker no está corriendo (revisa la tarea de cron o la unidad systemd).

Si la analítica sale «no disponible» tras conectar una cuenta, ver `docs/modulos/despliegue.md` → «Analítica no disponible tras conectar una cuenta».


### 5.4 Si algo no se ejecuta

Los comandos escriben su salida en `~/tmp` del usuario (`ss-schedule.log`, `ss-publish.log`, `ss-metrics.log`): lo primero es mirarla.

| Síntoma | Causa | Solución |
|---|---|---|
| `app:health`: `scheduler FAIL sin latido` | `schedule:run` no se ha ejecutado en los últimos 10 min (el latido caduca a los 600 s) | `tail ~/tmp/ss-schedule.log`: debe verse `Running [health-heartbeat] … DONE` cada minuto. Un `schedule:run` a mano solo lo arregla 10 minutos |
| `~/tmp/ss-schedule.log` no existe | El cron no ejecuta la tarea | Como root: `systemctl status cron`, `crontab -u <USUARIO> -l`, `journalctl -u cron --since "-10 min"`; si no aparece, `v-rebuild-cron-jobs <USUARIO>` y `systemctl restart cron`. Comprueba que la tarea no está suspendida (`v-list-cron-jobs <USUARIO>`) |
| Error de PHP o versión incorrecta en el log | El cron usa otro PHP | Ruta completa del binario (`which php` en tu sesión) en las tres tareas |
| `Call to undefined function … pcntl_signal()` | `pcntl` desactivado en el PHP de consola | Ver «Antes de crear los workers» arriba |
| Publicaciones que salen con minutos de retraso y `dispatch-due-posts` no aparece cada minuto en el log | Bloqueo de solapamiento enganchado (p. ej. tras lanzar `schedule:run` a mano a la vez que el cron) | `php artisan schedule:clear-cache` y no lanzar `schedule:run` a mano con el cron activo |
| La cola tiene pendientes que no bajan | El worker de esa cola no corre | `tail ~/tmp/ss-publish.log` o `ss-metrics.log`; procesarla a mano con `php artisan queue:work database --queue=<cola> --stop-when-empty --tries=3 --timeout=120` |

## 5 bis. Primer usuario [VPS]

El registro público está cerrado (`REGISTRATION_ENABLED=false`): las cuentas se crean por consola, una vez desplegada la app y aplicadas las migraciones.

```bash
# Como el usuario de Hestia, en la carpeta de la app. La contraseña se pide por teclado:
# no se ve ni queda en el historial de la shell.
php8.4 artisan migrate:status | tail -3        # todas en «Ran»
read -s -p 'Contraseña: ' SS_PASS; echo
SS_PASS="$SS_PASS" php8.4 artisan tinker --execute='
$u = new App\Models\User();
$u->name = "daycry";
$u->email = "daycry9@gmail.com";
$u->password = getenv("SS_PASS");        // el modelo la guarda con hash (cast `hashed`)
$u->email_verified_at = now();           // sin esto la app pide verificar el email antes de entrar
$u->save();
echo "Usuario creado con id {$u->id}\n";'
unset SS_PASS

# Comprobación
php8.4 artisan tinker --execute='echo App\Models\User::where("email","daycry9@gmail.com")->value("id");'
```

Después de entrar por primera vez:

- Crea la marca y vuelve a conectar Facebook, Instagram, YouTube y TikTok desde el dominio de producción: las conexiones de otro entorno (local, ngrok) no sirven.
- Si quieres, activa el doble factor y crea las passkeys en **Perfil → Seguridad** (las passkeys van atadas al dominio).
- «Continuar con Google» entra en esta cuenta si el email de Google coincide y está verificado (se vincula solo la primera vez).
- Las API keys del MCP de otro entorno no valen: conecta los clientes por OAuth contra `https://<DOMINIO>/mcp` o crea una key nueva en **API keys**.

Para cambiar la contraseña más tarde, desde **Perfil**. Si se pierde, repite el bloque anterior con `$u = App\Models\User::where("email", "...")->firstOrFail();` en lugar de `new App\Models\User()` (y sin `name`/`email`). Para desactivar el doble factor de un usuario que perdió el móvil: `php8.4 artisan user:two-factor-reset <email>`.

## 6. Despliegue repetible (un comando)

```bash
bash deploy/hestia/deploy.sh --dry-run   # lista los pasos sin ejecutar nada
bash deploy/hestia/deploy.sh             # despliega: pull, composer, migrate, caches, queue:restart, health
WORKER_UNITS="scheduler-worker@publish scheduler-worker@poll" bash deploy/hestia/deploy.sh   # ademas reinicia los workers systemd
```

Orden: `artisan down` -> fetch/pull -> composer -> `npm ci && npm run build` (gate: existe `public/build/manifest.json`) -> migrate -> caches
-> `queue:restart` -> `up` -> reinicio de `WORKER_UNITS` -> smoke (`manifest.json` + `app:health --skip-scheduler`).
- Si algo falla **antes** de cambiar el codigo, la app se vuelve a levantar; si falla **despues**, se queda en mantenimiento (no se sirve codigo nuevo con
  vendor o esquema a medias) y el script indica como volver a la revision anterior. En ambos casos se ejecuta `queue:restart`.
- Un job fallido historico **no** rompe el deploy: `app:health` solo falla si algo esta caido (BD, cola, disco); los fallidos recientes salen como AVISO.
- `PHP_BIN` (p. ej. `PHP_BIN=php8.4`) fija el PHP CLI y debe coincidir con `Environment=PHP_BIN=` de la unidad systemd.

## 7. Verificacion manual (M-04) [VPS]

```bash
curl -sI https://<DOMINIO>/up | head -1        # HTTP/2 200
php artisan schedule:list                      # cron activo
php artisan app:health                         # BD, cola, disco
```

## 8. TikTok: media publica y subida [VPS]

- **Fotos/carrusel (PULL_FROM_URL), solo con R2.** Con disco local no hay URL fija sin firma: deja `TIKTOK_VERIFIED_DOMAIN` vacío y la app no ofrece fotos de TikTok (los vídeos sí funcionan). TikTok descarga las imagenes de una URL del dominio verificado (`TIKTOK_VERIFIED_DOMAIN`), sin firma: ese dominio tiene que ser el dominio publico del bucket R2 (`R2_URL`) y **toda la media del bucket queda accesible por URL** (claves ULID impredecibles y bucket sin listado, pero no privado; CWE-552). Mitigacion recomendada: crear un bucket publico aparte solo para TikTok (o una regla WAF/Transform de Cloudflare que limite el dominio publico a un prefijo), apuntar `TIKTOK_VERIFIED_DOMAIN` ahi y fijar una regla de ciclo de vida de R2 que borre ese prefijo a las 24 h. El codigo no copia ni borra ficheros: decision deliberada (sin esa restriccion en el dominio, un prefijo aparte no oculta nada).
- **Video (FILE_UPLOAD).** La `upload_url` de TikTok vale 1 hora. Si caduca a mitad, la subida se reinicia una vez con un `init` nuevo; si vuelve a caducar, el destino falla con «reprograma». Ajustes: `TIKTOK_UPLOAD_URL_TTL`, `TIKTOK_UPLOAD_RESTARTS`, `TIKTOK_UPLOAD_MAX_MINUTES`.
- **Borrador sin confirmar.** Pasados `TIKTOK_INBOX_GRACE_MINUTES` desde la subida completa se da por enviado a la bandeja con la nota «revisa tus borradores»; no se sigue sondeando despues (si TikTok lo marcara `FAILED` mas tarde, el usuario lo ve en la app).

## Estado de verificacion

| Elemento | Estado |
|---|---|
| `deploy.sh --dry-run` | verificado en local |
| Dominio/TLS/BD/cron/Nginx en Hestia | pendiente: requiere el VPS (M-04) |
