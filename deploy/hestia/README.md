# Despliegue en HestiaCP

Runbook para publicar Social Scheduler en el VPS con HestiaCP. Todo lo versionable esta en `deploy/`.
Los pasos marcados **[VPS]** requieren acceso SSH/root y se ejecutan a mano una unica vez (M-04).

## 1. Prerrequisitos [VPS]

- HestiaCP con Nginx + PHP-FPM 8.4 (u 8.5) y MariaDB >= 10.6 / MySQL >= 8.0.
  Comprobar: `mysql --version` y `php -v`. Sin `SKIP LOCKED` (MariaDB < 10.6 / MySQL < 8.0) la cola `database` no admite varios workers.
- Extensiones PHP: `mbstring intl pdo_mysql bcmath gd curl xml zip`.
- Composer 2 y Node 22: `deploy.sh` compila los assets (`npm ci && npm run build`) y no da el deploy por bueno sin `public/build/manifest.json` (sin el, todas las paginas Inertia darian 500). Con `SKIP_BUILD=1` se omite la compilacion si el build llega por otro medio, pero el manifest sigue siendo obligatorio.
- Git y acceso de lectura al repositorio (deploy key).

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

- `nginx-overrides.conf`: `client_max_body_size`, cabeceras de seguridad, bloqueo de ficheros ocultos y `/up` sin logs. Las instrucciones de copia estan en la cabecera del fichero.
- PHP-FPM (pool del dominio, `/etc/php/8.4/fpm/pool.d/<DOMINIO>.conf`): `upload_max_filesize = 64M`, `post_max_size = 64M`, `memory_limit = 256M`, `max_execution_time = 120`. Hestia permite fijarlos desde el panel (Editar dominio -> PHP) o con `v-change-web-domain-backend-tpl`.
- Tras cambiar: `systemctl reload nginx php8.4-fpm`.

## 4. Primera instalacion [VPS]

```bash
su - <USUARIO>
cd ~/web/<DOMINIO>
git clone <REPO> app && cd app
cp .env.production.example .env        # rellenar APP_URL, DB_*, R2_*, HEALTHCHECK_TOKEN (openssl rand -hex 32), MAIL_*
chmod 600 .env                          # contiene secretos: solo legible por el usuario del dominio
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
```

## 5. Cron del scheduler [VPS]

Hestia -> Cron -> anadir (cada minuto) o por CLI:

```bash
v-add-cron-job <USUARIO> '*' '*' '*' '*' '*' 'cd /home/<USUARIO>/web/<DOMINIO>/app && php artisan schedule:run >> /dev/null 2>&1'
```

Verificar con `v-list-cron-jobs <USUARIO>` y, pasado un minuto, `php artisan schedule:list`.

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

## Estado de verificacion

| Elemento | Estado |
|---|---|
| `deploy.sh --dry-run` | verificado en local |
| Dominio/TLS/BD/cron/Nginx en Hestia | pendiente: requiere el VPS (M-04) |
