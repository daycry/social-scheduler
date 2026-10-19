---
generacion:
  inicio: 2026-10-19T15:28:30Z
  fin: 2026-10-19T15:34:45Z
  fuente: medido          # usage-meter.py close
  tokens_reales: { entrada: 18, salida: 56962, cache_creacion: 66893, cache_lectura: 898140 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json) - ejecuta rates-verify
  horas_ia: 0.41
  duracion: 25m
  ratio_usado: 300000     # default no calibrado     # ventana compartida con improvement-plan.md
verificacion: obligatoria
---

# Checklist de Tareas — Social Scheduler: programación y analítica de redes sociales (uso personal)

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-19 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (Laravel 13 nativo en HestiaCP) |
| **Test-plan** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> **Convención de horas.** Las horas por tarea son **base** (mid-point, sin colchón). El +20 % de contingencia se aplica en los totales de `improvement-plan.md` (505 h base → 606 h). El coste por fase = horas base × 1,2 × 50 €/h (más tokens, ⚠️ verificar precio).

> **🚦 PUERTA G1 (T-05).** Nada de lo que sigue a la Fase 0 empieza hasta que el usuario decida, con el informe del spike en la mano, qué hacer con TikTok y YouTube. Las tareas **T-13, T-14, T-26, T-27, T-33 y T-34** dependen explícitamente de esa decisión y pasan a `cancelado` si el usuario pospone la red.

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 0 — Spike de viabilidad de plataformas (puerta G1) | 0 | 5 | 0% | 0 / 10h | 0 / 0.9h | 0 / 0.4h | 0 / 455k |
| Fase 1 — Núcleo: Laravel, auth, marcas, aislamiento y despliegue en Hestia | 0 | 5 | 0% | 0 / 60h | 0 / 9.1h | 0 / 2.3h | 0 / 2,73M |
| Fase 2 — Conexiones OAuth por red | 0 | 6 | 0% | 0 / 60h | 0 / 9h | 0 / 2.3h | 0 / 2,73M |
| Fase 3 — Publicación base y Meta (IG + FB), calendario | 0 | 9 | 0% | 0 / 142h | 0 / 21.8h | 0 / 5.3h | 0 / 6,47M |
| Fase 4 — Publicación en YouTube (condicionada a G1) | 0 | 1 | 0% | 0 / 18h | 0 / 2.8h | 0 / 0.7h | 0 / 820k |
| Fase 5 — Publicación en TikTok (condicionada a G1) | 0 | 1 | 0% | 0 / 16h | 0 / 2.5h | 0 / 0.6h | 0 / 728k |
| Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación | 0 | 3 | 0% | 0 / 37h | 0 / 5.6h | 0 / 1.4h | 0 / 1,69M |
| Fase 7 — Analítica: snapshots, insights y dashboards | 0 | 7 | 0% | 0 / 106h | 0 / 16.2h | 0 / 4.1h | 0 / 4,83M |
| Fase 8 — Reporting PDF/CSV | 0 | 4 | 0% | 0 / 40h | 0 / 6h | 0 / 1.5h | 0 / 1,82M |
| Fase 9 — MCP estadísticas/informes y validación final | 0 | 2 | 0% | 0 / 16h | 0 / 2.4h | 0 / 0.6h | 0 / 728k |
| **TOTAL** | **0** | **43** | **0%** | **0 / 505h** | **0 / 76.3h** | **0 / 19.2h** | **0 / 23,00M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real), topado a la jornada configurada. Ver `skills/jira-sync/SKILL.md`.

---

## Fase 0 — Spike de viabilidad de plataformas (puerta G1)

**Estado**: en-progreso · **Estimado**: 10h · **Real**: — · **Coste est.**: 600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 455k

> Primer bloque del plan por condición del go de `evaluation.md`: confirmar la viabilidad de las plataformas antes de invertir en el resto. Presupuesto del spike: 8–12 h (aquí 10 h base). Es parte de C-02 (70 h base), así que no suma sobre el total evaluado.

### T-01 — Preparar cuentas de desarrollador y apps de prueba (Meta, Google, TikTok)

- **Descripción**: Crear las tres apps de desarrollador (Meta, Google Cloud, TikTok for Developers) en modo desarrollo/testing, vincular las cuentas propias (IG Business/Creator enlazada a página FB, canal YouTube, cuenta TikTok) y fijar el dominio HTTPS para los redirect URIs. Trabajo no delegable (trámites en consolas externas).
- **Changelog**: Sin cambio visible para el usuario final: prepara el terreno para conectar redes.
- **Estado**: borrador
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.2h · real —
- **Supervisión**: est. 0.1h (≈25 % IA) · real —
- **Previsión IA**: 104k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: devops
- **Archivos**: `spike/README.md`, `spike/.env.example`, `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `grep -ci "redirect" spike/README.md` → ≥ 3 (un redirect URI documentado por plataforma)
  - lectura: spike-report.md §Preparación lista app creada, modo (desarrollo/testing) y roles de las cuentas propias por plataforma

**Criterios de aceptación**
- [ ] Apps creadas en las tres plataformas, con las cuentas propias añadidas como testers/roles
- [ ] Dominio HTTPS accesible para los redirect URIs y para verificar prefijo de URL de TikTok
- [ ] Credenciales guardadas fuera del repo (`spike/.env`, ignorado por git)

**Subtareas**
- [ ] Crear app Meta (tipo Business) con productos Facebook Login e Instagram
- [ ] Crear proyecto Google Cloud, habilitar YouTube Data API v3 y YouTube Analytics API, pantalla de consentimiento en Testing
- [ ] Crear app TikTok con Login Kit y Content Posting API; registrar redirect y URL prefix
- [ ] Documentar en `spike/README.md` pasos, URLs y permisos solicitados

**Notas**: Código del spike = scripts PHP desechables en `spike/`, sin Laravel. Necesita un túnel o el dominio del VPS con TLS de Hestia.

### T-02 — Spike Meta: publicar y leer insights en modo desarrollo

- **Descripción**: Con una cuenta con rol en la app: OAuth, publicar un post de FB y uno de IG (imagen), un reel y una story de prueba (o registrar por qué no se puede), y leer insights de cuenta y de publicación. Anotar límites reales (publicaciones/24h, tamaños, caducidad de tokens).
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: borrador
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.2h · real —
- **Supervisión**: est. 0.1h (≈25 % IA) · real —
- **Previsión IA**: 104k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `spike/meta_probe.php`, `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php spike/meta_probe.php --check` → exit 0 e imprime tabla con permisos concedidos, tipos publicados y métricas de insights devueltas
  - lectura: spike-report.md §Meta responde (a) ¿basta el modo desarrollo?, (b) ¿login de IG vía Facebook Login o Instagram Login?, (c) ¿existe `impressions` o solo `views`?

**Criterios de aceptación**
- [ ] Resultado de publicación y de insights documentado con evidencia (IDs, respuesta de la API)
- [ ] Veredicto Meta: viable en modo desarrollo sí/no y limitaciones
- [ ] Método de login de IG propuesto

**Subtareas**
- [ ] Flujo OAuth y token de larga duración
- [ ] Publicación FB página (texto/foto) e IG (imagen, reel, story)
- [ ] Lectura de insights de cuenta y de publicación
- [ ] Registrar límites y errores observados

**Notas**: Los scripts son desechables; no migran a `app/`. Solo se reutilizan los hallazgos.

### T-03 — Spike TikTok: publicar sin auditoría y verificar dominio

- **Descripción**: Con la app sin auditar: OAuth, `creator_info/query` y publicación de prueba de vídeo (FILE_UPLOAD y PULL_FROM_URL) y de foto. Registrar la visibilidad resultante y los límites de usuarios/día.
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: borrador
- **Tiempo humano**: est. 2h · real —
- **Tiempo IA (ejec.)**: est. 0.2h · real —
- **Supervisión**: est. 0.1h (≈25 % IA) · real —
- **Previsión IA**: 83k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `spike/tiktok_probe.php`, `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php spike/tiktok_probe.php --check` → exit 0 e imprime las `privacy_level_options` devueltas por `creator_info/query`
  - lectura: spike-report.md §TikTok indica si, sin auditoría, solo es posible `SELF_ONLY`, y si PULL_FROM_URL exige dominio verificado

**Criterios de aceptación**
- [ ] Visibilidad real alcanzable sin auditoría documentada con evidencia
- [ ] Duración de access/refresh token confirmada
- [ ] Coste y plazo de la auditoría de TikTok anotados

**Subtareas**
- [ ] OAuth Login Kit
- [ ] Consulta `creator_info` y publicación de prueba
- [ ] Prueba de PULL_FROM_URL con dominio verificado
- [ ] Anotar requisitos de auditoría

**Notas**: Si TikTok solo permite privado, la publicación automática pierde valor: alimenta la puerta G1.

### T-04 — Spike YouTube: subida con proyecto no verificado y cupo diario

- **Descripción**: Subir un vídeo (y un short) con el proyecto sin verificar y sin auditoría, medir las unidades de cuota con la consola de Google y comprobar la caducidad del refresh token en estado Testing. Estimar subidas/día frente al volumen del usuario.
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: borrador
- **Tiempo humano**: est. 2h · real —
- **Tiempo IA (ejec.)**: est. 0.2h · real —
- **Supervisión**: est. 0.1h (≈25 % IA) · real —
- **Previsión IA**: 83k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `spike/youtube_probe.php`, `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php spike/youtube_probe.php --check` → exit 0 e imprime la visibilidad del vídeo subido y las unidades de cuota consumidas
  - lectura: spike-report.md §YouTube indica si los vídeos quedan privados, la caducidad del refresh token en Testing (7 días) y las subidas/día reales

**Criterios de aceptación**
- [ ] Visibilidad real y cuota por subida medidas
- [ ] Caducidad del refresh token confirmada
- [ ] Comparativa con el volumen declarado del usuario (subidas/día)

**Subtareas**
- [ ] OAuth con scope `youtube.upload`
- [ ] Subida reanudable de un vídeo de prueba
- [ ] Medir cuota en Cloud Console
- [ ] Anotar el proceso de verificación/auditoría de Google

### T-05 — Informe del spike y PUERTA G1: decisión del usuario sobre TikTok/YouTube

- **Descripción**: Consolidar los resultados del spike en `spike-report.md` con una tabla por plataforma (viable / limitado / no viable), presentarla al usuario y registrar su decisión por red. **Ninguna tarea de OAuth, publicación o analítica de TikTok o YouTube (T-13, T-14, T-26, T-27, T-33, T-34) empieza sin esta decisión, y el resto del plan tampoco arranca antes de G1** (condición del go).
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: borrador
- **Tiempo humano**: est. 1h · real —
- **Tiempo IA (ejec.)**: est. 0.1h · real —
- **Supervisión**: est. 0h (≈25 % IA) · real —
- **Previsión IA**: 41k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-02, T-03, T-04
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`, `docs/roadmap/2026-10-19-social-scheduler/improvement-plan.md`, `docs/roadmap/2026-10-19-social-scheduler/tasks.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `grep -c "^## Decisión G1" docs/roadmap/2026-10-19-social-scheduler/spike-report.md` → 1, con la opción elegida por el usuario por red
  - lectura: tasks.md refleja la decisión G1 (T-13, T-14, T-26, T-27, T-33 y T-34 marcadas `cancelado` si el usuario pospone la red)

**Criterios de aceptación**
- [ ] `spike-report.md` con veredicto por plataforma y evidencia
- [ ] Decisión G1 del usuario registrada por red (YouTube y TikTok) y confirmación de continuar con Meta
- [ ] Plan y ledger actualizados con el alcance resultante y su línea en el Changelog del plan

**Subtareas**
- [ ] Redactar el informe con tabla de viabilidad y recomendación
- [ ] Presentar al usuario y recoger la decisión (puerta)
- [ ] Actualizar alcance en el plan y cancelar tareas si procede

**Notas**: PUERTA G1 (obligatoria). Opciones por red: A) publicar con la limitación (p. ej. privado); B) tramitar auditoría/verificación y esperar; C) solo calendario y recordatorio, sin publicación automática; D) posponer la red.

---

## Fase 1 — Núcleo: Laravel, auth, marcas, aislamiento y despliegue en Hestia

**Estado**: borrador · **Estimado**: 60h · **Real**: — · **Coste est.**: 3.600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 2,73M

### T-06 — Esqueleto Laravel 13, calidad y CI

- **Descripción**: Crear el proyecto Laravel 13 en la raíz (sin pisar `docs/` ni `.claude/`) con Pest, Pint y CI. Fijar PHP 8.4/8.5 y BD MariaDB ≥ 10.6 / MySQL ≥ 8.0 (verificar con `mysql --version`); entorno local Laragon con `metricool.test`.
- **Changelog**: Proyecto base de la aplicación listo para desarrollo.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-05
- **Tipo**: backend
- **Archivos**: `composer.json`, `app/`, `bootstrap/`, `config/`, `routes/`, `tests/`, `phpunit.xml`, `pint.json`, `.github/workflows/ci.yml`, `.env.example`, `README.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan --version` → `Laravel Framework 13.`
  - `composer test` → suite verde (Pest) y `vendor/bin/pint --test` → exit 0

**Criterios de aceptación**
- [ ] Proyecto arranca en `https://metricool.test`
- [ ] CI ejecuta tests y Pint
- [ ] `.env.example` documenta las variables previstas

**Subtareas**
- [ ] `composer create-project laravel/laravel` en la raíz
- [ ] Instalar Pest y Pint; script `composer test`
- [ ] Workflow de CI
- [ ] Comprobar versión de MariaDB/MySQL y documentarla

**Notas**: La decisión de frontend (Inertia + Vue por defecto, React alternativa) se fija en T-07.

### T-07 — Autenticación web: registro, login, recuperación de contraseña e Inertia

- **Descripción**: Starter kit de Laravel con Inertia + Vue: registro, login, logout y reset de contraseña por email. Layout base con navegación.
- **Changelog**: Ya se puede registrar, iniciar sesión y recuperar la contraseña.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-06
- **Tipo**: frontend
- **Archivos**: `app/Http/Controllers/Auth/`, `routes/auth.php`, `resources/js/Pages/Auth/`, `resources/js/Layouts/`, `tests/Feature/Auth/`, `config/mail.php`
- **Cubre (tests)**: E2E-01
- **Verificación**:
  - `php artisan test --filter=Auth` → todos los tests pasan
  - `php artisan route:list --name=login` → ruta `login` presente

**Criterios de aceptación**
- [ ] Un visitante se registra e inicia sesión y ve su panel vacío (CA-01)
- [ ] Reset de contraseña envía email y permite cambiarla
- [ ] Rutas protegidas redirigen a login

**Subtareas**
- [ ] Instalar starter kit y adaptar
- [ ] Configurar envío de emails (SMTP de Hestia o proveedor)
- [ ] Tests de feature de auth
- [ ] Layout y navegación

**Notas**: Incógnita abierta: SMTP propio de Hestia o proveedor transaccional; 2FA fuera de alcance.

### T-08 — Dominio base: marcas, trait BelongsToUser, Policies y tests de aislamiento

- **Descripción**: Modelo `Brand`, trait `BelongsToUser` con global scope y Policies. Toda consulta pasa por servicios que exigen el usuario autenticado. Tests de aislamiento web desde el primer día, reutilizables para API y MCP.
- **Changelog**: Ya se pueden crear y gestionar marcas, aisladas por usuario.
- **Estado**: borrador
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real —
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-07
- **Tipo**: backend
- **Archivos**: `app/Models/Brand.php`, `app/Models/Concerns/BelongsToUser.php`, `app/Policies/`, `app/Services/BrandService.php`, `app/Http/Controllers/BrandController.php`, `resources/js/Pages/Brands/`, `database/migrations/`, `tests/Feature/Isolation/`
- **Cubre (tests)**: E2E-02
- **Verificación**:
  - `php artisan test --filter=Isolation` → todos los tests pasan (usuario B recibe 404/403 sobre marcas de A)
  - `php artisan migrate:fresh --env=testing` → exit 0

**Criterios de aceptación**
- [ ] CRUD de marcas completo
- [ ] Un usuario B pide una marca de A por web/API y recibe 404/403 sin filtrar existencia (CA-02)
- [ ] El global scope aplica a toda entidad con `user_id`
- [ ] Acceso denegado registrado

**Subtareas**
- [ ] Migración y modelo `Brand`
- [ ] Trait `BelongsToUser` + test de fallo seguro sin usuario
- [ ] Policies y `BrandService`
- [ ] Pantallas Inertia de marcas
- [ ] Suite `tests/Feature/Isolation/` reutilizable

**Notas**: Sin RLS en MariaDB/MySQL: el aislamiento es solo de aplicación; la suite de aislamiento es la defensa principal (design.md §6).

### T-09 — Despliegue en HestiaCP: dominio, PHP-FPM, BD, cron y plantilla Laravel

- **Descripción**: Documentar y automatizar el despliegue: dominio en Hestia con plantilla Laravel (`public/` como docroot), PHP 8.4/8.5, BD y usuario MariaDB, TLS Let's Encrypt, cron `schedule:run` cada minuto y script de deploy con `queue:restart`. Versionar los ajustes de Nginx/PHP-FPM (p. ej. `client_max_body_size`).
- **Changelog**: Aplicación disponible en el VPS bajo HTTPS.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: devops
- **Archivos**: `deploy/hestia/README.md`, `deploy/hestia/nginx-overrides.conf`, `deploy/hestia/deploy.sh`, `.env.production.example`
- **Cubre (tests)**: M-04
- **Verificación**:
  - `bash deploy/hestia/deploy.sh --dry-run` → lista los pasos sin errores
  - `curl -sI https://<dominio>/up` → `HTTP/2 200` en el VPS (manual, M-04)

**Criterios de aceptación**
- [ ] La app responde 200 en `/up` bajo HTTPS en el VPS
- [ ] Cron del scheduler activo
- [ ] Ajustes de Hestia versionados en `deploy/hestia/`
- [ ] Despliegue repetible con un solo comando

**Subtareas**
- [ ] Crear dominio y BD en Hestia
- [ ] Ajustes de Nginx y PHP-FPM versionados
- [ ] Cron de Hestia para el scheduler
- [ ] Script `deploy.sh` (pull, composer, migrate, caches, queue:restart)

**Notas**: Requiere el acceso SSH/root confirmado. Parte del trabajo es no delegable (M-04).

### T-10 — Almacenamiento R2, cola `database`, workers systemd y healthcheck

- **Descripción**: Configurar disco S3/R2, la conexión de cola `database` (comprobando `SKIP LOCKED` con la versión real de MariaDB/MySQL), unidades systemd de workers (`--max-time=3600 --max-jobs=500`, `Restart=always`) y un healthcheck externo. Documentar el plan B (cron con `--stop-when-empty`).
- **Changelog**: Procesos en segundo plano y almacenamiento de media listos.
- **Estado**: borrador
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: devops
- **Archivos**: `config/filesystems.php`, `config/queue.php`, `deploy/systemd/scheduler-worker@.service`, `app/Console/Commands/`, `routes/console.php`, `tests/Feature/Infra/`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --filter=Infra` → pasa (disco `r2` mockeado, cola `database` con colas `publish,poll,metrics,reports`)
  - `systemd-analyze verify deploy/systemd/scheduler-worker@.service` → sin errores (en el VPS)

**Criterios de aceptación**
- [ ] Worker systemd arranca, procesa un job de prueba y se reinicia tras `queue:restart`
- [ ] Disco `r2` operativo con URL pública o prefirmada
- [ ] Healthcheck documentado

**Subtareas**
- [ ] Config de filesystem R2 y dominio propio sobre el bucket
- [ ] Config de colas y job de prueba
- [ ] Unidad systemd plantilla `@.service`
- [ ] Healthcheck y plan B

**Notas**: Verificar `mysql --version` ≥ MariaDB 10.6 / MySQL 8.0 antes de habilitar varios workers.

---

## Fase 2 — Conexiones OAuth por red

**Estado**: borrador · **Estimado**: 60h · **Real**: — · **Coste est.**: 3.600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 2,73M

### T-11 — Modelo SocialAccount, tokens cifrados y contrato común de conectores

- **Descripción**: Definir `SocialAccount` (marca, red, id externo, estado `activa|requiere_reconexion|desconectada`, tokens con cast `encrypted`) y las interfaces de conector (OAuth, publicación, polling, insights) para aislar cada red y versión de API.
- **Changelog**: Las cuentas conectadas guardan sus credenciales cifradas.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Models/SocialAccount.php`, `app/Connectors/Contracts/`, `app/Services/SocialAccountService.php`, `database/migrations/`, `tests/Unit/Connectors/`
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --filter=SocialAccount` → pasa (la columna de tokens en BD no contiene texto plano)

**Criterios de aceptación**
- [ ] Tokens cifrados en reposo (verificado leyendo la BD)
- [ ] Interfaz común definida y documentada
- [ ] Aislamiento por usuario heredado

**Subtareas**
- [ ] Migración y modelo
- [ ] Contratos de conectores
- [ ] Servicio de cuentas con aislamiento
- [ ] Conector simulado (fake) para tests

### T-12 — Conector Meta: OAuth de Instagram y Facebook

- **Descripción**: Flujo OAuth contra Meta (método de login decidido en el spike), selección de página FB y cuenta IG, intercambio a token de larga duración y renovación. Socialite como base para FB; el resto con cliente HTTP propio.
- **Changelog**: Se pueden conectar cuentas de Instagram y páginas de Facebook.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/`, `app/Http/Controllers/OAuth/MetaController.php`, `config/services.php`, `tests/Feature/Connectors/Meta/`
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**:
  - `php artisan test --filter=MetaOAuth` → pasa con respuestas grabadas
  - lectura: M-02 registra la conexión real de IG y FB con evidencia

**Criterios de aceptación**
- [ ] IG y FB conectadas a una marca con su estado (CA-03)
- [ ] Token de larga duración guardado y renovable
- [ ] Errores de permisos con mensaje legible

**Subtareas**
- [ ] Cliente Meta con versión de API fija
- [ ] Controlador OAuth y selección de cuenta/página
- [ ] Renovación de token
- [ ] Tests con respuestas grabadas

### T-13 — Conector Google: OAuth de YouTube (condicionado a G1)

- **Descripción**: OAuth de Google para YouTube Data + Analytics con refresh token offline, selección de canal y manejo de la caducidad de 7 días en estado Testing según G1.
- **Changelog**: Se puede conectar un canal de YouTube.
- **Estado**: borrador
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/`, `app/Http/Controllers/OAuth/GoogleController.php`, `tests/Feature/Connectors/Google/`
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**: `php artisan test --filter=GoogleOAuth` → pasa con respuestas grabadas

**Criterios de aceptación**
- [ ] Canal conectado a una marca (CA-03)
- [ ] Refresh y reautenticación gestionados según G1
- [ ] Cancelada si G1 pospone YouTube

**Subtareas**
- [ ] Cliente `google/apiclient`
- [ ] Controlador OAuth y selección de canal
- [ ] Gestión de refresh/caducidad
- [ ] Tests

**Notas**: Se cancela con G1 = D.

### T-14 — Conector TikTok: OAuth con cliente HTTP propio (condicionado a G1)

- **Descripción**: Login Kit de TikTok con cliente propio (no `socialiteproviders/tiktok`, sin releases desde 2024-04), refresco de tokens de ≈24 h y consulta de `creator_info`.
- **Changelog**: Se puede conectar una cuenta de TikTok.
- **Estado**: borrador
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/`, `app/Http/Controllers/OAuth/TikTokController.php`, `tests/Feature/Connectors/TikTok/`
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**: `php artisan test --filter=TikTokOAuth` → pasa con respuestas grabadas

**Criterios de aceptación**
- [ ] Cuenta conectada a una marca (CA-03)
- [ ] Refresh de access token automático
- [ ] Cancelada si G1 pospone TikTok

**Subtareas**
- [ ] Cliente HTTP de TikTok
- [ ] Controlador OAuth
- [ ] Refresh de tokens
- [ ] Tests

**Notas**: Se cancela con G1 = D.

### T-15 — UI de conexiones: conectar, desconectar y reconectar por marca

- **Descripción**: Pantalla de conexiones de la marca: estado de cada cuenta, botones de conectar/desconectar y aviso `requiere reconexión`.
- **Changelog**: Pantalla para gestionar las cuentas conectadas de cada marca.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Brands/Connections.vue`, `app/Http/Controllers/ConnectionController.php`, `tests/Feature/Connections/`
- **Cubre (tests)**: E2E-03, E2E-04, M-02
- **Verificación**: `php artisan test --filter=Connections` → pasa

**Criterios de aceptación**
- [ ] La cuenta aparece conectada con su estado (CA-03)
- [ ] Una cuenta en `requiere reconexión` muestra aviso y botón de reconectar
- [ ] Desconectar elimina los tokens

**Subtareas**
- [ ] Vista Inertia
- [ ] Controlador y rutas
- [ ] Mensajes de estado
- [ ] Tests de feature

### T-16 — Job de refresco de tokens y estado «requiere reconexión»

- **Descripción**: Job programado que renueva tokens antes de caducar (p. ej. 7 días antes de los 60 de Meta) y, si no puede, marca la cuenta y avisa. Las publicaciones afectadas no se pierden.
- **Changelog**: Las conexiones se mantienen vivas y avisan si hay que reconectar.
- **Estado**: borrador
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `app/Jobs/RefreshTokens.php`, `routes/console.php`, `app/Notifications/ReconnectRequired.php`, `tests/Feature/Jobs/`
- **Cubre (tests)**: E2E-04
- **Verificación**: `php artisan test --filter=RefreshTokens` → pasa (renueva tokens; el fallo marca la cuenta y notifica)

**Criterios de aceptación**
- [ ] Renovación automática sin intervención (CA-04)
- [ ] Fallo deja la cuenta `requiere_reconexion` y notifica
- [ ] Publicaciones pendientes no se borran

**Subtareas**
- [ ] Job con margen configurable
- [ ] Marcado de estado y notificación
- [ ] Registro en el scheduler
- [ ] Tests

---

## Fase 3 — Publicación base y Meta (IG + FB), calendario

**Estado**: borrador · **Estimado**: 142h · **Real**: — · **Coste est.**: 8.520 € + tokens (⚠️ verificar precio) · **Tokens est.**: 6,47M

### T-17 — Subida de media a R2 (multipart prefirmada) y MediaAsset

- **Descripción**: Subida directa navegador→R2 con URLs prefirmadas y multipart para vídeo; registro del `MediaAsset` con metadatos y URL pública/firmada accesible por Meta y TikTok. El binario nunca pasa por PHP-FPM.
- **Changelog**: Se pueden subir imágenes y vídeos grandes.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-10, T-08
- **Tipo**: backend
- **Archivos**: `app/Models/MediaAsset.php`, `app/Services/MediaUploadService.php`, `app/Http/Controllers/MediaController.php`, `resources/js/Components/MediaUploader.vue`, `tests/Feature/Media/`
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --filter=MediaUpload` → pasa (URLs prefirmadas, aislamiento, finalización multipart)

**Criterios de aceptación**
- [ ] Vídeo grande sube por multipart sin pasar por PHP-FPM
- [ ] Cada asset pertenece a un usuario y no es accesible por otros
- [ ] Metadatos (dimensiones, duración, peso) extraídos

**Subtareas**
- [ ] Endpoints de prefirmado y finalización
- [ ] Componente de subida
- [ ] Extracción de metadatos
- [ ] Tests de aislamiento

### T-18 — Validador de media por red y tipo de contenido

- **Descripción**: Reglas por red y tipo (dimensiones, duración, peso, códec, nº de elementos de carrusel) en configuración, contrastadas con la documentación oficial (⚠️ verificar). Mensajes concretos del límite incumplido.
- **Changelog**: La aplicación avisa antes de programar si el contenido no cumple los límites de la red.
- **Estado**: borrador
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real —
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-17
- **Tipo**: backend
- **Archivos**: `app/Services/MediaValidator.php`, `config/media_specs.php`, `tests/Unit/MediaValidator/`
- **Cubre (tests)**: E2E-05
- **Verificación**: `php artisan test --filter=MediaValidator` → pasa para los 13 tipos de contenido, con casos límite

**Criterios de aceptación**
- [ ] Un vídeo que excede los límites de un reel de IG se rechaza indicando el límite (CA-05)
- [ ] Reglas versionadas en `config/media_specs.php`
- [ ] Cobertura de los 13 tipos

**Subtareas**
- [ ] Matriz de specs oficial por tipo
- [ ] Validador y mensajes
- [ ] Tests con ficheros de muestra

**Notas**: Sin transcodificación (fuera de alcance, +20–30 h).

### T-19 — Modelo de publicaciones, máquina de estados y PostService

- **Descripción**: Modelar `Post` y `PostTarget` por red/cuenta/tipo, estados `borrador → programada → publicando → procesando → publicada|fallida` y servicio con zona horaria por usuario/marca. Todo pasa por el servicio (web y MCP).
- **Changelog**: Modelo de publicaciones con estados claros.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-18
- **Tipo**: backend
- **Archivos**: `app/Models/Post.php`, `app/Models/PostTarget.php`, `app/Models/PublishAttempt.php`, `app/Services/PostService.php`, `app/Enums/PostStatus.php`, `database/migrations/`, `tests/Feature/Posts/`
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --filter=Posts` → pasa (transiciones válidas e inválidas, aislamiento)

**Criterios de aceptación**
- [ ] Transiciones de estado validadas y persistidas
- [ ] Zona horaria por marca respetada
- [ ] Aislamiento por usuario heredado

**Subtareas**
- [ ] Migraciones y modelos
- [ ] Enum de estados y reglas de transición
- [ ] `PostService` con validación de media
- [ ] Tests

### T-20 — Cola de publicación: dispatcher, jobs, reintentos e idempotencia

- **Descripción**: Dispatcher cada minuto de lo vencido; `PublishPostTarget` con `tries`/`backoff`/`ShouldBeUnique`; estado `publicando` persistido antes de llamar a la red y consulta del estado externo antes de reintentar; `PollPublishStatus` para procesos asíncronos; recuperación de vencidas tras caída del VPS.
- **Changelog**: Las publicaciones se lanzan solas a su hora y sin duplicados.
- **Estado**: borrador
- **Tiempo humano**: est. 22h · real —
- **Tiempo IA (ejec.)**: est. 3.4h · real —
- **Supervisión**: est. 0.8h (≈25 % IA) · real —
- **Previsión IA**: 911k in / 91k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-10
- **Tipo**: backend
- **Archivos**: `app/Jobs/PublishPostTarget.php`, `app/Jobs/PollPublishStatus.php`, `routes/console.php`, `app/Services/PublishRecovery.php`, `tests/Feature/Publishing/`
- **Cubre (tests)**: E2E-06, E2E-07
- **Verificación**: `php artisan test --filter=Publishing` → pasa (reintentos con backoff, sin duplicados, recuperación de vencidas)

**Criterios de aceptación**
- [ ] Error transitorio acaba `publicada` o `fallida` con motivo, sin duplicados (CA-07)
- [ ] Tras una caída, al arrancar se publican las vencidas
- [ ] Error permanente no reintenta

**Subtareas**
- [ ] Dispatcher en el scheduler
- [ ] Jobs con unicidad y backoff
- [ ] Idempotencia y comprobación previa del estado externo
- [ ] Recuperación de vencidas
- [ ] Tests con conector simulado

**Notas**: Tarea de mayor riesgo técnico (design.md §6).

### T-21 — Publicación en Instagram: post, carrusel, reel y story

- **Descripción**: Contenedores de media de IG, polling del procesamiento, publicación y captura del permalink; manejo del límite de publicaciones por 24 h y de las limitaciones de stories (⚠️ verificar).
- **Changelog**: Se publica en Instagram: imagen, carrusel, reel y story.
- **Estado**: borrador
- **Tiempo humano**: est. 22h · real —
- **Tiempo IA (ejec.)**: est. 3.4h · real —
- **Supervisión**: est. 0.8h (≈25 % IA) · real —
- **Previsión IA**: 911k in / 91k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Instagram/`, `tests/Feature/Connectors/Meta/Instagram/`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=InstagramPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real de los 4 tipos de IG con enlace/ID

**Criterios de aceptación**
- [ ] Cada tipo se publica y queda `publicada` con enlace/ID externo (CA-06)
- [ ] Errores de la API mapeados a transitorios o permanentes
- [ ] Limitaciones de stories avisadas en el compositor

**Subtareas**
- [ ] Imagen y carrusel
- [ ] Reel con polling
- [ ] Story
- [ ] Mapeo de errores y límites
- [ ] Tests con respuestas grabadas

**Notas**: La validación real es manual (M-01).

### T-22 — Publicación en Facebook páginas: texto/enlace, foto, vídeo y reel

- **Descripción**: Publicación en páginas de Facebook (texto/enlace, foto, vídeo y reel) con el token de página y polling del procesamiento de vídeo.
- **Changelog**: Se publica en páginas de Facebook.
- **Estado**: borrador
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real —
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Facebook/`, `tests/Feature/Connectors/Meta/Facebook/`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=FacebookPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real de los 4 tipos de FB

**Criterios de aceptación**
- [ ] Los 4 tipos quedan `publicada` con ID externo (CA-06)
- [ ] El reel respeta sus requisitos de formato
- [ ] Errores mapeados

**Subtareas**
- [ ] Texto/enlace y foto
- [ ] Vídeo
- [ ] Reel
- [ ] Tests con respuestas grabadas

### T-23 — Compositor de publicaciones por red y tipo

- **Descripción**: Formulario de creación y edición: elegir marca, cuentas y tipo, texto, media y fecha/hora con zona horaria; muestra errores de validación de media y limitaciones por red.
- **Changelog**: Se pueden programar publicaciones desde un compositor.
- **Estado**: borrador
- **Tiempo humano**: est. 18h · real —
- **Tiempo IA (ejec.)**: est. 2.8h · real —
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-21
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Posts/Compose.vue`, `resources/js/Components/`, `app/Http/Controllers/PostController.php`, `tests/Feature/Posts/Compose/`
- **Cubre (tests)**: E2E-05, E2E-06, M-01
- **Verificación**: `php artisan test --filter=Compose` → pasa

**Criterios de aceptación**
- [ ] Programación guardada y visible
- [ ] Media inválida se rechaza con el límite incumplido (CA-05)
- [ ] Edición y borrado de pendientes

**Subtareas**
- [ ] Formulario por red/tipo
- [ ] Integración con el validador
- [ ] Previsualización básica
- [ ] Tests de feature

**Notas**: El compositor incorpora YouTube y TikTok en T-26 y T-27.

### T-24 — Calendario por marca y red con reprogramación

- **Descripción**: Vista de calendario (mes/semana) filtrable por marca y red con el estado de cada publicación y reprogramación de las pendientes.
- **Changelog**: Calendario para ver y reprogramar publicaciones.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Calendar/`, `app/Services/CalendarService.php`, `app/Http/Controllers/CalendarController.php`, `tests/Feature/Calendar/`
- **Cubre (tests)**: E2E-08
- **Verificación**: `php artisan test --filter=Calendar` → pasa

**Criterios de aceptación**
- [ ] Publicaciones en su fecha con estado (CA-08)
- [ ] Reprogramar pendientes funciona
- [ ] Filtros por marca y red

**Subtareas**
- [ ] `CalendarService`
- [ ] Vista de calendario
- [ ] Edición de fecha
- [ ] Tests

### T-25 — Notificaciones de fallo y política ante cuota agotada

- **Descripción**: Aviso in-app y por email cuando una publicación falla, y política ante cuota agotada (reprogramar automáticamente o fallar con motivo «cuota»; decisión de producto a confirmar).
- **Changelog**: Avisos cuando una publicación falla.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `app/Notifications/PublishFailed.php`, `app/Services/QuotaPolicy.php`, `tests/Feature/Notifications/`
- **Cubre (tests)**: E2E-07
- **Verificación**: `php artisan test --filter=PublishFailed` → pasa (aviso in-app y email)

**Criterios de aceptación**
- [ ] Un fallo genera aviso con motivo legible
- [ ] Cuota agotada sigue la política elegida

**Subtareas**
- [ ] Notificación in-app y email
- [ ] Política de cuota configurable
- [ ] Tests

**Notas**: Incógnita: reprogramar automáticamente o fallar.

---

## Fase 4 — Publicación en YouTube (condicionada a G1)

**Estado**: borrador · **Estimado**: 18h · **Real**: — · **Coste est.**: 1.080 € + tokens (⚠️ verificar precio) · **Tokens est.**: 820k

> Fase condicionada a la puerta G1 (T-05). Si el usuario pospone la red, la tarea pasa a `cancelado` y su presupuesto se libera.

### T-26 — Publicación en YouTube: vídeo y short con subida reanudable (condicionada a G1)

- **Descripción**: Subida reanudable en streaming desde R2, gestión de cuota (~1.600 unidades por subida), estado de procesamiento y visibilidad según la decisión G1. Añade los tipos YouTube al compositor.
- **Changelog**: Se publica en YouTube (vídeo y short).
- **Estado**: borrador
- **Tiempo humano**: est. 18h · real —
- **Tiempo IA (ejec.)**: est. 2.8h · real —
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-13, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `tests/Feature/Connectors/Google/Publish/`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=YouTubePublish` → pasa (chunks 8–16 MB desde R2 en streaming, control de cuota)
  - lectura: M-01 registra la subida real de vídeo y short con su visibilidad

**Criterios de aceptación**
- [ ] Vídeo y short quedan `publicada` con enlace (CA-06) con la visibilidad acordada en G1
- [ ] Cuota agotada sigue la política de T-25
- [ ] Nunca se carga el vídeo completo en memoria

**Subtareas**
- [ ] Cliente de subida reanudable
- [ ] Control de cuota
- [ ] Integración con la cola y el compositor
- [ ] Tests

**Notas**: Se cancela con G1 = D; con G1 = C solo calendario y recordatorio.

---

## Fase 5 — Publicación en TikTok (condicionada a G1)

**Estado**: borrador · **Estimado**: 16h · **Real**: — · **Coste est.**: 960 € + tokens (⚠️ verificar precio) · **Tokens est.**: 728k

> Fase condicionada a la puerta G1 (T-05). Si el usuario pospone la red, la tarea pasa a `cancelado` y su presupuesto se libera.

### T-27 — Publicación en TikTok: vídeo y foto/carrusel (condicionada a G1)

- **Descripción**: Content Posting API: vídeo (PULL_FROM_URL desde dominio verificado) y foto/carrusel, polling de `publish_status` y visibilidad según G1. Añade los tipos TikTok al compositor.
- **Changelog**: Se publica en TikTok (vídeo y foto/carrusel).
- **Estado**: borrador
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real —
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-14, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/TikTokPublisher.php`, `tests/Feature/Connectors/TikTok/Publish/`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=TikTokPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real en TikTok con su visibilidad

**Criterios de aceptación**
- [ ] Vídeo y foto quedan `publicada` (CA-06) con la visibilidad acordada en G1
- [ ] Polling del estado de publicación robusto
- [ ] Dominio propio sobre el bucket verificado

**Subtareas**
- [ ] Flujo init/upload/publish
- [ ] Polling del estado
- [ ] Integración con cola y compositor
- [ ] Tests

**Notas**: Se cancela con G1 = D.

---

## Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación

**Estado**: borrador · **Estimado**: 37h · **Real**: — · **Coste est.**: 2.220 € + tokens (⚠️ verificar precio) · **Tokens est.**: 1,69M

### T-28 — Servidor MCP HTTP con API keys personales (Sanctum)

- **Descripción**: Servidor `laravel/mcp` (versión menor fijada) en `/mcp` con Bearer = API key personal (hash en reposo), pantalla para crear y revocar keys y rate limiting.
- **Changelog**: Un agente de IA puede conectarse al MCP con una API key personal.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Mcp/Servers/SchedulerServer.php`, `routes/ai.php`, `app/Http/Controllers/ApiKeyController.php`, `resources/js/Pages/ApiKeys/`, `tests/Feature/Mcp/`
- **Cubre (tests)**: E2E-12, API-01, API-02
- **Verificación**:
  - `php artisan test --filter=McpAuth` → pasa (key válida inicializa; inválida o revocada, 401)
  - `php artisan route:list --path=mcp` → ruta `/mcp` con middleware `auth:sanctum`

**Criterios de aceptación**
- [ ] Key inválida o revocada devuelve 401 y no ejecuta herramientas
- [ ] Keys con hash en reposo
- [ ] UI para crear y revocar

**Subtareas**
- [ ] Instalar y fijar `laravel/mcp`
- [ ] Autenticación Sanctum y rate limit
- [ ] UI de API keys
- [ ] Tests

**Notas**: Plan B de design.md §6 si `laravel/mcp` falla.

### T-29 — Herramientas MCP: marcas, cuentas, publicaciones y calendario

- **Descripción**: Herramientas finas sobre `app/Services`: listar marcas y cuentas, crear/editar/borrar/reprogramar publicaciones y consultar el calendario. El agente pasa URL o ID de media ya subida.
- **Changelog**: El agente puede programar y consultar publicaciones.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-28, T-24
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/`, `tests/Feature/Mcp/Tools/`
- **Cubre (tests)**: API-01
- **Verificación**: `php artisan test --filter=McpTools` → pasa (listar marcas, programar/editar/borrar publicación, calendario)

**Criterios de aceptación**
- [ ] Cada operación se ejecuta solo sobre datos del usuario de la key (CA-12)
- [ ] Errores devueltos con mensajes legibles

**Subtareas**
- [ ] Tools de marcas y cuentas
- [ ] Tools de publicaciones
- [ ] Tool de calendario
- [ ] Tests

### T-30 — Aislamiento MCP, auditoría y límites

- **Descripción**: Reutilizar la suite de aislamiento contra cada herramienta MCP, registrar acciones y accesos denegados y limitar el uso por key.
- **Changelog**: El acceso por agente es seguro y auditable.
- **Estado**: borrador
- **Tiempo humano**: est. 11h · real —
- **Tiempo IA (ejec.)**: est. 1.7h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 455k in / 46k out tok · ⚠️ verificar €
- **Dependencias**: T-29
- **Tipo**: test
- **Archivos**: `tests/Feature/Isolation/Mcp/`, `app/Services/AuditLog.php`, `tests/Feature/Mcp/`
- **Cubre (tests)**: API-03
- **Verificación**:
  - `php artisan test --filter=Isolation` → pasa incluidas las herramientas MCP
  - `php artisan test --filter=McpAudit` → pasa (acciones registradas)

**Criterios de aceptación**
- [ ] Un usuario B no accede a datos de A por MCP (CA-02, CA-12)
- [ ] Acciones y denegaciones auditadas

**Subtareas**
- [ ] Tests de aislamiento por herramienta
- [ ] Auditoría
- [ ] Rate limiting por key

---

## Fase 7 — Analítica: snapshots, insights y dashboards

**Estado**: borrador · **Estimado**: 106h · **Real**: — · **Coste est.**: 6.360 € + tokens (⚠️ verificar precio) · **Tokens est.**: 4,83M

### T-31 — Modelo de snapshots y job diario (adelantable tras F2)

- **Descripción**: Tablas de snapshots normalizados y job diario por cuenta conectada. Puede adelantarse justo después de F2 para empezar a acumular histórico cuanto antes.
- **Changelog**: La aplicación empieza a guardar el histórico diario de métricas.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-16
- **Tipo**: backend
- **Archivos**: `app/Models/AccountSnapshot.php`, `app/Models/PostMetricSnapshot.php`, `app/Jobs/CollectSnapshots.php`, `routes/console.php`, `database/migrations/`, `tests/Feature/Snapshots/`
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --filter=Snapshots` → pasa (un snapshot por cuenta y día, idempotente)

**Criterios de aceptación**
- [ ] Snapshot diario por cuenta y de publicaciones recientes (CA-09)
- [ ] Job idempotente

**Subtareas**
- [ ] Migraciones y modelos
- [ ] Job con planificación diaria
- [ ] Tests

### T-32 — Colector de insights de Meta (IG + FB)

- **Descripción**: Métricas de cuenta y de publicación en IG/FB según las que ofrezca la versión vigente de la API (⚠️ `impressions` frente a `views`). Las no disponibles se reportan como no disponibles, nunca como 0.
- **Changelog**: Se recogen estadísticas de Instagram y Facebook.
- **Estado**: borrador
- **Tiempo humano**: est. 18h · real —
- **Tiempo IA (ejec.)**: est. 2.8h · real —
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Insights.php`, `tests/Feature/Connectors/Meta/Insights/`
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --filter=MetaInsights` → pasa con respuestas grabadas; una métrica no disponible se guarda como `null`

**Criterios de aceptación**
- [ ] Snapshot de métricas de cuenta y publicaciones (CA-09)
- [ ] Métricas retiradas gestionadas

**Subtareas**
- [ ] Mapeo de métricas por versión de API
- [ ] Colector y reintentos
- [ ] Tests

### T-33 — Colector de YouTube Analytics (condicionado a G1)

- **Descripción**: Métricas de canal y de vídeo con YouTube Analytics API (otro scope y otra API).
- **Changelog**: Se recogen estadísticas de YouTube.
- **Estado**: borrador
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real —
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-13
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/Analytics.php`, `tests/Feature/Connectors/Google/Analytics/`
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --filter=YouTubeAnalytics` → pasa con respuestas grabadas

**Criterios de aceptación**
- [ ] Snapshot de métricas (CA-09)
- [ ] Scope adicional solicitado en la conexión

**Subtareas**
- [ ] Cliente Analytics
- [ ] Colector
- [ ] Tests

**Notas**: Se cancela con G1 = D.

### T-34 — Colector de TikTok Display API (condicionado a G1)

- **Descripción**: Métricas disponibles de la Display API (pocas); lo no disponible se muestra como tal.
- **Changelog**: Se recogen las estadísticas que TikTok permite.
- **Estado**: borrador
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real —
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-14
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/Display.php`, `tests/Feature/Connectors/TikTok/Display/`
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --filter=TikTokDisplay` → pasa con respuestas grabadas

**Criterios de aceptación**
- [ ] Snapshot de métricas disponibles (CA-09)

**Subtareas**
- [ ] Cliente Display
- [ ] Colector
- [ ] Tests

**Notas**: Se cancela con G1 = D.

### T-35 — Normalización de métricas y cálculo de crecimiento/engagement

- **Descripción**: Capa de normalización desacoplada de la versión de API, cálculo de crecimiento y engagement sin inventar equivalencias entre redes.
- **Changelog**: Las métricas de todas las redes se presentan de forma comparable.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: backend
- **Archivos**: `app/Services/AnalyticsService.php`, `tests/Unit/Analytics/`
- **Cubre (tests)**: E2E-10
- **Verificación**: `php artisan test --filter=Analytics` → pasa (crecimiento, engagement, «no disponible»)

**Criterios de aceptación**
- [ ] Crecimiento y engagement calculados correctamente
- [ ] Métrica ausente = «no disponible» (no 0)

**Subtareas**
- [ ] Modelo normalizado
- [ ] Cálculos
- [ ] Tests

### T-36 — Backfill inicial y métricas por publicación reciente

- **Descripción**: Cargar el histórico que permita cada API al conectar una cuenta y refrescar métricas de publicaciones recientes (p. ej. cada 6 h las primeras 72 h).
- **Changelog**: Al conectar una cuenta se importa el histórico disponible.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: backend
- **Archivos**: `app/Jobs/BackfillMetrics.php`, `app/Jobs/RefreshPostMetrics.php`, `tests/Feature/Backfill/`
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --filter=Backfill` → pasa

**Criterios de aceptación**
- [ ] Backfill con el máximo histórico permitido por red
- [ ] Refresco programado de publicaciones recientes

**Subtareas**
- [ ] Job de backfill
- [ ] Refresco por publicación
- [ ] Tests

### T-37 — Dashboards por marca con gráficos por red

- **Descripción**: Dashboard por marca con vista por red: seguidores y crecimiento, alcance, impresiones y engagement con ECharts/Chart.js, mostrando «no disponible» donde la API no lo dé.
- **Changelog**: Panel de estadísticas por marca.
- **Estado**: borrador
- **Tiempo humano**: est. 24h · real —
- **Tiempo IA (ejec.)**: est. 3.7h · real —
- **Supervisión**: est. 0.9h (≈25 % IA) · real —
- **Previsión IA**: 994k in / 99k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Dashboard/`, `app/Http/Controllers/DashboardController.php`, `tests/Feature/Dashboard/`
- **Cubre (tests)**: E2E-10, M-03
- **Verificación**:
  - `php artisan test --filter=Dashboard` → pasa
  - lectura: M-03 revisa gráficos con datos reales

**Criterios de aceptación**
- [ ] Con ≥ 30 días de snapshots se ven seguidores, alcance, impresiones y engagement (CA-10)
- [ ] «No disponible» donde proceda

**Subtareas**
- [ ] Componentes de gráficos
- [ ] Vista por marca y red
- [ ] Filtros de fecha
- [ ] Tests

---

## Fase 8 — Reporting PDF/CSV

**Estado**: borrador · **Estimado**: 40h · **Real**: — · **Coste est.**: 2.400 € + tokens (⚠️ verificar precio) · **Tokens est.**: 1,82M

### T-38 — ReportService y exportación CSV

- **Descripción**: Generar el conjunto de datos del informe por marca y rango de fechas y exportarlo a CSV.
- **Changelog**: Se pueden exportar los datos de un informe en CSV.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: backend
- **Archivos**: `app/Services/ReportService.php`, `app/Models/Report.php`, `tests/Feature/Reports/Csv/`
- **Cubre (tests)**: E2E-11
- **Verificación**: `php artisan test --filter=ReportCsv` → pasa

**Criterios de aceptación**
- [ ] CSV con los datos del rango (CA-11)
- [ ] Aislamiento por usuario

**Subtareas**
- [ ] Servicio y modelo
- [ ] Exportador CSV
- [ ] Tests

### T-39 — Exportación PDF (decisión de driver: Chromium o DomPDF)

- **Descripción**: Decidir `spatie/laravel-pdf` (Chromium headless) frente a DomPDF con gráficos rasterizados según la RAM del VPS; generar en la cola `reports` con concurrencia 1.
- **Changelog**: Se pueden exportar informes en PDF.
- **Estado**: borrador
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-38
- **Tipo**: backend
- **Archivos**: `app/Services/Reports/PdfRenderer.php`, `resources/views/reports/`, `tests/Feature/Reports/Pdf/`
- **Cubre (tests)**: E2E-11, M-03
- **Verificación**:
  - `php artisan test --filter=ReportPdf` → pasa y el PDF generado no está vacío
  - lectura: M-03 revisa el PDF con datos reales

**Criterios de aceptación**
- [ ] PDF con KPIs, gráficos y tabla de publicaciones (CA-11)
- [ ] Consumo de memoria controlado

**Subtareas**
- [ ] Prueba del driver (RAM)
- [ ] Plantilla de informe
- [ ] Job en cola `reports`
- [ ] Tests

**Notas**: Decisión de driver pendiente (design.md §7).

### T-40 — UI de informes: generación, estado y descarga

- **Descripción**: Pantalla para elegir marca y rango de fechas, ver el estado de generación y descargar en PDF y CSV.
- **Changelog**: Pantalla de informes con descarga.
- **Estado**: borrador
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real —
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Reports/`, `app/Http/Controllers/ReportController.php`, `app/Jobs/GenerateReport.php`, `tests/Feature/Reports/`
- **Cubre (tests)**: E2E-11
- **Verificación**: `php artisan test --filter=Reports` → pasa

**Criterios de aceptación**
- [ ] Informe descargable en PDF y CSV (CA-11)
- [ ] Estado de generación visible

**Subtareas**
- [ ] Vista Inertia
- [ ] Controlador y job
- [ ] Descarga firmada
- [ ] Tests

### T-41 — Plantilla y diseño visual del informe

- **Descripción**: Cerrar secciones y branding del PDF (plantilla única por marca) y validar con el usuario.
- **Changelog**: Informe con diseño pulido.
- **Estado**: borrador
- **Tiempo humano**: est. 6h · real —
- **Tiempo IA (ejec.)**: est. 0.9h · real —
- **Supervisión**: est. 0.2h (≈25 % IA) · real —
- **Previsión IA**: 248k in / 25k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: frontend
- **Archivos**: `resources/views/reports/brand.blade.php`, `tests/Feature/Reports/`
- **Cubre (tests)**: M-03
- **Verificación**: lectura: M-03 aprobado por el usuario sobre un informe real

**Criterios de aceptación**
- [ ] El usuario aprueba el diseño del informe

**Subtareas**
- [ ] Ajuste de plantilla
- [ ] Revisión con el usuario

**Notas**: Incógnita: secciones y branding sin definir en la spec.

---

## Fase 9 — MCP estadísticas/informes y validación final

**Estado**: borrador · **Estimado**: 16h · **Real**: — · **Coste est.**: 960 € + tokens (⚠️ verificar precio) · **Tokens est.**: 728k

### T-42 — Herramientas MCP: estadísticas e informes

- **Descripción**: Añadir las herramientas de lectura de estadísticas y generación de informes al servidor MCP.
- **Changelog**: El agente puede leer estadísticas y pedir informes.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-30, T-40, T-35
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/`, `tests/Feature/Mcp/Tools/`
- **Cubre (tests)**: API-01, API-03
- **Verificación**: `php artisan test --filter=McpStats` → pasa (estadísticas e informe vía MCP, con aislamiento)

**Criterios de aceptación**
- [ ] Estadísticas e informes por MCP sobre datos del usuario (CA-12)

**Subtareas**
- [ ] Tools de estadísticas
- [ ] Tool de informes
- [ ] Tests

### T-43 — Validación final: suite E2E, pruebas manuales y revisión de seguridad

- **Descripción**: Ejecutar el `test-plan.md` completo, las pruebas manuales con cuentas reales y una revisión de seguridad (aislamiento, cifrado, API keys).
- **Changelog**: Entrega validada de extremo a extremo.
- **Estado**: borrador
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real —
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-42, T-24, T-37
- **Tipo**: test
- **Archivos**: `tests/e2e/`, `docs/roadmap/2026-10-19-social-scheduler/test-plan.md`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07, E2E-08, E2E-09, E2E-10, E2E-11, E2E-12, M-01, M-02, M-03, M-04, M-05
- **Verificación**:
  - `npx playwright test` → los 12 E2E (E2E-01…E2E-12) pasan
  - lectura: test-plan.md con M-01…M-05 marcados con evidencia

**Criterios de aceptación**
- [ ] Todos los CA-01…CA-12 verificados
- [ ] Revisión de aislamiento y cifrado sin hallazgos críticos

**Subtareas**
- [ ] Suite Playwright
- [ ] Pruebas manuales con evidencia
- [ ] Revisión de seguridad

**Notas**: Las horas de pruebas por fase están dentro de cada tarea; aquí solo la ejecución final.
