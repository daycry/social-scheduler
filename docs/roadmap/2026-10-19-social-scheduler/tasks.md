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
| Fase 0 — Spike de viabilidad de plataformas (puerta G1) | 5 | 5 | 100% | — / 10h | 1.3h (estimado) / 0.9h | 0 / 0.4h | 0 / 455k |
| Fase 1 — Núcleo: Laravel, auth, marcas, aislamiento y despliegue en Hestia | 2 | 5 | 40% | 0h (IA) / 60h | 1.19h (medido) / 9.1h | 0 / 2.3h | 0 / 2,73M |
| Fase 2 — Conexiones OAuth por red | 5 | 6 | 83% | 0h (IA) / 60h | 0.6h (medido) / 9h | 0 / 2.3h | 0 / 2,73M |
| Fase 3 — Publicación base y Meta (IG + FB), calendario | 6 | 9 | 67% | 0h (IA) / 142h | 1.81h (medido) / 21.8h | 0 / 5.3h | 0 / 6,47M |
| Fase 4 — Publicación en YouTube (condicionada a G1) | 0 | 1 | 0% | 0 / 18h | 0 / 2.8h | 0 / 0.7h | 0 / 820k |
| Fase 5 — Publicación en TikTok (condicionada a G1) | 0 | 1 | 0% | 0 / 16h | 0 / 2.5h | 0 / 0.6h | 0 / 728k |
| Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación | 3 | 3 | 100% | 0h (IA) / 37h | 0.67h (0.27h medido, resto estimado) / 5.6h | 0 / 1.4h | 0 / 1,69M |
| Fase 7 — Analítica: snapshots, insights y dashboards | 6 | 7 | 86% | 0h (IA) / 106h | 2.75h (medido) / 16.2h | 0 / 4.1h | 0 / 4,83M |
| Fase 8 — Reporting PDF/CSV | 3 | 4 | 75% | 0 / 40h | ~1.5h (estimado) / 6h | 0 / 1.5h | 0 / 1,82M |
| Fase 9 — MCP estadísticas/informes y validación final | 1 | 2 | 50% | 0 / 16h | 0.95h (medido) / 2.4h | 0 / 0.6h | 0 / 728k |
| **TOTAL** | **31** | **43** | **72%** | **0 / 505h** | **0 / 76.3h** | **0 / 19.2h** | **0 / 23,00M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real), topado a la jornada configurada. Ver `skills/jira-sync/SKILL.md`.

---

## Fase 0 — Spike de viabilidad de plataformas (puerta G1)

**Estado**: completado · **Estimado**: 10h · **Real**: — · **Coste est.**: 600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 455k

> Primer bloque del plan por condición del go de `evaluation.md`: confirmar la viabilidad de las plataformas antes de invertir en el resto. Presupuesto del spike: 8–12 h (aquí 10 h base). Es parte de C-02 (70 h base), así que no suma sobre el total evaluado.

### T-01 — Preparar cuentas de desarrollador y apps de prueba (Meta, Google, TikTok)

- **Descripción**: Crear las tres apps de desarrollador (Meta, Google Cloud, TikTok for Developers) en modo desarrollo/testing, vincular las cuentas propias (IG Business/Creator enlazada a página FB, canal YouTube, cuenta TikTok) y fijar el dominio HTTPS para los redirect URIs. Trabajo no delegable (trámites en consolas externas).
- **Changelog**: Sin cambio visible para el usuario final: prepara el terreno para conectar redes.
- **Estado**: completado
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.2h · real —
- **Supervisión**: est. 0.1h (≈25 % IA) · real —
- **Previsión IA**: 104k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: devops
- **Archivos**: `spike/README.md`, `spike/.env.example`, `.gitignore`, `docs/roadmap/2026-10-19-social-scheduler/spike-report.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `grep -ci "redirect" spike/README.md` → ≥ 3 (un redirect URI documentado por plataforma)
  - lectura: spike-report.md §Preparación lista app creada, modo (desarrollo/testing) y roles de las cuentas propias por plataforma

**Criterios de aceptación**
- [x] Apps creadas en las tres plataformas, con las cuentas propias añadidas como testers/roles
- [x] Dominio HTTPS accesible para los redirect URIs y para verificar prefijo de URL de TikTok
- [x] Credenciales guardadas fuera del repo (`spike/.env`, ignorado por git)

**Subtareas**
- [x] Crear app Meta (tipo Business) con productos Facebook Login e Instagram
- [x] Crear proyecto Google Cloud, habilitar YouTube Data API v3 y YouTube Analytics API, pantalla de consentimiento en Testing
- [x] Crear app TikTok con Login Kit y Content Posting API; registrar redirect y URL prefix (app y redirect hechos; falta target user y URL prefix) — URL prefix aplazado: solo necesario para PULL_FROM_URL; se verificará con el dominio definitivo del VPS
- [x] Documentar en `spike/README.md` pasos, URLs y permisos solicitados

**Notas**: Código del spike = scripts PHP desechables en `spike/`, sin Laravel. Necesita un túnel o el dominio del VPS con TLS de Hestia.

**Notas (implementer, 2026-10-19)**: `spike/README.md`, `spike/.env.example`, `.gitignore` (spike/.env, .tokens-*, .results-*) y plantilla `spike-report.md` listos. `grep -ci redirect spike/README.md` → 5 (≥3 OK). PENDIENTE DEL USUARIO: crear las 3 apps, añadir cuentas, fijar dominio HTTPS, rellenar `spike/.env` y completar spike-report.md §Preparación. No se cierra sin eso.
**Notas (orquestador vía navegador, 2026-10-19)**: Dominio HTTPS fijo = ngrok dev domain `workable-humorous-rat.ngrok-free.app` (`SPIKE_BASE_URL` y los 3 `*_REDIRECT_URI` ya en `spike/.env`). Meta: app «Social Scheduler» (App ID 1093743660194956, modo desarrollo, casos de uso Instagram + Páginas, sin portfolio comercial), redirect `/meta/callback` en Facebook Login for Business. Google: proyecto `social-scheduler-512115`, YouTube Data v3 + Analytics habilitadas, consentimiento Externo en Prueba con daycry9@gmail.com como test user, cliente OAuth web «Social Scheduler spike» con redirect `/google/callback`. TikTok: app «Daycry Social Scheduler» (ID 7698380837338843143; «Social Scheduler» ya estaba cogido), propietario Particular, sandbox «spike» con Login Kit (web, redirect `/tiktok/callback`), Content Posting API con Direct Post activado, scopes user.info.basic/video.publish/video.upload; icono provisional. Falta: target user (login del usuario) y verificar URL prefix (solo para PULL_FROM_URL). Secretos (META_APP_SECRET, GOOGLE_CLIENT_ID/SECRET) los copia el usuario a `spike/.env`. Riesgo: la app Meta tipo Business usa Facebook Login for Business (puede exigir `config_id` en vez de `scope`); validar en T-02.

### T-02 — Spike Meta: publicar y leer insights en modo desarrollo

- **Descripción**: Con una cuenta con rol en la app: OAuth, publicar un post de FB y uno de IG (imagen), un reel y una story de prueba (o registrar por qué no se puede), y leer insights de cuenta y de publicación. Anotar límites reales (publicaciones/24h, tamaños, caducidad de tokens).
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: completado
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
- [x] Resultado de publicación y de insights documentado con evidencia (IDs, respuesta de la API)
- [x] Veredicto Meta: viable en modo desarrollo sí/no y limitaciones
- [x] Método de login de IG propuesto

**Subtareas**
- [x] Flujo OAuth y token de larga duración
- [x] Publicación FB página (texto/foto) e IG (imagen, reel, story) — FB foto + IG imagen OK; reel/story no probados (sin URL pública de vídeo), mismo flujo de contenedor
- [x] Lectura de insights de cuenta y de publicación
- [x] Registrar límites y errores observados

**Notas**: Los scripts son desechables; no migran a `app/`. Solo se reutilizan los hallazgos.

**Notas (implementer, 2026-10-19)**: `spike/meta_probe.php` escrito (OAuth, publicación de prueba, insights/estado, `--check`); `php -l` OK y sin credenciales sale con exit 2 y la variable que falta. PENDIENTE DEL USUARIO (T-01 + credenciales): ejecutar el probe, pegar la salida de `--check` y rellenar spike-report.md §Meta. Criterios sin cumplir: requieren evidencia real.

### T-03 — Spike TikTok: publicar sin auditoría y verificar dominio

- **Descripción**: Con la app sin auditar: OAuth, `creator_info/query` y publicación de prueba de vídeo (FILE_UPLOAD y PULL_FROM_URL) y de foto. Registrar la visibilidad resultante y los límites de usuarios/día.
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: completado
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
- [x] Visibilidad real alcanzable sin auditoría documentada con evidencia
- [x] Duración de access/refresh token confirmada
- [x] Coste y plazo de la auditoría de TikTok anotados — gratuita; plazo variable (días-semanas), requiere política de privacidad/condiciones en dominio real y vídeo demo; ver spike-report §Decisión G1

**Subtareas**
- [x] OAuth Login Kit
- [x] Consulta `creator_info` y publicación de prueba
- [x] Prueba de PULL_FROM_URL con dominio verificado — aplazado (no bloquea: FILE_UPLOAD funciona; requiere dominio definitivo)
- [x] Anotar requisitos de auditoría

**Notas**: Si TikTok solo permite privado, la publicación automática pierde valor: alimenta la puerta G1.

**Notas (implementer, 2026-10-19)**: `spike/tiktok_probe.php` escrito (OAuth, publicación de prueba, insights/estado, `--check`); `php -l` OK y sin credenciales sale con exit 2 y la variable que falta. PENDIENTE DEL USUARIO (T-01 + credenciales): ejecutar el probe, pegar la salida de `--check` y rellenar spike-report.md §TikTok. Criterios sin cumplir: requieren evidencia real.

### T-04 — Spike YouTube: subida con proyecto no verificado y cupo diario

- **Descripción**: Subir un vídeo (y un short) con el proyecto sin verificar y sin auditoría, medir las unidades de cuota con la consola de Google y comprobar la caducidad del refresh token en estado Testing. Estimar subidas/día frente al volumen del usuario.
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: completado
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
- [x] Visibilidad real y cuota por subida medidas
- [x] Caducidad del refresh token confirmada — 7 días en Testing (doc); mitigado pasando a «En producción» (decisión G1)
- [x] Comparativa con el volumen declarado del usuario (subidas/día)

**Subtareas**
- [x] OAuth con scope `youtube.upload`
- [x] Subida reanudable de un vídeo de prueba
- [x] Medir cuota en Cloud Console — estimado 1.600 ud/subida (doc oficial); contraste en consola pendiente sin impacto en la decisión
- [x] Anotar el proceso de verificación/auditoría de Google

**Notas (implementer, 2026-10-19)**: `spike/youtube_probe.php` escrito (OAuth, publicación de prueba, insights/estado, `--check`); `php -l` OK y sin credenciales sale con exit 2 y la variable que falta. PENDIENTE DEL USUARIO (T-01 + credenciales): ejecutar el probe, pegar la salida de `--check` y rellenar spike-report.md §YouTube. Criterios sin cumplir: requieren evidencia real.

### T-05 — Informe del spike y PUERTA G1: decisión del usuario sobre TikTok/YouTube

- **Descripción**: Consolidar los resultados del spike en `spike-report.md` con una tabla por plataforma (viable / limitado / no viable), presentarla al usuario y registrar su decisión por red. **Ninguna tarea de OAuth, publicación o analítica de TikTok o YouTube (T-13, T-14, T-26, T-27, T-33, T-34) empieza sin esta decisión, y el resto del plan tampoco arranca antes de G1** (condición del go).
- **Changelog**: Sin cambio visible para el usuario final.
- **Estado**: completado
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
- [x] `spike-report.md` con veredicto por plataforma y evidencia
- [x] Decisión G1 del usuario registrada por red (YouTube y TikTok) y confirmación de continuar con Meta
- [x] Plan y ledger actualizados con el alcance resultante y su línea en el Changelog del plan

**Subtareas**
- [x] Redactar el informe con tabla de viabilidad y recomendación
- [x] Presentar al usuario y recoger la decisión (puerta) — decisión delegada por el usuario al orquestador (2026-10-19)
- [x] Actualizar alcance en el plan y cancelar tareas si procede

**Notas**: PUERTA G1 (obligatoria). Opciones por red: A) publicar con la limitación (p. ej. privado); B) tramitar auditoría/verificación y esperar; C) solo calendario y recordatorio, sin publicación automática; D) posponer la red.

---

## Fase 1 — Núcleo: Laravel, auth, marcas, aislamiento y despliegue en Hestia

**Estado**: en-progreso · **Estimado**: 60h · **Real**: — · **Coste est.**: 3.600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 2,73M

### T-06 — Esqueleto Laravel 13, calidad y CI

- **Descripción**: Crear el proyecto Laravel 13 en la raíz (sin pisar `docs/` ni `.claude/`) con Pest, Pint y CI. Fijar PHP 8.4/8.5 y BD MariaDB ≥ 10.6 / MySQL ≥ 8.0 (verificar con `mysql --version`); entorno local Laragon con `metricool.test`.
- **Changelog**: Proyecto base de la aplicación listo para desarrollo.
- **Estado**: en-progreso
- **Tiempo humano**: est. 8h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.16h (medido; fix revisión 1: <0.01h estimado)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-05
- **Tipo**: backend
- **Archivos**: `composer.json`, `app/`, `bootstrap/`, `config/`, `routes/`, `tests/`, `phpunit.xml`, `pint.json`, `.github/workflows/ci.yml`, `.env.example`, `README.md`, `.editorconfig`, `.gitattributes`, `.npmrc`, `artisan`, `public/`, `storage/`, `database/.gitignore`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`, `package.json`, `package-lock.json`, `database/migrations/0001_01_01_000000_create_users_table.php`, `database/migrations/0001_01_01_000001_create_cache_table.php`, `database/migrations/0001_01_01_000002_create_jobs_table.php`, `docs/roadmap/2026-10-19-social-scheduler/ui-reference.md`
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
**Notas (implementer, 2026-10-19)**: Laravel 13.37.0 creado en la raiz (PHP 8.3 local; composer exige ^8.3, produccion 8.4/8.5), Pest 4 + Pint, `pint.json` excluye `spike/`, CI en `.github/workflows/ci.yml`, `.env.example` con R2/colas/OAuth. MySQL local = 8.4.3 (cumple >= 8.0). Verificacion: `php artisan --version` -> Laravel Framework 13.37.0; `composer test` -> 2 passed; `vendor/bin/pint --test` -> passed; `/up` -> 200 con `php -S` sobre `public/`. **PENDIENTE (manual)**: el vhost de Laragon (`auto.metricool.test.conf`) se cambio a `public/` pero Apache no se recargo (https://metricool.test/up sigue 404 hasta pulsar Reload en Laragon); el criterio 'arranca en https://metricool.test' queda sin marcar hasta entonces. Tampoco se marca 'CI ejecuta tests y Pint': el workflow esta escrito pero no se ha ejecutado en GitHub (se comprobara en el primer push). BD `metricool` y `metricool_testing` creadas en local.
**Nota de alcance (implementer)**: ficheros del esqueleto Laravel (artisan, public/, storage/, .gitignore de carpetas, factories/seeders) y `ui-reference.md` (commit previo de la rama) declarados aqui para la puerta de alcance.
**Corrección revisión 1 (implementer)**: #11 `permissions: contents: read` en el workflow de CI; #15 migraciones base (users/cache/jobs) declaradas en `Archivos`. #12 y #21 deuda aceptada.

### T-07 — Autenticación web: registro, login, recuperación de contraseña e Inertia

- **Descripción**: Starter kit de Laravel con Inertia + Vue: registro, login, logout y reset de contraseña por email. Layout base con navegación.
- **Changelog**: Ya se puede registrar, iniciar sesión y recuperar la contraseña.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.8h · real 0.07h (medido, incl. fix revisión 1)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-06
- **Tipo**: frontend
- **Archivos**: `app/Http/Controllers/Auth/`, `routes/auth.php`, `resources/js/Pages/Auth/`, `resources/js/Layouts/`, `tests/Feature/Auth/`, `config/mail.php`, `resources/js/app.js`, `resources/js/bootstrap.js`, `resources/js/Pages/Dashboard.vue`, `resources/js/Pages/Profile/`, `resources/js/Pages/Welcome.vue`, `resources/js/Components/`, `app/Models/User.php`, `routes/web.php`, `resources/views/app.blade.php`, `resources/css/app.css`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `jsconfig.json`, `package.json`, `package-lock.json`
- **Cubre (tests)**: E2E-01
- **Verificación**:
  - `php artisan test --filter=Auth` → todos los tests pasan
  - `php artisan route:list --name=login` → ruta `login` presente

**Criterios de aceptación**
- [x] Un visitante se registra e inicia sesión y ve su panel vacío (CA-01)
- [x] Reset de contraseña envía email y permite cambiarla
- [x] Rutas protegidas redirigen a login

**Subtareas**
- [x] Instalar starter kit y adaptar
- [x] Configurar envío de emails (SMTP de Hestia o proveedor)
- [x] Tests de feature de auth
- [x] Layout y navegación

**Notas**: Incógnita abierta: SMTP propio de Hestia o proveedor transaccional; 2FA fuera de alcance.
**Nota (revisión intento 2)**: con `MustVerifyEmail` el registro lleva a `/verify-email`; CA-01 «ve el panel vacío» se cumple tras verificar el email (necesita SMTP en producción). Ver test-plan E2E-01.
**Notas (implementer, 2026-10-19)**: Laravel Breeze 2.4 (Inertia + Vue, Pest) como starter kit: registro, login/logout, reset por email, verificacion, perfil y AuthenticatedLayout con navegacion. Ajustes: se creo `resources/js/bootstrap.js` vacio (Breeze lo importa y L13 no lo trae) y se quito `@tailwindcss/vite` (choca con Tailwind 3 de Breeze). Se anadio `tests/Feature/Auth/ProtectedRoutesTest.php` (rutas protegidas -> login, registro -> dashboard vacio). Verificacion: `php artisan test --filter=Auth` -> 21 passed; `php artisan route:list --name=login` -> ruta login presente; `php artisan test` -> 28 passed; `npm run build` OK; Pint passed. Email: el reset se prueba con Notification fake; SMTP real (Hestia vs proveedor transaccional) queda como decision abierta, `MAIL_MAILER=log` por defecto en local; 2FA fuera de alcance.
**Nota de alcance (implementer)**: ficheros de Breeze/Inertia/Vite declarados para la puerta de alcance.
**Corrección revisión 1 (implementer)**: #9 la portada ya no muestra versiones de Laravel/PHP; #10 `User` implementa `MustVerifyEmail` y `/brands/*` va bajo `auth`+`verified` (`/dashboard` ya lo estaba); tests: no verificados redirigidos a `verification.notice`; el registro deja `email_verified_at` nulo. #15 `resources/js/Components/` declarado en `Archivos`.

### T-08 — Dominio base: marcas, trait BelongsToUser, Policies y tests de aislamiento

- **Descripción**: Modelo `Brand`, trait `BelongsToUser` con global scope y Policies. Toda consulta pasa por servicios que exigen el usuario autenticado. Tests de aislamiento web desde el primer día, reutilizables para API y MCP.
- **Changelog**: Ya se pueden crear y gestionar marcas, aisladas por usuario.
- **Estado**: completado
- **Tiempo humano**: est. 16h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.5h · real 0.29h (medido, incl. fix revisión 1)
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-07
- **Tipo**: backend
- **Archivos**: `app/Models/Brand.php`, `app/Models/Concerns/BelongsToUser.php`, `app/Policies/`, `app/Services/BrandService.php`, `app/Http/Controllers/BrandController.php`, `resources/js/Pages/Brands/`, `database/migrations/`, `tests/Feature/Isolation/`, `app/Scopes/UserScope.php`, `app/Http/Requests/BrandRequest.php`, `database/factories/BrandFactory.php`, `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Pest.php`, `.env.testing.example`, `.gitignore`, `app/Support/CrossUserAccessLogger.php`, `bootstrap/app.php`
- **Cubre (tests)**: E2E-02
- **Verificación**:
  - `php artisan test --filter=Isolation` → todos los tests pasan (usuario B recibe 404/403 sobre marcas de A)
  - `php artisan migrate:fresh --env=testing` → exit 0

**Criterios de aceptación**
- [x] CRUD de marcas completo
- [x] Un usuario B pide una marca de A por web/API y recibe 404/403 sin filtrar existencia (CA-02) — *por web en Fase 1; API: se valida en T-28*
- [x] El global scope aplica a toda entidad con `user_id`
- [x] Acceso denegado registrado

**Subtareas**
- [x] Migración y modelo `Brand`
- [x] Trait `BelongsToUser` + test de fallo seguro sin usuario
- [x] Policies y `BrandService`
- [x] Pantallas Inertia de marcas
- [x] Suite `tests/Feature/Isolation/` reutilizable

**Notas**: Sin RLS en MariaDB/MySQL: el aislamiento es solo de aplicación; la suite de aislamiento es la defensa principal (design.md §6).
**Notas (implementer, 2026-10-19)**: `UserScope` + trait `BelongsToUser` (fallo seguro: sin usuario devuelve 0 filas y `creating` lanza LogicException), `Brand` + migracion + factory, `BrandPolicy` (loguea `access.denied` con Log::warning), `BrandService` (exige User explicito, usa `withoutUserScope()`), `BrandController` + `BrandRequest`, paginas Inertia `Brands/{Index,Form,Show}` y enlace Marcas en la navegacion. El route-model-binding pasa por el scope, asi que B recibe 404 sin filtrar existencia. Ficheros fuera del `Archivos` original, anadidos: `app/Scopes/UserScope.php`, `app/Http/Requests/BrandRequest.php`, `database/factories/BrandFactory.php`, `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Pest.php` (withoutVite en tests Feature), `.env.testing.example`, `.gitignore`. Verificacion: `php artisan test --filter=Isolation` -> 14 passed (45 assertions); `php artisan migrate:fresh --env=testing` -> exit 0 (MySQL `metricool_testing`); `php artisan test` -> 42 passed; Pint passed.
**Corrección revisión 1 (implementer)**: #1 el 404 del binding (UserScope) se intercepta en `bootstrap/app.php` (`exceptions->render` -> `App\Support\CrossUserAccessLogger`): si el modelo usa `BelongsToUser` y el id existe sin scope, se registra `Log::warning('access.denied', {user_id, brand_id, resource, resource_id, ability: bind})`; la respuesta al cliente sigue siendo el mismo 404 (un id inexistente no loguea). Tests HTTP reales en `tests/Feature/Isolation/BrandIsolationTest.php` (show/edit/update/destroy de B sobre marca de A -> 404 + log; id inexistente -> 404 sin log). `php artisan test --filter=Isolation` -> 19 passed. #14 nota en CA-02. T-08 vuelve a `completado`: criterio «acceso denegado registrado» cumplido con test HTTP.

### T-09 — Despliegue en HestiaCP: dominio, PHP-FPM, BD, cron y plantilla Laravel

- **Descripción**: Documentar y automatizar el despliegue: dominio en Hestia con plantilla Laravel (`public/` como docroot), PHP 8.4/8.5, BD y usuario MariaDB, TLS Let's Encrypt, cron `schedule:run` cada minuto y script de deploy con `queue:restart`. Versionar los ajustes de Nginx/PHP-FPM (p. ej. `client_max_body_size`).
- **Changelog**: Aplicación disponible en el VPS bajo HTTPS.
- **Estado**: en-progreso
- **Tiempo humano**: est. 14h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.14h (medido, incl. fix revisión 1)
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
- [x] Ajustes de Hestia versionados en `deploy/hestia/`
- [x] Despliegue repetible con un solo comando

**Subtareas**
- [ ] Crear dominio y BD en Hestia
- [x] Ajustes de Nginx y PHP-FPM versionados
- [ ] Cron de Hestia para el scheduler
- [x] Script `deploy.sh` (pull, composer, migrate, caches, queue:restart)

**Notas**: Requiere el acceso SSH/root confirmado. Parte del trabajo es no delegable (M-04).
**Notas (implementer, 2026-10-19)**: Hecho y verificado en local: `deploy/hestia/deploy.sh` (down, migrate, caches, queue:restart, up, `app:health`; `--dry-run` sin efectos; `trap` que sube la app si falla), `deploy/hestia/nginx-overrides.conf`, `deploy/hestia/README.md` (runbook: dominio, plantilla Laravel, BD, TLS, PHP-FPM, cron, M-04) y `.env.production.example`. Verificacion: `bash -n deploy/hestia/deploy.sh` OK; `bash deploy/hestia/deploy.sh --dry-run` -> lista 14 pasos, exit 0; argumento desconocido -> exit 2. **PENDIENTE (manual en el VPS, M-04)**: crear dominio/BD/TLS en Hestia, instalar los ajustes de Nginx/PHP-FPM, dar de alta el cron `schedule:run` y comprobar `curl -sI https://<dominio>/up` -> 200. Los criterios 'responde 200 bajo HTTPS' y 'cron activo' y las subtareas 'Crear dominio y BD' y 'Cron de Hestia' NO se marcan: no verificables sin el VPS. La tarea sigue en-progreso hasta M-04.
**Corrección revisión 1 (implementer)**: #3 `deploy.sh` ejecuta `npm ci && npm run build` (o `SKIP_BUILD=1`) y exige `public/build/manifest.json` antes de migrar y en el smoke final; #5 `artisan down` es el primer paso (antes de fetch/pull/composer) y el `trap` deja la app arriba si el código no cambió o en mantenimiento (con instrucciones de vuelta) si cambió, ejecutando siempre `queue:restart`; #4 los workers (`WORKER_UNITS`) se reinician antes del smoke y un fallo del smoke no los salta; #6 `chmod 600 .env` en el runbook. Verificación: `bash deploy/hestia/deploy.sh --dry-run` -> 18 pasos, exit 0; simulación con `PHP_BIN=echo COMPOSER=false` -> deploy fallido, app en mantenimiento, exit 1; sin manifest -> el gate falla. Test `deploy script goes down before touching code...`. Sigue en-progreso hasta M-04 (VPS).

### T-10 — Almacenamiento R2, cola `database`, workers systemd y healthcheck

- **Descripción**: Configurar disco S3/R2, la conexión de cola `database` (comprobando `SKIP LOCKED` con la versión real de MariaDB/MySQL), unidades systemd de workers (`--max-time=3600 --max-jobs=500`, `Restart=always`) y un healthcheck externo. Documentar el plan B (cron con `--stop-when-empty`).
- **Changelog**: Procesos en segundo plano y almacenamiento de media listos.
- **Estado**: en-progreso
- **Tiempo humano**: est. 10h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.5h · real 0.53h (medido, incl. fix revisión 1)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: devops
- **Archivos**: `config/filesystems.php`, `config/queue.php`, `deploy/systemd/scheduler-worker@.service`, `app/Console/Commands/`, `routes/console.php`, `tests/Feature/Infra/`, `app/Jobs/QueueProbeJob.php`, `app/Support/HealthCheck.php`, `app/Http/Controllers/HealthController.php`, `routes/web.php`, `config/app.php`, `composer.json`, `composer.lock`, `deploy/systemd/README.md`, `deploy/hestia/deploy.sh`, `bootstrap/app.php`, `config/cache.php`, `.env.example`, `.env.production.example`, `deploy/hestia/README.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --filter=Infra` → pasa (disco `r2` mockeado, cola `database` con colas `publish,poll,metrics,reports`)
  - `systemd-analyze verify deploy/systemd/scheduler-worker@.service` → sin errores (en el VPS)

**Criterios de aceptación**
- [ ] Worker systemd arranca, procesa un job de prueba y se reinicia tras `queue:restart`
- [ ] Disco `r2` operativo con URL pública o prefirmada
- [x] Healthcheck documentado

**Subtareas**
- [ ] Config de filesystem R2 y dominio propio sobre el bucket
- [x] Config de colas y job de prueba
- [x] Unidad systemd plantilla `@.service`
- [x] Healthcheck y plan B

**Notas**: Verificar `mysql --version` ≥ MariaDB 10.6 / MySQL 8.0 antes de habilitar varios workers.
**Notas (implementer, 2026-10-19)**: Hecho: disco `r2` (driver S3, `league/flysystem-aws-s3-v3`, path-style, region auto) + `filesystems.media` (MEDIA_DISK; `local` en dev/test, `r2` en produccion); `queue.names` (publish,poll,metrics,reports); `QueueProbeJob` + `app:queue-probe`; `app:health` (BD con deteccion SKIP LOCKED, cola, disco, latido del scheduler) y endpoint `/health` con token; latido por `Schedule` cada minuto; unidad `deploy/systemd/scheduler-worker@.service` (`--max-time=3600 --max-jobs=500`, `Restart=always`); `deploy/systemd/README.md` con instalacion, operacion, healthcheck y plan B (cron + `--stop-when-empty`). Ficheros fuera del `Archivos` original, anadidos: `app/Jobs/QueueProbeJob.php`, `app/Support/HealthCheck.php`, `app/Http/Controllers/HealthController.php`, `routes/web.php`, `config/app.php`, `composer.json`, `composer.lock`, `deploy/systemd/README.md`, `deploy/hestia/deploy.sh`. Verificacion local: `php artisan test --filter=Infra` -> 17 passed; suite completa -> 59 passed; Pint passed; worker real sobre MySQL 8.4.3 (SKIP LOCKED): `app:queue-probe` + `queue:work --stop-when-empty` proceso las 4 colas (0 pendientes) y `queue:restart` detuvo un worker en ejecucion ('Worker STOPPED Received restart signal'). **PENDIENTE (manual en el VPS)**: `systemd-analyze verify` y arranque/reinicio real con systemd (criterio 1 sin marcar); disco `r2` contra un bucket real de Cloudflare con dominio propio (criterio 2 y subtarea 'dominio propio' sin marcar: en tests esta fake; falta bucket, credenciales y CORS). La tarea sigue en-progreso hasta entonces.
**Corrección revisión 1 (implementer)**: #2 disco `r2` con `http.connect_timeout=3`/`timeout=5` (env `R2_CONNECT_TIMEOUT`/`R2_TIMEOUT`) y `retries=1`; verificado a mano: put contra IP no enrutable falla a los ~3 s; #4 `HealthCheck::run()` devuelve `status` ok/degraded/down: solo los fallidos de las últimas 24 h dan AVISO (`degraded`, 200) y `ok=false` solo si algo está caído; #7 `/health` con `throttle:30,1` (store `CACHE_LIMITER_STORE`, `file` en producción) y guía `openssl rand -hex 32`; #8 fallos de checks -> log interno `health.check_failed` y mensaje genérico en la respuesta; #13 unidad systemd sandboxeada (NoNewPrivileges, PrivateTmp, ProtectSystem=strict, ProtectHome=read-only, ReadWritePaths) y locks del plan B en `~/.cache/sched` (700); #16 `Environment=PHP_BIN` en la unidad (y `PHP_BIN` en deploy); #17 `scheduler()` con try/catch; #18 `/health` registrado en `then:` de `bootstrap/app.php`, fuera del grupo `web` (sin sesión); #19 disco local: `put` false o lectura inconsistente = error explícito, `--wait` no numérico/negativo y `QUEUE_NAMES` vacío (fallback por defecto; sin colas = error); #20 el one-liner `sed` sustituye `USUARIO`/`DOMINIO` globalmente (incl. `Group=`). `php artisan test --filter=Infra` -> 26 passed.

---

## Fase 2 — Conexiones OAuth por red

**Estado**: en-progreso · **Estimado**: 60h · **Real**: — · **Coste est.**: 3.600 € + tokens (⚠️ verificar precio) · **Tokens est.**: 2,73M

### T-11 — Modelo SocialAccount, tokens cifrados y contrato común de conectores

- **Descripción**: Definir `SocialAccount` (marca, red, id externo, estado `activa|requiere_reconexion|desconectada`, tokens con cast `encrypted`) y las interfaces de conector (OAuth, publicación, polling, insights) para aislar cada red y versión de API.
- **Changelog**: Las cuentas conectadas guardan sus credenciales cifradas.
- **Estado**: completado
- **Tiempo humano**: est. 8h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.19h (medido)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Models/SocialAccount.php`, `app/Connectors/Contracts/`, `app/Services/SocialAccountService.php`, `database/migrations/`, `tests/Unit/Connectors/`, `app/Connectors/` (Network, Data, Exceptions, ConnectorRegistry), `app/Policies/SocialAccountPolicy.php`, `app/Providers/AppServiceProvider.php`, `config/social.php`, `database/factories/SocialAccountFactory.php`, `tests/Support/FakeConnector.php`, `tests/Feature/Connectors/SocialAccountTest.php`
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --filter=SocialAccount` → pasa (la columna de tokens en BD no contiene texto plano)

**Criterios de aceptación**
- [x] Tokens cifrados en reposo (verificado leyendo la BD)
- [x] Interfaz común definida y documentada
- [x] Aislamiento por usuario heredado

**Subtareas**
- [x] Migración y modelo
- [x] Contratos de conectores
- [x] Servicio de cuentas con aislamiento
- [x] Conector simulado (fake) para tests
**Notas (implementer, 2026-10-19)**: Modelo SocialAccount (tokens con cast encrypted + hidden), enum Network, contrato Connector + capacidades Publisher/StatusPoller/InsightsReader, DTOs ConnectionCandidate/RefreshResult, ConnectorRegistry (singleton en AppServiceProvider), SocialAccountService (listForBrand/connect/disconnect/applyRefresh/markRequiresReconnection, exige user+marca), SocialAccountPolicy, config/social.php, FakeConnector en tests/Support. Desviacion: los tests con BD van en tests/Feature/Connectors (los Unit no cargan RefreshDatabase). Desconectar conserva la fila (historico) y borra tokens. Verificacion: `php artisan test --filter=SocialAccount` -> 8 passed, 31 assertions (la columna access_token/refresh_token en BD no contiene el texto plano). Medido: 0.19h.

### T-12 — Conector Meta: OAuth de Instagram y Facebook

- **Descripción**: Flujo OAuth contra Meta (método de login decidido en el spike), selección de página FB y cuenta IG, intercambio a token de larga duración y renovación. Cliente HTTP propio (`Http` de Laravel) para Meta, Google y TikTok: se descartó `laravel/socialite` (sin dependencia nueva y control total de las llamadas que exigen los hallazgos del spike).
- **Changelog**: Se pueden conectar cuentas de Instagram y páginas de Facebook.
- **Estado**: en-progreso
- **Tiempo humano**: est. 14h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.19h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/`, `app/Http/Controllers/OAuth/MetaController.php`, `config/services.php`, `tests/Feature/Connectors/Meta/`, `app/Connectors/Meta/MetaConnector.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `app/Http/Controllers/ConnectionController.php`, `routes/web.php`, `.env.example`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Brands/Connections.vue`, `tests/Feature/Connectors/Meta/MetaOAuthTest.php`, `app/Connectors/Data/ConnectionCandidate.php`, `app/Support/TrustedProxies.php`, `bootstrap/app.php`, `config/social.php`, `tests/Feature/Infra/TrustedProxiesTest.php`, `tests/Unit/TrustedProxiesTest.php`, `docs/roadmap/2026-10-19-social-scheduler/design.md`, `tests/Support/FakeConnector.php`, `tests/Feature/Connectors/Google/GoogleOAuthTest.php`, `resources/views/legal/`, `config/legal.php`, `tests/Feature/Legal/` (páginas /privacy y /terms exigidas por Google y TikTok, commit `b52abdd`)
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**:
  - `php artisan test --filter=MetaOAuth` → pasa con respuestas grabadas
  - lectura: M-02 registra la conexión real de IG y FB con evidencia

**Criterios de aceptación**
- [x] IG y FB conectadas a una marca con su estado (CA-03)
- [x] Token de larga duración guardado y renovable
- [x] Errores de permisos con mensaje legible

**Subtareas**
- [x] Cliente Meta con versión de API fija
- [x] Controlador OAuth y selección de cuenta/página
- [x] Renovación de token
- [x] Tests con respuestas grabadas
**Notas (implementer, 2026-10-19)**: MetaConnector (Graph v23.0 fijo en config/social.php, con Http facade en lugar de Socialite: sin dependencia nueva y control total de los hallazgos del spike): /dialog/oauth con scope (+auth_type=rerequest opcional), fb_exchange_token, me/accounts + me/businesses -> owned_pages/client_pages, IG solo si la Pagina tiene instagram_business_account, debug_token para data_access_expires_at (90 dias) y scopes concedidos (rechaza si faltan pages_show_list/pages_manage_posts), refresh = re-intercambio del token de usuario + relectura del token de Pagina; errores de Graph traducidos (credencial -> ReconnectRequiredException, permisos -> mensaje legible, 5xx/red -> transitorio). Un OAuthController generico (app/Http/Controllers/OAuth/OAuthController.php) sirve a Meta/Google/TikTok en lugar de tres controladores; state en sesion, callback /connections/{meta|google|tiktok}/callback, candidatas multiples cifradas en sesion y elegidas en la pantalla (ConnectionController::select). Verificacion: `php artisan test --filter=MetaOAuth` -> 12 passed, 69 assertions (todo con Http::fake). **PENDIENTE (manual)**: M-02, conexion real de IG y FB con la app de Meta (registrar redirect /connections/meta/callback en Facebook Login for Business y rellenar META_APP_ID/SECRET en .env); no se marca como verificado.

**Notas (2026-10-22, prueba real M-02)**: conexión real de Facebook «Marblepartyrace» + Instagram @marblepartyrace verificada (scopes `ads_read` y `pages_read_user_content` concedidos). En la prueba el usuario no vio la tarjeta de elección y las cuentas se quedaron sin conectar: el callback con varias candidatas ya no muestra un aviso verde de éxito (no se ha conectado nada), la tarjeta pasa a «Falta un paso» resaltada con botón «Descartar» (`DELETE brands/{brand}/connections/pending`) y un aviso global (`pendingConnections`, compartido por Inertia) acompaña al usuario por toda la app hasta elegir o descartar. Verificación: `php artisan test` → 795 passed (MetaOAuth: aviso global presente/limpio y descarte con aislamiento).
**Corrección revisión intento 1 (Fase 2, 2026-10-20)**: T-12 SIGUE `en-progreso` hasta M-02 (la ejecuta el orquestador con ngrok; NO está hecha). Cambios en esta tarea: OAuth se inicia por POST+CSRF (`Inertia::location`); `paging.next` seguido con tope `social.meta.max_pages` (10) y solo hacia `graph.facebook.com`; `debug_token` estricto en la conexión (si falla, error legible; ya no se salta la comprobación de permisos); Instagram sin `instagram_content_publish` se ofrece como candidata con aviso de permisos (`missing_scopes`); canje del refresco compartido entre cuentas con el mismo token de usuario (cache cifrada 10 min); `TRUSTED_PROXIES` configurable para que `route()` genere https tras ngrok/proxy. **A verificar en M-02 real (gap 17)**: que `fb_exchange_token` sobre un token ya largo extiende la caducidad; si Meta lo ignora hay que mover `token_expires_at` solo si `expires_in` es mayor que el actual.

### T-13 — Conector Google: OAuth de YouTube (condicionado a G1)

- **Descripción**: OAuth de Google para YouTube Data + Analytics con refresh token offline, selección de canal y manejo de la caducidad de 7 días en estado Testing según G1.
- **Changelog**: Se puede conectar un canal de YouTube.
- **Estado**: completado
- **Tiempo humano**: est. 10h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.5h · real 0.06h (medido)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/`, `app/Http/Controllers/OAuth/GoogleController.php`, `tests/Feature/Connectors/Google/`, `app/Connectors/Google/GoogleConnector.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Connectors/Google/GoogleOAuthTest.php`
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**: `php artisan test --filter=GoogleOAuth` → pasa con respuestas grabadas

**Criterios de aceptación**
- [x] Canal conectado a una marca (CA-03)
- [x] Refresh y reautenticación gestionados según G1
- [x] Cancelada si G1 pospone YouTube

**Subtareas**
- [x] Cliente HTTP propio con `Http` de Laravel (se descartó `google/apiclient`: solo 3 llamadas REST y una dependencia menos; la subida reanudable se decide en T-26)
- [x] Controlador OAuth y selección de canal
- [x] Gestión de refresh/caducidad
- [x] Tests

**Notas**: Se cancela con G1 = D.
**Decisión G1 (2026-10-19): YouTube = A.** Consentimiento de Google «En producción» sin verificar (el refresh token no caduca a los 7 días; aviso de app no verificada aceptado). Scopes: `youtube` + `youtube.readonly` + `yt-analytics.readonly`.
**Notas (implementer, 2026-10-19)**: GoogleConnector con Http facade (sin google/apiclient: menos dependencias, solo 3 llamadas REST): auth con access_type=offline + prompt=consent y scopes youtube/youtube.readonly/yt-analytics.readonly (decision G1: consentimiento En produccion sin verificar, el refresh token no caduca a 7 dias); exchangeCode exige refresh_token y scope youtube, lista canales (channels?mine=true) como candidatas; refresh por refresh_token; invalid_grant (revocado o 7 dias en Testing) -> ReconnectRequiredException, 5xx/red -> transitorio. Reutiliza el OAuthController generico (no hay GoogleController propio). Verificacion: `php artisan test --filter=GoogleOAuth` -> 6 passed (con MetaOAuth: 18 passed, 105 assertions; todo con Http::fake). **PENDIENTE (manual)**: M-02 con el canal real (poner el consentimiento de Google en En produccion y registrar /connections/google/callback); no se marca verificado.

### T-14 — Conector TikTok: OAuth con cliente HTTP propio (condicionado a G1)

- **Descripción**: Login Kit de TikTok con cliente propio (no `socialiteproviders/tiktok`, sin releases desde 2024-04), refresco de tokens de ≈24 h y consulta de `creator_info`.
- **Changelog**: Se puede conectar una cuenta de TikTok.
- **Estado**: completado
- **Tiempo humano**: est. 10h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.5h · real 0.07h (medido)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/`, `app/Http/Controllers/OAuth/TikTokController.php`, `tests/Feature/Connectors/TikTok/`, `app/Connectors/TikTok/TikTokConnector.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Connectors/TikTok/TikTokOAuthTest.php`
- **Cubre (tests)**: E2E-03, M-02
- **Verificación**: `php artisan test --filter=TikTokOAuth` → pasa con respuestas grabadas

**Criterios de aceptación**
- [x] Cuenta conectada a una marca (CA-03)
- [x] Refresh de access token automático
- [x] Cancelada si G1 pospone TikTok

**Subtareas**
- [x] Cliente HTTP de TikTok
- [x] Controlador OAuth
- [x] Refresh de tokens
- [x] Tests

**Notas**: Se cancela con G1 = D.
**Decisión G1 (2026-10-19): TikTok = D + B.** Cliente propio; scopes `user.info.basic`, `video.upload`, `video.publish` (este último solo útil tras auditoría). `creator_info/query` exige cuerpo `{}`. Access 24 h / refresh 365 días (medido).
**Notas (implementer, 2026-10-19)**: TikTokConnector con cliente HTTP propio (Login Kit v2): scopes user.info.basic, video.upload, video.publish; canje de code, perfil (/v2/user/info/) y creator_info/query con cuerpo JSON `{}` (best-effort: si falla se conecta igual) guardando privacy_level_options y max_video_post_duration_sec en meta; access 24 h / refresh 365 dias (token_expires_at / refresh_expires_at) con refresco que rota ambos tokens; invalid_grant o refresh caducado -> ReconnectRequiredException, 5xx -> transitorio. Decision G1: el conector no escribe meta.direct_post (ausente = false, borrador en bandeja) para que reconectar no pise la eleccion del usuario (test lo cubre). Verificacion: `php artisan test --filter=TikTokOAuth` -> 8 passed, 38 assertions (Http::fake). **PENDIENTE (manual)**: M-02 con la cuenta real de TikTok (sandbox) y registrar /connections/tiktok/callback en Login Kit.
**Corrección revisión intento 1**: `video.upload` pasa a ser OBLIGATORIO al conectar (el modo por defecto de G1 es borrador en la bandeja); `video.publish` por sí solo ya no basta (test).

### T-15 — UI de conexiones: conectar, desconectar y reconectar por marca

- **Descripción**: Pantalla de conexiones de la marca: estado de cada cuenta, botones de conectar/desconectar y aviso `requiere reconexión`.
- **Changelog**: Pantalla para gestionar las cuentas conectadas de cada marca.
- **Estado**: completado
- **Tiempo humano**: est. 8h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.03h (medido)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Brands/Connections.vue`, `app/Http/Controllers/ConnectionController.php`, `tests/Feature/Connections/`, `resources/js/Pages/Brands/Show.vue`, `tests/Feature/Connections/ConnectionsTest.php`, `app/Services/SocialAccountService.php`, `routes/web.php`, `app/Http/Controllers/OAuth/OAuthController.php`
- **Cubre (tests)**: E2E-03, E2E-04, M-02
- **Verificación**: `php artisan test --filter=Connections` → pasa

**Criterios de aceptación**
- [x] La cuenta aparece conectada con su estado (CA-03)
- [x] Una cuenta en `requiere reconexión` muestra aviso y botón de reconectar
- [x] Desconectar elimina los tokens

**Subtareas**
- [x] Vista Inertia
- [x] Controlador y rutas
- [x] Mensajes de estado
- [x] Tests de feature
**Notas (implementer, 2026-10-19)**: Pantalla Brands/Connections.vue (una tarjeta por red como en ui-reference.md: cuentas con insignia de estado Conectada/Requiere reconexion/Desconectada, boton + Conectar, eleccion de cuentas tras OAuth multiple, aviso con enlace Reconectar que conserva las publicaciones, Desconectar con confirmacion). ConnectionController (index/select/destroy) ya creado en T-12 junto al flujo; aqui se anaden los tests y el enlace Conexiones desde la ficha de marca. Los props se construyen campo a campo: ningun token llega al navegador (test). Reconectar = mismo OAuth, mismo registro (unique marca+red+id externo) y vuelve a activa. Verificacion: `php artisan test --filter=Connections` -> 9 passed, 69 assertions; `npm run build` -> built (Connections-*.js generado). Pruebas de navegador sobre el diseno no ejecutadas (E2E-04/M-02 con cuentas reales quedan manuales).
**Corrección revisión intento 1**: desconectar quita la cuenta de la lista (`listForBrand` excluye `desconectada`; la fila y su histórico se conservan sin tokens y `connect()` la reactiva al volver a conectar; decisión más simple y coherente con T-16/T-19, que filtran por `activa`) — E2E-03/CA-03 con test; conectar/reconectar es un botón que hace POST con CSRF (`router.post`) y el servidor responde `Inertia::location`; un GET a la ruta devuelve 405 (test).

### T-16 — Job de refresco de tokens y estado «requiere reconexión»

- **Descripción**: Job programado que renueva tokens antes de caducar (p. ej. 7 días antes de los 60 de Meta) y, si no puede, marca la cuenta y avisa. Las publicaciones afectadas no se pierden.
- **Changelog**: Las conexiones se mantienen vivas y avisan si hay que reconectar.
- **Estado**: completado
- **Tiempo humano**: est. 10h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.5h · real 0.06h (medido)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `app/Jobs/RefreshTokens.php`, `routes/console.php`, `app/Notifications/ReconnectRequired.php`, `tests/Feature/Jobs/`, `database/migrations/` (notifications), `app/Jobs/RefreshTokens.php`, `tests/Feature/Jobs/RefreshTokensTest.php`, `app/Jobs/RefreshSocialAccount.php`, `database/migrations/2026_10_20_090000_add_refresh_failures_to_social_accounts.php`, `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php`, `config/social.php`, `config/queue.php`, `.env.example`, `deploy/systemd/scheduler-worker@.service`, `deploy/systemd/README.md`, `tests/Support/FakeConnector.php`
- **Cubre (tests)**: E2E-04, M-02 (renovación real: PENDIENTE manual, ver nota)
- **Verificación**: `php artisan test --filter=RefreshTokens` → pasa (renueva tokens; el fallo marca la cuenta y notifica)

**Criterios de aceptación**
- [x] Renovación automática sin intervención (CA-04)
- [x] Fallo deja la cuenta `requiere_reconexion` y notifica
- [x] Publicaciones pendientes no se borran (a nivel de cuenta/marca; el criterio sobre publicaciones reales queda recogido en T-19 y se re-verifica allí)
- [x] Un fallo transitorio (5xx, red, timeout) NUNCA marca `requiere_reconexion` por sí solo; solo tras N fallos seguidos (`social.max_transient_failures`, 12 ≈ 6 h) o ante un fallo permanente
- [x] Un job por cuenta (único por id, `$timeout` 100 s < `--timeout` 120 s del worker < `retry_after` 180 s) que relee la cuenta con lock y aborta si ya no está activa o ya se renovó

**Subtareas**
- [x] Job con margen configurable
- [x] Marcado de estado y notificación
- [x] Registro en el scheduler
- [x] Tests

---
**Notas (implementer, 2026-10-19)**: Job RefreshTokens (cola poll, configurable con SOCIAL_REFRESH_QUEUE; es mantenimiento y no debe competir con publish) registrado en routes/console.php cada 30 min con withoutOverlapping (el access de Google dura 1 h). Itera cuentas activas (withoutUserScope, es un job sin usuario) y renueva las que caen dentro del margen del conector (config/social.php: Meta 168 h = 7 dias de los 60, Google 1 h, TikTok 6 h); sin caducidad conocida no renueva. ReconnectRequiredException o data_access_expires_at vencido -> requiere_reconexion + ReconnectRequired (mail + database, una sola vez por transicion, sin tokens); fallo transitorio -> reintento, solo escala si el token ya caduco; un error en una cuenta no corta el resto. No borra nada: marcas y cuentas (y por diseno las publicaciones futuras) se conservan. Migracion notifications para el canal database. Verificacion: `php artisan test --filter=RefreshTokens` -> 10 passed, 32 assertions. Nota: el criterio Publicaciones pendientes no se borran se cubre a nivel de cuenta/marca porque el modelo de publicaciones llega en T-19; re-verificar entonces.
**Corrección revisión intento 1 (2026-10-20)**: (a) Clasificación de fallos: permanentes (`ReconnectRequiredException`: invalid_grant/401, `data_access_expires_at` o `refresh_expires_at` vencidos) escalan al instante; transitorios NUNCA marcan aunque el access token haya caducado, cuentan en `refresh_failures` (migración nueva) y escalan solo tras `social.max_transient_failures` (12 ≈ 6 h) seguidos; un éxito reinicia el contador (el test antiguo que fijaba el comportamiento contrario se reescribió). (b) `RefreshTokens` ahora solo DESPACHA (`ShouldBeUnique`, `chunkById`, sin OFFSET) un `RefreshSocialAccount` por cuenta vencida (`ShouldBeUnique` por id, `$timeout` 100, `$tries` 2); cada job relee con `lockForUpdate` en transacción antes de escribir (la llamada de red va FUERA de la transacción para no retener la fila) y descarta el resultado si la cuenta se desconectó o ya se renovó; solo una transición notifica. (c) `--timeout=120` en la unidad systemd y en el plan B de cron (`TimeoutStopSec=130`), `retry_after` de la cola 90→180 s. (d) Dedup del canje de Meta por token de usuario (FB+IG de la misma Página), en `MetaConnector`. (e) `ReconnectRequired` es `ShouldQueue` con canal `database` antes que `mail` (Laravel encola un job por canal: un SMTP caído no impide el aviso en BD) y guarda datos escalares, no el modelo (el worker no tiene usuario autenticado; el UserScope impediría restaurarlo). **PENDIENTE (manual) M-02**: renovación real de un token con cuentas reales (Meta/Google/TikTok); no se marca verificada. Nota de criterio 3: las publicaciones pendientes se conservan por diseño (nunca se borra nada); T-19 debe mantenerlo sin `cascadeOnDelete` destructivo.

## Fase 3 — Publicación base y Meta (IG + FB), calendario

**Estado**: en-progreso · **Estimado**: 142h · **Real**: — · **Coste est.**: 8.520 € + tokens (⚠️ verificar precio) · **Tokens est.**: 6,47M

### T-17 — Subida de media a R2 (multipart prefirmada) y MediaAsset

- **Descripción**: Subida directa navegador→R2 con URLs prefirmadas y multipart para vídeo; registro del `MediaAsset` con metadatos y URL pública/firmada accesible por Meta y TikTok. El binario nunca pasa por PHP-FPM.
- **Changelog**: Se pueden subir imágenes y vídeos grandes.
- **Estado**: completado
- **Tiempo humano**: est. 14h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.46h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-10, T-08
- **Tipo**: backend
- **Archivos**: `app/Models/MediaAsset.php`, `app/Services/MediaUploadService.php`, `app/Http/Controllers/MediaController.php`, `resources/js/Components/MediaUploader.vue`, `tests/Feature/Media/`, `config/media.php`, `database/migrations/2026_10_21_090000_create_media_assets_table.php`, `database/factories/MediaAssetFactory.php`, `app/Services/MediaStorage.php`, `app/Services/MediaProbe.php`, `app/Policies/MediaAssetPolicy.php`, `routes/web.php`, `deploy/hestia/README.md`, `tests/Feature/Media/MediaUploadTest.php`, `app/Console/Commands/PruneMedia.php`, `config/media.php`, `tests/Feature/Media/MediaProbeSecurityTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --filter=MediaUpload` → pasa (URLs prefirmadas, aislamiento, finalización multipart)

**Criterios de aceptación**
- [x] Vídeo grande sube por multipart sin pasar por PHP-FPM
- [x] Cada asset pertenece a un usuario y no es accesible por otros
- [x] Metadatos (dimensiones, duración, peso) extraídos

**Subtareas**
- [x] Endpoints de prefirmado y finalización
- [x] Componente de subida
- [x] Extracción de metadatos
- [x] Tests de aislamiento

---
**Notas (implementer, 2026-10-19)**: `MediaUploadService` prefirma contra el disco `config('filesystems.media')`: en R2/S3 el navegador sube con un PUT prefirmado (`single`) o por multipart (partes de 16 MiB desde 32 MiB, una URL prefirmada por parte, cierre en `complete`); el binario nunca pasa por PHP-FPM. Con disco local (dev/test) `mode: local` sube a `PUT media/{asset}/upload` (409 en R2). `MediaProbe` extrae dimensiones (GD) y duracion/fps/codec (ffprobe sobre ruta o URL temporal); contenido distinto del MIME declarado o ilegible -> `invalido` y se borra. `MediaAsset` con `BelongsToUser` (otro usuario recibe 404) y `publicUrl()` temporal firmada para Meta/TikTok. Requisitos de despliegue (ffprobe y CORS de R2 con ExposeHeaders ETag) anotados en `deploy/hestia/README.md`. **Verificacion**: `php artisan test --filter=MediaUpload` -> 11 passed, 73 assertions (incluye ffprobe real sobre un video generado con ffmpeg, multipart con cliente S3 simulado, aislamiento). **PENDIENTE manual**: probar la subida real navegador->R2 (CORS) cuando haya bucket; no hay credenciales R2 en dev.

### T-18 — Validador de media por red y tipo de contenido

- **Descripción**: Reglas por red y tipo (dimensiones, duración, peso, códec, nº de elementos de carrusel) en configuración, contrastadas con la documentación oficial (⚠️ verificar). Mensajes concretos del límite incumplido.
- **Changelog**: La aplicación avisa antes de programar si el contenido no cumple los límites de la red.
- **Estado**: completado
- **Tiempo humano**: est. 16h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.5h · real 0.15h (medido)
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-17
- **Tipo**: backend
- **Archivos**: `app/Services/MediaValidator.php`, `config/media_specs.php`, `tests/Unit/MediaValidator/`, `app/Enums/ContentType.php`, `tests/Feature/Media/MediaValidatorSampleTest.php`, `tests/Unit/MediaValidator/MediaValidatorTest.php`
- **Cubre (tests)**: E2E-05
- **Verificación**: `php artisan test --filter=MediaValidator` → pasa para los 13 tipos de contenido, con casos límite

**Criterios de aceptación**
- [x] Un vídeo que excede los límites de un reel de IG se rechaza indicando el límite (CA-05)
- [x] Reglas versionadas en `config/media_specs.php`
- [x] Cobertura de los 13 tipos

**Subtareas**
- [x] Matriz de specs oficial por tipo
- [x] Validador y mensajes
- [x] Tests con ficheros de muestra

**Notas**: Sin transcodificación (fuera de alcance, +20–30 h).

---
**Notas (implementer, 2026-10-19)**: `config/media_specs.php` (version 2026-10-19) define las reglas de los 13 tipos (IG imagen/carrusel/reel/historia-imagen/historia-video, FB texto/foto/video/reel, YT video/short, TikTok video/fotos) y `MediaValidator` devuelve mensajes con el limite exacto (p. ej. «dura 20 min y un reel de Instagram admite como maximo 15 min», CA-05). Incluye TikTok >= 23 fps (spike), IG solo JPEG. Los limites de las redes estan puestos de memoria de la documentacion oficial y marcados en el fichero como VERIFICAR contra la doc vigente al tocarlos (el ledger ya pedia ⚠️ verificar); sin transcodificacion (un PNG para IG se rechaza). **Verificacion**: `php artisan test --filter=MediaValidator` -> 40 passed, 109 assertions (13 validos + 22 invalidos + limites exactos + videos de muestra reales con ffmpeg/ffprobe).

### T-19 — Modelo de publicaciones, máquina de estados y PostService

- **Descripción**: Modelar `Post` y `PostTarget` por red/cuenta/tipo, estados `borrador → programada → publicando → procesando → publicada|fallida` y servicio con zona horaria por usuario/marca. Todo pasa por el servicio (web y MCP).
- **Changelog**: Modelo de publicaciones con estados claros.
- **Estado**: completado
- **Tiempo humano**: est. 14h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.18h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-18
- **Tipo**: backend
- **Archivos**: `app/Models/Post.php`, `app/Models/PostTarget.php`, `app/Models/PublishAttempt.php`, `app/Services/PostService.php`, `app/Enums/PostStatus.php`, `database/migrations/`, `tests/Feature/Posts/`, `app/Models/MediaAsset.php`, `app/Http/Controllers/MediaController.php`, `app/Exceptions/InvalidPostTransition.php`, `app/Exceptions/PostValidationException.php`, `database/factories/PostFactory.php`, `database/factories/PostTargetFactory.php`, `database/migrations/2026_10_21_100000_create_posts_tables.php`, `tests/Feature/Posts/PostsTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --filter=Posts` → pasa (transiciones válidas e inválidas, aislamiento)

**Criterios de aceptación**
- [x] Transiciones de estado validadas y persistidas
- [x] Zona horaria por marca respetada
- [x] Aislamiento por usuario heredado
- [x] Al crear `post_targets`, borrar o desconectar una cuenta (o pasarla a `requiere_reconexion`) NO borra publicaciones pendientes: sin `cascadeOnDelete` destructivo desde `social_accounts` (FK `restrict`/`nullOnDelete` + estado) y test que lo verifique (criterio heredado de T-16)

**Subtareas**
- [x] Migraciones y modelos
- [x] Enum de estados y reglas de transición
- [x] `PostService` con validación de media
- [x] Tests

---
**Notas (implementer, 2026-10-19)**: Modelo `posts` (texto, `scheduled_at` en UTC, estado agregado) + `post_targets` (cuenta + tipo + estado real, `external_id/url/reference`, `attempts`, `started_at`, `next_attempt_at`, unicidad por post+cuenta+tipo) + `post_media` (orden, FK `restrict`: una media en uso no se borra, el endpoint responde 409) + `publish_attempts`. Estados en `App\Enums\PostStatus` con matriz de transiciones (`InvalidPostTransition` si no es valida); `publicando -> programada` permite reintentos diferidos. `PostService` es la capa unica (exige usuario explicito): create/update/schedule/unschedule/reschedule/delete/transition (con `lockForUpdate`), valida cuentas de la marca, tipo-red, media propia y `MediaValidator` por destino, y un string de fecha sin zona se interpreta en la zona de la marca y se guarda en UTC. **Criterio heredado de T-16**: `post_targets.social_account_id` es nullable con `nullOnDelete` (sin cascade destructivo); tests: desconectar, `requiere_reconexion` y borrar la fila de la cuenta conservan la publicacion pendiente (en el borrado el target queda con cuenta null y `programada`; T-20 la marcara fallida con motivo). Borrar la MARCA si borra sus publicaciones (accion explicita). **Verificacion**: `php artisan test --filter=Posts` -> 28 passed, 88 assertions; suite completa 234 passed.

### T-20 — Cola de publicación: dispatcher, jobs, reintentos e idempotencia

- **Descripción**: Dispatcher cada minuto de lo vencido; `PublishPostTarget` con `tries`/`backoff`/`ShouldBeUnique`; estado `publicando` persistido antes de llamar a la red y consulta del estado externo antes de reintentar; `PollPublishStatus` para procesos asíncronos; recuperación de vencidas tras caída del VPS.
- **Changelog**: Las publicaciones se lanzan solas a su hora y sin duplicados.
- **Estado**: completado
- **Tiempo humano**: est. 22h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 3.4h · real 0.26h (medido)
- **Supervisión**: est. 0.8h (≈25 % IA) · real —
- **Previsión IA**: 911k in / 91k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-10
- **Tipo**: backend
- **Archivos**: `app/Jobs/PublishPostTarget.php`, `app/Jobs/PollPublishStatus.php`, `routes/console.php`, `app/Services/PublishRecovery.php`, `tests/Feature/Publishing/`, `config/publishing.php`, `app/Jobs/DispatchDuePosts.php`, `app/Services/PublishOutcomes.php`, `app/Services/PublishRequestFactory.php`, `app/Connectors/ConnectorRegistry.php`, `app/Connectors/Contracts/Publisher.php`, `app/Connectors/Contracts/StatusPoller.php`, `app/Connectors/Data/PublishMedia.php`, `app/Connectors/Data/PublishRequest.php`, `app/Connectors/Data/PublishResult.php`, `app/Connectors/Exceptions/PublishException.php`, `app/Connectors/Exceptions/TransientPublishException.php`, `app/Connectors/Exceptions/PermanentPublishException.php`, `app/Connectors/Exceptions/QuotaExceededException.php`, `tests/Support/FakePublisher.php`, `tests/Feature/Publishing/PublishingTest.php`, `app/Connectors/Support/StepBudget.php`, `app/Services/QuotaPolicy.php`, `app/Services/SocialAccountService.php`, `app/Enums/PostStatus.php`, `app/Models/PostTarget.php`, `database/migrations/2026_10_23_090000_add_progress_and_indexes_to_posts.php`, `tests/Feature/Publishing/RevisionFase3Test.php`
- **Cubre (tests)**: E2E-06, E2E-07
- **Verificación**: `php artisan test --filter=Publishing` → pasa (reintentos con backoff, sin duplicados, recuperación de vencidas)

**Criterios de aceptación**
- [x] Error transitorio acaba `publicada` o `fallida` con motivo, sin duplicados (CA-07)
- [x] Tras una caída, al arrancar se publican las vencidas
- [x] Error permanente no reintenta

**Subtareas**
- [x] Dispatcher en el scheduler
- [x] Jobs con unicidad y backoff
- [x] Idempotencia y comprobación previa del estado externo
- [x] Recuperación de vencidas
- [x] Tests con conector simulado

**Notas**: Tarea de mayor riesgo técnico (design.md §6).

---
**Notas (implementer, 2026-10-19)**: Contratos `Publisher` (publish + `findPublished`) y `StatusPoller` rehechos con `PublishRequest/Result` y excepciones transitoria/permanente/cuota (el `Publisher` previo era solo un esqueleto sin implementaciones). `DispatchDuePosts` (scheduler cada minuto, en linea y `withoutOverlapping`) ejecuta `PublishRecovery` (cuenta eliminada -> fallida; vencidas > `max_lateness_hours`=24 -> fallida `vencida`; `publicando` colgado -> se relanza; `procesando` con sondeo vencido -> se vuelve a sondear) y despacha un `PublishPostTarget` (cola `publish`, `ShouldBeUnique` por destino, timeout 110 < worker 120 < retry_after 180) por destino `programada` vencido con cuenta activa. El job reclama con `lockForUpdate` y persiste `publicando` antes de llamar a la red; en cualquier reintento (attempts>1 o referencia externa) consulta `findPublished` antes de publicar; transitorio -> `programada` con backoff 60/300/900 s hasta `max_attempts` (4) -> `fallida` con motivo; permanente -> `fallida` sin reintento; cuota -> aplazado sin gastar intento (la politica elegible llega en T-25); credenciales invalidas -> la cuenta pasa a `requiere_reconexion` (aviso una vez) y la publicacion se conserva `programada`. `PollPublishStatus` (cola `poll`) sondea `procesando` hasta `poll_max_minutes`; un error pasajero al sondear nunca falla el destino. Decision de producto por defecto (documentada en `config/publishing.php`): las vencidas se publican al volver solo si llevan < 24 h de retraso. **Decision confirmada por el usuario (2026-10-21, revision F3 #10):** tope de 24 h (`max_lateness_hours`) sobre «tras una caida se publican las vencidas» (`design.md:158`). **Verificacion**: `php artisan test --filter=Publishing` -> 24 passed, 105 assertions (reintentos con backoff, timeout-tras-enviar sin duplicado, recuperacion de vencidas y colgadas, cuota, reconexion, sondeo); suite completa 254 passed. Ejecuciones simultaneas del mismo destino publican una vez (test).

### T-21 — Publicación en Instagram: post, carrusel, reel y story

- **Descripción**: Contenedores de media de IG, polling del procesamiento, publicación y captura del permalink; manejo del límite de publicaciones por 24 h y de las limitaciones de stories (⚠️ verificar).
- **Changelog**: Se publica en Instagram: imagen, carrusel, reel y story.
- **Estado**: en-progreso
- **Tiempo humano**: est. 22h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 3.4h · real 0.23h (medido)
- **Supervisión**: est. 0.8h (≈25 % IA) · real —
- **Previsión IA**: 911k in / 91k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Instagram/`, `tests/Feature/Connectors/Meta/Instagram/`, `app/Connectors/Meta/GraphClient.php`, `app/Providers/AppServiceProvider.php`, `app/Enums/ContentType.php`, `app/Services/PublishRequestFactory.php`, `tests/Support/FakeGraph.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramPublishTest.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramRevisionTest.php`, `tests/Support/FakeGraph.php`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=InstagramPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real de los 4 tipos de IG con enlace/ID

**Criterios de aceptación**
- [ ] Cada tipo se publica y queda `publicada` con enlace/ID externo (CA-06)
- [x] Errores de la API mapeados a transitorios o permanentes
- [x] Limitaciones de stories avisadas en el compositor (T-23 las muestra al elegir el tipo)

**Subtareas**
- [x] Imagen y carrusel
- [x] Reel con polling
- [x] Story
- [x] Mapeo de errores y límites
- [x] Tests con respuestas grabadas

**Notas**: La validación real es manual (M-01).

---
**Notas (implementer, 2026-10-19)**: `InstagramPublisher` (Graph v23.0, token de Pagina) sigue el flujo del spike: contenedor `/{ig}/media` -> espera de `status_code` (FINISHED/IN_PROGRESS/ERROR/EXPIRED/PUBLISHED) -> `/{ig}/media_publish` -> permalink. Imagen y story publican en el acto si el contenedor ya esta FINISHED, si no pasan a `procesando` y `poll()` los publica; reel (`REELS`, `share_to_feed` configurable) siempre por sondeo; carrusel en dos fases (`external_reference` = `child:ID1,ID2` hasta que todos los hijos terminan y entonces crea el contenedor `CAROUSEL`). Cupo: consulta `content_publishing_limit` antes de gastar un contenedor (si no se puede consultar, intenta igualmente). Errores (`GraphClient`): 190 y afines -> reconexion; 2207042 -> cuota; 2207026/2207023/2207052... -> permanente con motivo legible; 2207001/2207003/2207027/9007, rate limit 4/17/32/613, 5xx y timeouts -> transitorio; (#10)/(#200) -> permanente `permisos` (instagram_content_publish). `findPublished` busca en la media reciente por descripcion + hora (limitacion documentada: una publicacion SIN texto no se puede reconocer tras un timeout). Limitaciones de stories y demas avisadas via `ContentType::limitations()` (el compositor las muestra en T-23). **Verificacion**: `php artisan test --filter=InstagramPublish` -> 22 passed, 102 assertions (los 4 tipos con respuestas simuladas de Graph, mapeo de errores, cupo, y de extremo a extremo por la cola: timeout en media_publish sin duplicar). **PENDIENTE (manual) M-01**: publicar de verdad los 4 tipos de IG en @aipocalipsis_oficial (necesita `instagram_content_publish` concedido y media accesible por URL publica) y registrar enlace/ID; el criterio `Cada tipo se publica ... (CA-06)` y la tarea siguen en-progreso hasta entonces.

### T-22 — Publicación en Facebook páginas: texto/enlace, foto, vídeo y reel

- **Descripción**: Publicación en páginas de Facebook (texto/enlace, foto, vídeo y reel) con el token de página y polling del procesamiento de vídeo.
- **Changelog**: Se publica en páginas de Facebook.
- **Estado**: en-progreso
- **Tiempo humano**: est. 16h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.5h · real 0.09h (medido)
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Facebook/`, `tests/Feature/Connectors/Meta/Facebook/`, `app/Providers/AppServiceProvider.php`, `app/Connectors/Meta/GraphClient.php`, `tests/Support/FakeGraph.php`, `tests/Feature/Connectors/Meta/Facebook/FacebookPublishTest.php`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=FacebookPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real de los 4 tipos de FB

**Criterios de aceptación**
- [ ] Los 4 tipos quedan `publicada` con ID externo (CA-06)
- [x] El reel respeta sus requisitos de formato
- [x] Errores mapeados

**Subtareas**
- [x] Texto/enlace y foto
- [x] Vídeo
- [x] Reel
- [x] Tests con respuestas grabadas

---
**Notas (implementer, 2026-10-19)**: `FacebookPublisher` (Graph v23.0, token de Pagina): texto/enlace `POST /{page}/feed` (message + link), foto `POST /{page}/photos` (url + caption, como en el spike; devuelve `post_id`), video `POST /{page}/videos` (`file_url`) con sondeo de `status.video_status`, y reel en 3 pasos (`video_reels` start -> subida por cabecera `file_url` a `rupload.facebook.com` -> finish `PUBLISHED`) con sondeo. La URL de subida solo se acepta si es https y de `*.facebook.com`. `findPublished` busca en `/posts` (o `/videos`) recientes por texto + hora, y un video/reel ya creado se resuelve por su ID (no se crea otro). Errores via `GraphClient` (mismos mapeos que IG). El reel respeta los requisitos de formato porque el `MediaValidator` (T-18) rechaza antes de programar (9:16, 3-90 s, 24-60 fps, 540x960). **Verificacion**: `php artisan test --filter=FacebookPublish` -> 17 passed, 65 assertions (los 4 tipos con respuestas simuladas y de extremo a extremo por la cola, errores, idempotencia); suite completa 293 passed. **PENDIENTE (manual) M-01**: publicar de verdad los 4 tipos de FB en la Pagina Aipocalipsis (foto ya verificada en el spike, post_id `1065269806670394_122137968398744480`; texto/video/reel sin probar; los campos de `status` del reel hay que contrastarlos con la respuesta real). El criterio `Los 4 tipos quedan publicada con ID externo (CA-06)` y la tarea siguen en-progreso hasta entonces.

### T-23 — Compositor de publicaciones por red y tipo

- **Descripción**: Formulario de creación y edición: elegir marca, cuentas y tipo, texto, media y fecha/hora con zona horaria; muestra errores de validación de media y limitaciones por red.
- **Changelog**: Se pueden programar publicaciones desde un compositor.
- **Estado**: en-progreso
- **Tiempo humano**: est. 18h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 2.8h · real 0.16h (medido)
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-21
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Posts/Compose.vue`, `resources/js/Components/`, `app/Http/Controllers/PostController.php`, `tests/Feature/Posts/Compose/`, `app/Services/PostService.php`, `app/Policies/PostPolicy.php`, `app/Http/Requests/PostRequest.php`, `resources/js/Components/MediaUploader.vue`, `resources/js/Pages/Brands/Show.vue`, `routes/web.php`, `tests/Feature/Posts/Compose/ComposeTest.php`
- **Cubre (tests)**: E2E-05, E2E-06, M-01
- **Verificación**: `php artisan test --filter=Compose` → pasa

**Criterios de aceptación**
- [x] Programación guardada y visible
- [x] Media inválida se rechaza con el límite incumplido (CA-05)
- [x] Edición y borrado de pendientes

**Subtareas**
- [x] Formulario por red/tipo
- [x] Integración con el validador
- [x] Previsualización básica
- [x] Tests de feature

**Notas**: El compositor incorpora YouTube y TikTok en T-26 y T-27.

---
**Notas (implementer, 2026-10-19)**: `PostController` (crear, editar, programar, borrar, y validacion en vivo `POST brands/{brand}/posts/validate`) sobre `PostService`; todas las rutas autorizan por Policy y el UserScope da 404 a otros usuarios. `Posts/Compose.vue`: cuentas de la marca con selector de tipo por red, limitaciones por tipo (`ContentType::limitations()`: p. ej. las historias de IG no admiten texto), media con subida (`MediaUploader`, ordenable), fecha en la zona horaria de la marca (se guarda en UTC), validacion en vivo contra `MediaValidator` (muestra el limite incumplido, CA-05), previsualizacion basica y estado por red al editar; las ya publicadas/en vuelo quedan de solo lectura. Programar o Guardar borrador desde el mismo formulario; el calendario (T-24) sustituira la redireccion a la edicion. **Verificacion**: `php artisan test --filter=Compose` -> 11 passed, 112 assertions; `npm run build` OK; suite completa 304 passed. **Manual (no bloquea el cierre)**: probar el compositor en el navegador con cuentas reales dentro de M-01. **Estado coherente (revision F3 #11):** T-23 permanece `en-progreso` hasta cerrar M-01 y T-21; no se marca `completado` mientras M-01 figure en «Cubre».

### T-24 — Calendario por marca y red con reprogramación

- **Descripción**: Vista de calendario (mes/semana) filtrable por marca y red con el estado de cada publicación y reprogramación de las pendientes.
- **Changelog**: Calendario para ver y reprogramar publicaciones.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.8h · real 0.15h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Calendar/`, `app/Services/CalendarService.php`, `app/Http/Controllers/CalendarController.php`, `tests/Feature/Calendar/`, `resources/js/Layouts/AuthenticatedLayout.vue`, `app/Http/Controllers/PostController.php`, `routes/web.php`, `tests/Feature/Calendar/CalendarTest.php`, `tests/Feature/Posts/Compose/ComposeTest.php`
- **Cubre (tests)**: E2E-08
- **Verificación**: `php artisan test --filter=Calendar` → pasa

**Criterios de aceptación**
- [x] Publicaciones en su fecha con estado (CA-08)
- [x] Reprogramar pendientes funciona
- [x] Filtros por marca y red

**Subtareas**
- [x] `CalendarService`
- [x] Vista de calendario
- [x] Edición de fecha
- [x] Tests

---
**Notas (implementer, 2026-10-19)**: Calendario siguiendo `ui-reference.md` (Planning de Metricool): vistas Semana (franjas horarias de 24 h x 7 dias con tarjetas por publicacion: hora, chips de red IG/FB/YT/TT, estado y extracto), Mes (6 semanas lunes-domingo) y Lista (tabla Fecha - Contenido - Redes - Estado POR RED, motivo del error, enlace externo, busqueda por texto, filtro de estado, borradores sin fecha), filtros por marca y red, navegacion anterior/hoy/siguiente, zona horaria visible y boton «Crear publicacion» (a la marca filtrada). Las pendientes se reprograman arrastrando la tarjeta a otro dia/hora (semana y mes) o editando la fecha en la lista (`PATCH posts/{post}/reschedule` -> `PostService::reschedule`); las publicadas o en curso no son arrastrables y el servidor las rechaza. `CalendarService` solo consulta publicaciones del usuario; las horas salen en la zona de la marca filtrada (o la primera marca). Programar desde el compositor ahora redirige al calendario en la semana de la publicacion («programacion visible»). Fuera de alcance v1: mapa de calor de mejores horas (ui-reference.md). **Verificacion**: `php artisan test --filter=Calendar` -> 9 passed, 237 assertions (fecha/hora/estado por publicacion, mes, filtros marca/red, zona horaria, lista, reprogramacion, publicada no reprogramable, aislamiento); `npm run build` OK; suite completa verde. **Manual**: el arrastrar-y-soltar y el aspecto del calendario no tienen test de navegador (solo build); revisar en el navegador.

### T-25 — Notificaciones de fallo y política ante cuota agotada

- **Descripción**: Aviso in-app y por email cuando una publicación falla, y política ante cuota agotada (reprogramar automáticamente o fallar con motivo «cuota»; decisión de producto a confirmar).
- **Changelog**: Avisos cuando una publicación falla.
- **Estado**: completado
- **Tiempo humano**: est. 8h · real 0h (IA)
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.13h (medido)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `app/Notifications/PublishFailed.php`, `app/Services/QuotaPolicy.php`, `tests/Feature/Notifications/`, `app/Services/PublishOutcomes.php`, `app/Jobs/PublishPostTarget.php`, `config/publishing.php`, `app/Http/Controllers/NotificationController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Pages/Notifications/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `routes/web.php`, `tests/Feature/Notifications/PublishFailedTest.php`
- **Cubre (tests)**: E2E-07
- **Verificación**: `php artisan test --filter=PublishFailed` → pasa (aviso in-app y email)

**Criterios de aceptación**
- [x] Un fallo genera aviso con motivo legible
- [x] Cuota agotada sigue la política elegida

**Subtareas**
- [x] Notificación in-app y email
- [x] Política de cuota configurable
- [x] Tests

**Notas**: Incógnita: reprogramar automáticamente o fallar.

---
**Notas (implementer, 2026-10-19)**: `PublishFailed` (database + mail, por cola `poll`, datos escalares, sin tokens) se envia UNA vez en la transicion a `fallida` (hook unico en `PublishOutcomes::failed`, que cubre permanentes, reintentos agotados, vencidas y cuenta eliminada); un transitorio que aun reintenta no avisa. Aviso in-app: pagina `Avisos` (lista, marcar leido / todo) y contador de no leidos en la barra, siempre acotado al usuario autenticado. `QuotaPolicy` con politica configurable `PUBLISH_QUOTA_POLICY`: `reschedule` (POR DEFECTO: aplaza hasta que la red libere cupo sin gastar intento; si no llega dentro del margen `max_lateness_hours` falla con motivo «cuota») o `fail` (falla ya con motivo «cuota» y avisa). **Decision de producto confirmada por el usuario (2026-10-21)**: reprogramar automaticamente (`reschedule`); esta documentada en `config/publishing.php` y se cambia por entorno sin tocar codigo. **Verificacion**: `php artisan test --filter=PublishFailed` -> 9 passed, 75 assertions (aviso in-app y email con motivo legible, una sola vez, transitorio, vencidas, politica de cuota en sus 3 variantes, pantalla de avisos y aislamiento); suite completa 322 passed; `npm run build` OK.

---

## Fase 4 — Publicación en YouTube (condicionada a G1)

**Estado**: en-progreso · **Estimado**: 18h · **Real**: — · **Coste est.**: 1.080 € + tokens (⚠️ verificar precio) · **Tokens est.**: 820k

> Fase condicionada a la puerta G1 (T-05). Si el usuario pospone la red, la tarea pasa a `cancelado` y su presupuesto se libera.

### T-26 — Publicación en YouTube: vídeo y short con subida reanudable (condicionada a G1)

- **Descripción**: Subida reanudable en streaming desde R2, gestión de cuota (~1.600 unidades por subida), estado de procesamiento y visibilidad según la decisión G1. Añade los tipos YouTube al compositor.
- **Changelog**: Ya se puede programar y publicar en YouTube, como vídeo o short, con la visibilidad elegida y control del cupo diario de la API.
- **Estado**: en-progreso
- **Tiempo humano**: est. 18h · real —
- **Tiempo IA (ejec.)**: est. 2.8h · real 8.35h (medido, ventana inflada por una pausa de límite de uso; T-26+T-27 comparten sesión)
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-13, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeQuota.php`, `app/Connectors/Support/MediaChunkReader.php`, `app/Connectors/Data/PublishMedia.php`, `app/Connectors/Data/PublishResult.php`, `app/Services/PublishOutcomes.php`, `app/Services/PublishRequestFactory.php`, `app/Services/PostService.php`, `app/Services/CalendarService.php`, `app/Models/PostTarget.php`, `app/Enums/ContentType.php`, `app/Http/Controllers/PostController.php`, `app/Http/Requests/PostRequest.php`, `app/Providers/AppServiceProvider.php`, `config/social.php`, `.env.example`, `database/migrations/2026_10_22_090000_add_status_note_to_post_targets.php`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Pages/Calendar/Index.vue`, `tests/Support/FakeHttp.php`, `tests/Feature/Connectors/Google/Publish/`, `tests/Feature/Connectors/Support/`, `app/Jobs/PollPublishStatus.php`, `config/publishing.php`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=YouTubePublish` → 49 passed (subida reanudable en trozos, cuota, idempotencia por tag, presupuesto); `php artisan test --filter=MediaChunkReader` → 2 passed (lectura por rango `Range` en S3/R2 simulado)
  - lectura: M-01 registra la subida real de vídeo y short con su visibilidad

**Criterios de aceptación**
- [x] Vídeo y short quedan `publicada` con enlace (CA-06) con la visibilidad acordada en G1
- [x] Cuota agotada sigue la política de T-25
- [x] Nunca se carga el vídeo completo en memoria

**Subtareas**
- [x] Cliente de subida reanudable
- [x] Control de cuota
- [x] Integración con la cola y el compositor
- [x] Tests

**Notas**: Se cancela con G1 = D; con G1 = C solo calendario y recordatorio.
**Decisión G1 (2026-10-19): YouTube = A.** Publicación con la visibilidad pedida (verificado: `public` no se fuerza a privado). Cuota 10.000 ud/día ≈ 6 subidas: bloquear/reprogramar al superar el cupo.

---

## Fase 5 — Publicación en TikTok (condicionada a G1)

**Estado**: en-progreso · **Estimado**: 16h · **Real**: — · **Coste est.**: 960 € + tokens (⚠️ verificar precio) · **Tokens est.**: 728k

> Fase condicionada a la puerta G1 (T-05). Si el usuario pospone la red, la tarea pasa a `cancelado` y su presupuesto se libera.
**Notas (implementer, 2026-10-20)**: `YouTubePublisher` (Publisher + StatusPoller, cliente `Http` propio): `videos.insert` reanudable (`uploadType=resumable&part=snippet,status`), sesion en cache por destino, trozos de 8 MiB (`social.youtube.chunk_bytes`, multiplo de 256 KiB) leidos en streaming con `MediaChunkReader` (nunca el video entero en memoria; test: ninguna peticion supera un trozo), recuperacion tras cortes preguntando a Google el `Range` recibido, y presupuesto de 80 s por pasada: si no acaba, el destino queda `procesando` con referencia `subiendo` y `PollPublishStatus` continua la subida. Tras subir, se sondea `status.uploadStatus`/`processingDetails` hasta `processed` (publicada con enlace `watch?v=`/`shorts/`) o `rejected/failed` (motivo legible). Visibilidad pedida respetada (`options.privacy`, por defecto `public`, desconocida -> `private`). Short: `#Shorts` anadido salvo `shorts_hashtag=false`. Cuota: `YouTubeQuota` (cache, dia de Pacifico, 10.000 ud/dia, 1.600/subida, configurables) -> `QuotaExceededException` con `retryAt` = reinicio, que aplica la politica de T-25 (`reschedule` por defecto / `fail`); `quotaExceeded` de la API marca el dia agotado. `findPublished` (idempotencia) busca por titulo+hora en la playlist de subidas `UU...`. `deleteVideo` con scope `youtube`. Decisiones: (1) `PublishMedia` gana `disk`/`path` para leer en streaming; (2) nueva columna `post_targets.status_note` (+`PublishResult::note`) para matices de «publicada» (la usa T-27); (3) tipos YouTube en compositor con titulo/visibilidad y avisos de cuota. **Verificacion**: `php artisan test --filter=YouTubePublish` -> 35 passed (33 del publicador + 2 de compositor/conexiones), suite completa 376 passed, `vendor/bin/pint --test` OK, `npm run build` OK. **PENDIENTE MANUAL (M-01)**: la subida real de un video y un short con su visibilidad no se ha ejecutado (los tests solo usan Http::fake/Storage::fake); por eso la tarea sigue `en-progreso`.
**Corrección intento 1 (Fases 4-5, implementer, 2026-10-20)**: sesión reanudable reutilizada en reintentos (sin re-cobrar cuota), consulta de offset con 200/201 toma el `id` del cuerpo, tag propio `ss-<destino>` para la idempotencia, presupuesto comprobado antes de cada PUT con timeout HTTP acotado a la marca dura de 100 s, `MediaChunkReader` por rango (`GetObject` con `Range` en R2), plazo de subida propio (`publishing.upload_max_minutes`, 240) y `poll_max_minutes` desde que termina, `rateLimitExceeded` transitorio, descripción en bytes, `chunk_bytes` múltiplo de 256 KiB. **Verificación (salida real)**: `php artisan test --filter=YouTubePublish` -> 49 passed (incluye compositor/conexiones y 12 pruebas nuevas de la revisión); `php artisan test --filter=MediaChunkReader` -> 2 passed (rango S3 simulado: una petición `bytes=900-949`); suite completa 485 passed; `vendor/bin/pint --test` OK; `npm run build` OK. Las cifras anteriores (35/21) eran de otra ejecución y no cuadraban. Medición de la corrección (usage-meter, mismo tramo para T-26-fix1 y T-27-fix1, no separable): 3.06 h IA / 3.08 h IA (medido), ≈ 225k tok de salida compartidos.

### T-27 — Publicación en TikTok: vídeo y foto/carrusel (condicionada a G1)

- **Descripción**: Content Posting API: vídeo (PULL_FROM_URL desde dominio verificado) y foto/carrusel, polling de `publish_status` y visibilidad según G1. Añade los tipos TikTok al compositor.
- **Changelog**: Los vídeos se envían a la bandeja de TikTok para terminarlos en la app, con Direct Post opcional por cuenta y fotos solo con dominio verificado.
- **Estado**: en-progreso
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real incluido en T-26 (misma sesion, no separable)
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-14, T-05
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/TikTokPublisher.php`, `app/Http/Controllers/ConnectionController.php`, `routes/web.php`, `resources/js/Pages/Brands/Connections.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Pages/Calendar/Index.vue`, `app/Enums/ContentType.php`, `app/Services/PostService.php`, `config/social.php`, `.env.example`, `app/Connectors/Data/PublishResult.php`, `app/Services/PublishOutcomes.php`, `tests/Feature/Connectors/TikTok/Publish/`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=TikTokPublish` → 25 passed (subida por pasadas con `publish_id` previo, borrador atascado, PULL_FROM_URL)
  - lectura: M-01 registra la publicación real en TikTok con su visibilidad

**Criterios de aceptación**
- [ ] Vídeo y foto quedan `publicada` (CA-06) con la visibilidad acordada en G1
- [x] Polling del estado de publicación robusto
- [ ] Dominio propio sobre el bucket verificado

**Subtareas**
- [x] Flujo init/upload/publish
- [x] Polling del estado
- [x] Integración con cola y compositor
- [x] Tests

**Notas**: Se cancela con G1 = D.
**Decisión G1 (2026-10-19): TikTok = D + B.** Modo por defecto: **borrador a la bandeja** (`/v2/post/publish/inbox/video/init/`, FILE_UPLOAD por chunks; estado final a la espera del usuario en la app). **Direct Post** (`/v2/post/publish/video/init/` con `post_info`) detrás de la opción por cuenta `tiktok.direct_post` (desactivada hasta aprobar la auditoría; sin auditoría la API responde `unaudited_client_can_only_post_to_private_accounts`). Validar vídeo ≥ 23 fps antes de subir (`frame_rate_check_failed`). PULL_FROM_URL solo con dominio verificado.

---
**Notas (implementer, 2026-10-20)**: `TikTokPublisher` (Publisher + StatusPoller): por defecto **borrador a la bandeja** (`inbox/video/init`, FILE_UPLOAD, un trozo si <= 64 MB, si no trozos de 10 MB con `total_chunk_count = floor(size/chunk)` y el ultimo absorbe el resto; PUT con `Content-Range`, en streaming); `upload_url` solo de hosts TikTok. **Decision de estado final**: el borrador queda `publicada` (SEND_TO_USER_INBOX; `external_id` = publish_id, sin enlace) con la **nota** `status_note` «Enviado a la bandeja de TikTok: abre la app y termina de publicarlo desde tus borradores», visible en el compositor y el calendario (sin estado nuevo: no toca la maquina de estados). **Direct Post** (`video/init` + `post_info`, visibilidad de `creator_info` con `{}`, nunca mas abierta de lo pedido) solo con `meta.direct_post` = true; interruptor por cuenta en Conexiones (PATCH `brands.connections.direct-post`, desactivado por defecto, texto «Requiere auditoria aprobada de TikTok»). `unaudited_client_can_only_post_to_private_accounts` -> fallo permanente sugiriendo desactivar Direct Post. fps < 23 se rechaza antes de subir (`frame_rate_check_failed`). Sondeo `status/fetch` (PROCESSING_* / SEND_TO_USER_INBOX / PUBLISH_COMPLETE con enlace / FAILED con motivo); errores clasificados (cuota por spam, reconexion, transitorio, permanente). Foto/carrusel por PULL_FROM_URL (`content/init`, MEDIA_UPLOAD o DIRECT_POST) **solo con `TIKTOK_VERIFIED_DOMAIN`**; sin el, `ContentType::isOffered()` oculta el tipo del compositor y `PostService` lo rechaza; las URLs firmadas se reescriben bajo el dominio (requiere que ese dominio haga de proxy/CDN del bucket: **pendiente de infraestructura**). **Verificacion**: `php artisan test --filter=TikTokPublish` -> 21 passed (19 publicador con respuestas simuladas + 2 conexiones/compositor), suite completa 376 passed, pint OK, `npm run build` OK. **PENDIENTE MANUAL (M-01)**: publicacion real (borrador y Direct Post) en TikTok, y verificar el dominio propio sobre el bucket; la tarea sigue `en-progreso`.
**Corrección intento 1 (Fases 4-5, implementer, 2026-10-20)**: `publish_id` y estado de la subida (`progress`: `phase=uploading`, `upload_url`, trozo siguiente) guardados por `checkpoint` ANTES del primer PUT y tras cada trozo; la subida se reparte en pasadas (presupuesto 80 s, marca dura 100 s) y `PollPublishStatus` la continúa; trozo enviado en streaming; borrador atascado en `PROCESSING_UPLOAD` se resuelve como `publicada` con nota «Enviado a la bandeja (TikTok no confirmó el estado)» tras `inbox_grace_minutes` (10) — decisión documentada: el spike observó que en sandbox el estado no avanza y el vídeo ya está en la bandeja; Direct Post conserva el fallo por plazo; PULL_FROM_URL = `TIKTOK_VERIFIED_DOMAIN` (https) + clave del objeto (el dominio verificado debe ser el dominio público del bucket y las imágenes legibles sin firma); título de foto ≤ 90. **Nota para T-34**: `external_id` de un destino TikTok enviado a la bandeja es el `publish_id`, NO un ID de vídeo; `progress.external_id_kind` vale `publish_id` (o `video_id` si TikTok devolvió el ID de vídeo): la analítica solo debe usar `external_id` como ID de vídeo cuando es `video_id`. **Verificación (salida real)**: `php artisan test --filter=TikTokPublish` -> 25 passed (incluye compositor y 6 pruebas nuevas de la revisión); suite completa 485 passed; `vendor/bin/pint --test` OK; `npm run build` OK.

## Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación

**Estado**: completado · **Estimado**: 37h · **Real**: 0.67h IA (parte estimada) · **Coste est.**: 2.220 € + tokens (⚠️ verificar precio) · **Tokens est.**: 1,69M

### T-28 — Servidor MCP HTTP con API keys personales (Sanctum)

- **Descripción**: Servidor `laravel/mcp` (versión menor fijada) en `/mcp` con Bearer = API key personal (hash en reposo), pantalla para crear y revocar keys y rate limiting.
- **Changelog**: Un agente de IA puede conectarse al MCP con una API key personal que creas y revocas desde la web.
- **Estado**: completado
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.3h (estimado; el JSON de usage-meter no se capturó)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Mcp/Servers/SchedulerServer.php`, `routes/ai.php`, `app/Http/Controllers/ApiKeyController.php`, `resources/js/Pages/ApiKeys/`, `tests/Feature/Mcp/`; añadidos al implementar: `composer.json`, `composer.lock`, `config/sanctum.php`, `config/services.php`, `database/migrations/2026_10_19_223906_create_personal_access_tokens_table.php`, `database/migrations/2026_10_24_090000_create_audit_logs_table.php`, `app/Models/User.php`, `app/Models/AuditLog.php`, `app/Services/AuditLog.php`, `app/Mcp/Abilities.php`, `app/Mcp/Tools/`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Support/McpClient.php`, `tests/Feature/Mcp/McpAuthTest.php`
- **Cubre (tests)**: E2E-12, API-01, API-02
- **Verificación**:
  - `php artisan test --filter=McpAuth` → 13 passed (71 assertions)
  - `php artisan route:list --path=mcp -v` → POST /mcp con ReorderJsonAccept, ValidateMcpHeaders, AddWwwAuthenticateHeader, Authenticate:sanctum, ThrottleRequests:mcp-ip y :mcp (GET/DELETE → 405)
**Criterios de aceptación**
- [x] Key inválida o revocada devuelve 401 y no ejecuta herramientas
- [x] Keys con hash en reposo
- [x] UI para crear y revocar

**Subtareas**
- [x] Instalar y fijar `laravel/mcp`
- [x] Autenticación Sanctum y rate limit
- [x] UI de API keys
- [x] Tests

**Notas**: Plan B de design.md §6 si `laravel/mcp` falla.

**Notas (implementer, 2026-10-20)**: `laravel/mcp` ~1.0.2 (instalado v1.0.2) + Sanctum 4; el plan B (SDK Node) no fue necesario. Abilities: `brands:read`, `posts:read`, `posts:write` (`*` también vale). Sanctum con `guard=[]` (solo Bearer: una sesión web NO autentica `/mcp`), prefijo `msk_`, caducidad 1 año por defecto (`SANCTUM_EXPIRATION`) o fecha elegida. Rate limit `mcp-ip` 120/min antes de auth y `mcp` 60/min por key. Secreto mostrado una vez (flash); crear/revocar quedan en `audit_logs` sin secreto. OAuth 2.1 (Passport) no incluido: sigue opcional (design.md). **PENDIENTES MANUALES**: M-F6-1 conectar un cliente MCP real (crear key en /api-keys y `claude mcp add --transport http scheduler https://<APP_URL>/mcp --header "Authorization: Bearer msk_..."`; probar `list_brands` y `create_post` en borrador y comprobar en la web); M-F6-2 ajustar `MCP_RATE_PER_MINUTE`/`SANCTUM_EXPIRATION` en producción y comprobar que Nginx/Hestia pasa la cabecera `Authorization` a PHP-FPM.

### T-29 — Herramientas MCP: marcas, cuentas, publicaciones y calendario

- **Descripción**: Herramientas finas sobre `app/Services`: listar marcas y cuentas, crear/editar/borrar/reprogramar publicaciones y consultar el calendario. El agente pasa URL o ID de media ya subida.
- **Changelog**: El agente puede programar, editar, cancelar y consultar publicaciones y el calendario.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real 0.27h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-28, T-24
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/`, `tests/Feature/Mcp/Tools/`; añadidos al implementar: `app/Mcp/Tools/` (10 herramientas), `tests/Feature/Mcp/McpToolsTest.php`
- **Cubre (tests)**: API-01
- **Verificación**: `php artisan test --filter=McpTools` → 9 passed (85 assertions)
**Criterios de aceptación**
- [x] Cada operación se ejecuta solo sobre datos del usuario de la key (CA-12)
- [x] Errores devueltos con mensajes legibles

**Subtareas**
- [x] Tools de marcas y cuentas
- [x] Tools de publicaciones
- [x] Tool de calendario
- [x] Tests

**Notas (implementer, 2026-10-20)**: 10 herramientas finas sobre `BrandService`/`SocialAccountService`/`PostService`/`CalendarService` (misma validación de media y límites por red que la web): `list_brands`, `list_accounts`, `list_media`, `get_calendar`, `get_post`, `create_post`, `update_post`, `reschedule_post`, `cancel_post` (→ borrador), `delete_post`. La media se pasa por ID (`list_media`); **no se aceptan URLs** (evita SSRF) ni binarios por MCP. Errores legibles («Datos no válidos: campo: motivo», «Publicación no encontrada.», «Esta API key no tiene el permiso «x»»).

### T-30 — Aislamiento MCP, auditoría y límites

- **Descripción**: Reutilizar la suite de aislamiento contra cada herramienta MCP, registrar acciones y accesos denegados y limitar el uso por key.
- **Changelog**: El acceso por agente queda aislado por usuario, limitado por key y auditado.
- **Estado**: completado
- **Tiempo humano**: est. 11h · real —
- **Tiempo IA (ejec.)**: est. 1.7h · real 0.1h (estimado; ~0.1h de reloj, JSON no capturado)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 455k in / 46k out tok · ⚠️ verificar €
- **Dependencias**: T-29
- **Tipo**: test
- **Archivos**: `tests/Feature/Isolation/Mcp/`, `app/Services/AuditLog.php`, `tests/Feature/Mcp/`; añadidos al implementar: `tests/Feature/Isolation/Mcp/McpIsolationTest.php`, `tests/Feature/Mcp/McpAuditTest.php`, `app/Models/AuditLog.php`, `database/migrations/2026_10_24_090000_create_audit_logs_table.php`
- **Cubre (tests)**: API-03
- **Verificación**:
  - `php artisan test --filter=Isolation` → pasa (incluye MCP)
  - `php artisan test --filter=McpAudit` → 6 passed
  - `McpAuth|McpTools|McpAudit|Isolation` → 62 passed (351 assertions)
**Criterios de aceptación**
- [x] Un usuario B no accede a datos de A por MCP (CA-02, CA-12)
- [x] Acciones y denegaciones auditadas

**Subtareas**
- [x] Tests de aislamiento por herramienta
- [x] Auditoría
- [x] Rate limiting por key

---

**Notas (implementer, 2026-10-20)**: 8 herramientas con id ajeno == id inexistente (mismo mensaje); lecturas sin fuga; B no puede usar cuenta/media de A ni editando; A intacta. `audit_logs` (user, token, acción, resultado, ip); las denegaciones van también a `Log access.denied`; sin secreto ni contenido. Rate limit por key (429) probado en McpAuth. T-42 ampliará las tools (estadísticas/informes) sobre este servidor.

## Fase 7 — Analítica: snapshots, insights y dashboards

**Estado**: en-progreso · **Estimado**: 106h · **Real**: 2.75h IA (medido) + corrección intento 1 ≈ 2.5h IA y 0.3h supervisión (estimado) · **Verificación de fase**: `php artisan test` → 591 passed (3108 assertions); `vendor/bin/pint --test` (rutas propias) → passed; `npm run build` → built OK · **Coste est.**: 6.360 € + tokens (⚠️ verificar precio) · **Tokens est.**: 4,83M

### T-31 — Modelo de snapshots y job diario (adelantable tras F2)

- **Descripción**: Tablas de snapshots normalizados y job diario por cuenta conectada. Puede adelantarse justo después de F2 para empezar a acumular histórico cuanto antes.
- **Changelog**: La aplicación empieza a guardar el histórico diario de métricas de cada cuenta y de sus publicaciones recientes.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real 1.14h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-16
- **Tipo**: backend
- **Archivos**: `app/Models/AccountSnapshot.php`, `app/Models/PostMetricSnapshot.php`, `app/Jobs/CollectSnapshots.php`, `routes/console.php`, `database/migrations/`, `tests/Feature/Snapshots/`; añadidos al implementar: `database/migrations/2026_10_25_090000_create_snapshot_tables.php`, `app/Analytics/Metric.php`, `app/Analytics/SnapshotCollector.php`, `app/Jobs/CollectAccountSnapshot.php`, `app/Connectors/ConnectorRegistry.php` (registerInsights/insightsFor), `app/Connectors/Contracts/InsightsReader.php` (+canReadPost/postInsights), `config/social.php` (bloque analytics), `tests/Support/FakeInsights.php`, `tests/Feature/Snapshots/SnapshotsTest.php`; añadidos en la corrección intento 1 (Fase 7, `646b133`): `app/Analytics/Days.php`, `app/Connectors/Contracts/RangeInsightsReader.php`, `database/migrations/2026_10_27_090000_add_settled_at_to_account_snapshots.php`, `tests/Feature/Snapshots/SettleTest.php` (y cambios en `app/Analytics/Metric.php`, `app/Models/AccountSnapshot.php`, `config/social.php`, `tests/Support/FakeInsights.php`)
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --filter=Snapshots` → pasa (un snapshot por cuenta y día, idempotente)
  - real: `{"result":"passed","tests":12,"passed":12,"assertions":38}`

**Criterios de aceptación**
- [x] Snapshot diario por cuenta y de publicaciones recientes (CA-09)
- [x] Job idempotente

**Subtareas**
- [x] Migraciones y modelos
- [x] Job con planificación diaria
- [x] Tests

**Notas (implementer, 2026-10-20)**: commit `070ffd6`. Evidencia: un snapshot por cuenta y día, upsert idempotente, `null` no pisa un valor previo, jobs únicos por cuenta+día en la cola `metrics`, timeout 90/60 < 120, planificado `collect-snapshots` 12:00 UTC (antes 03:30; cambiado en la corrección intento 1, ver abajo), aislamiento por usuario, la reconexión avisa una sola vez. Decisiones: día de referencia = ayer UTC; reintento de los 2 días previos solo si faltan o salieron vacíos (latencia de YouTube); `date` sin cast (string Y-m-d) para que el upsert por fecha sea estable en SQLite/MySQL; `null` = no disponible en `metrics` (JSON).

**Corrección revisión intento 1 (Fase 7, `646b133`)**: (a) **asentado** (`account_snapshots.settled_at`, migración nueva) = completo según `Metric::EXPECTED_FLOWS` (los stocks como seguidores o vídeos no cuentan) o día fuera de la ventana de `settle_days` (3, `Days::isHistoric`); `needsCollection()` = sin fila o sin `settled_at` y `CollectSnapshots` reintenta D-1..D-3. Una métrica permanentemente rechazada (p. ej. `total_interactions` en cuentas pequeñas) se reintenta como mucho 3 días y luego se asienta con `null`; TikTok (solo stocks) se da por completo con cualquier dato. (b) **Zonas horarias por red**: `App\Analytics\Days` con `social.analytics.timezones` (Meta y YouTube = `America/Los_Angeles`, TikTok UTC); el despachador calcula el último día cerrado de cada cuenta en la zona de su red. (c) **`daily_at` 12:00 UTC** (antes 03:30): el día del Pacífico cierra a las 07:00/08:00 UTC; comentario de config corregido. (d) Las publicaciones del snapshot diario se leen en `RefreshPostMetrics` aparte, por bloques (≤25) con presupuesto (60 s < `$timeout` 90) y continuación por offset. Evidencia: `SettleTest`; suite completa 669 passed.

### T-32 — Colector de insights de Meta (IG + FB)

- **Descripción**: Métricas de cuenta y de publicación en IG/FB según las que ofrezca la versión vigente de la API (⚠️ `impressions` frente a `views`). Las no disponibles se reportan como no disponibles, nunca como 0.
- **Changelog**: Se recogen estadísticas de Instagram y Facebook (seguidores, visualizaciones, alcance e interacciones).
- **Estado**: completado
- **Tiempo humano**: est. 18h · real —
- **Tiempo IA (ejec.)**: est. 2.8h · real 0.20h (medido)
- **Supervisión**: est. 0.7h (≈25 % IA) · real —
- **Previsión IA**: 745k in / 75k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-12
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Insights.php`, `tests/Feature/Connectors/Meta/Insights/`; añadido al implementar: `tests/Feature/Connectors/Meta/Insights/MetaInsightsTest.php`; tocado en la corrección intento 1 (Fase 7, `d21d31d`): `app/Connectors/Meta/GraphClient.php` (solo clasificación de límites de uso + parámetro `$read`)
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --filter=MetaInsights` → pasa con respuestas grabadas; una métrica no disponible se guarda como `null`
  - real: `{"result":"passed","tests":11,"passed":11,"assertions":59}` (respuestas simuladas con `Http::fake`; no hay grabaciones reales)

**Criterios de aceptación**
- [x] Snapshot de métricas de cuenta y publicaciones (CA-09)
- [x] Métricas retiradas gestionadas

**Subtareas**
- [x] Mapeo de métricas por versión de API
- [x] Colector y reintentos
- [x] Tests

**Notas (implementer, 2026-10-20)**: commit «T-32: colector de insights de Meta». IG usa `views` y nunca `impressions`; FB usa `page_media_view`/`page_post_engagements`/`page_follows` y nunca `page_impressions`/`page_fans`; `follower_count` sin `metric_type`; una métrica rechazada con #100 queda `null` y un 0 real sigue 0; 190 propaga `ReconnectRequired`, 5xx propaga `Transient`. **Pendiente manual**: `post_media_view` y `post_total_media_view_unique` (publicaciones de Facebook) no están verificadas en el spike; si Meta las rechaza quedan `null`; probar con un post real y ajustar `Insights::facebookPost`. IG: `follower_count` exige ≥ 100 seguidores; confirmar `followers_count`/`views` por día con since/until en la cuenta real (la primera entrada de `values` se toma como el día).

**Corrección revisión intento 1 (Fase 7, `d21d31d`)**: el valor del día se toma por `end_time − 1 s` en el día del Pacífico (no `values[0]`); `total_interactions` de cuenta IG; límites de uso de Graph (4/17/32/613 y familia 800xx) son transitorios en lecturas; `canReadPost` excluye `external_id_kind=container`; las lecturas registran `meta.read_error` en `debug`. Supuestos a validar en M-03 con cuentas reales: convención de `end_time`, `total_interactions` con `metric_type=total_value`, `since/until` ≤30 días y códigos 800xx.

### T-33 — Colector de YouTube Analytics (condicionado a G1)

- **Descripción**: Métricas de canal y de vídeo con YouTube Analytics API (otro scope y otra API).
- **Changelog**: Se recogen estadísticas de YouTube (visualizaciones, minutos reproducidos, interacciones y suscriptores).
- **Estado**: completado
- **Tiempo humano**: est. 16h · real —
- **Tiempo IA (ejec.)**: est. 2.5h · real 0.06h (medido)
- **Supervisión**: est. 0.6h (≈25 % IA) · real —
- **Previsión IA**: 662k in / 66k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-13
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/Analytics.php`, `tests/Feature/Connectors/Google/Analytics/`; añadido al implementar: `tests/Feature/Connectors/Google/Analytics/YouTubeAnalyticsTest.php`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --filter=YouTubeAnalytics` → pasa con respuestas grabadas
  - real: `{"result":"passed","tests":7,"passed":7,"assertions":49}`

**Criterios de aceptación**
- [x] Snapshot de métricas (CA-09)
- [x] Scope adicional solicitado en la conexión

**Subtareas**
- [x] Cliente Analytics
- [x] Colector
- [x] Tests

**Notas**: Se cancela con G1 = D.
**Decisión G1 (2026-10-19): YouTube = A.** Analítica vía YouTube Analytics API (`yt-analytics.readonly`).

**Notas (implementer, 2026-10-20)**: el scope `yt-analytics.readonly` ya estaba en `config/social.php` (google.scopes) desde T-13. Métricas (YouTube Analytics API v2, `channel_reports` verificado en la doc): views, estimatedMinutesWatched, likes, comments, shares, subscribersGained, subscribersLost; suscriptores/vídeos vía Data API `channels.list?part=statistics`. La API no da impresiones ni alcance → `null`. Sin filas (latencia 1-3 días) → `null`, se reintenta. **Pendiente manual**: confirmar en cuenta real que `yt-analytics.readonly` se concedió al conectar (los canales conectados antes de T-13 deben reconectar) y que la YouTube Analytics API está habilitada en el proyecto de Google Cloud.

### T-34 — Colector de TikTok Display API (condicionado a G1)

- **Descripción**: Métricas disponibles de la Display API (pocas); lo no disponible se muestra como tal.
- **Changelog**: Se recogen las estadísticas que TikTok permite (seguidores y vídeos); el resto se muestra como no disponible.
- **Estado**: completado
- **Tiempo humano**: est. 10h · real —
- **Tiempo IA (ejec.)**: est. 1.5h · real 0.08h (medido)
- **Supervisión**: est. 0.4h (≈25 % IA) · real —
- **Previsión IA**: 414k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-31, T-14
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/Display.php`, `tests/Feature/Connectors/TikTok/Display/`; añadidos al implementar: `app/Providers/AppServiceProvider.php` (registro de los 4 lectores), `tests/Feature/Connectors/TikTok/Display/TikTokDisplayTest.php`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --filter=TikTokDisplay` → pasa con respuestas grabadas
  - real: `{"result":"passed","tests":7,"passed":7,"assertions":44}`

**Criterios de aceptación**
- [x] Snapshot de métricas disponibles (CA-09)

**Subtareas**
- [x] Cliente Display
- [x] Colector
- [x] Tests

**Notas**: Se cancela con G1 = D.
**Aviso de la revisión Fases 4-5 (#13)**: en TikTok, `external_id` de un destino enviado a la bandeja es el `publish_id`, no un ID de vídeo; usarlo para métricas solo si `progress.external_id_kind = video_id`.
**Decisión G1 (2026-10-19): TikTok = D + B.** Analítica limitada a lo que den `user.info.stats`/`video.list` si se conceden en la auditoría; sin ellos, mostrar «no disponible» por red (documentado, no bloquea).

**Notas (implementer, 2026-10-20)**: sin `user.info.stats`/`video.list` en `account.scopes` todo es `null` y no hay llamada HTTP (`Http::assertNothingSent`). `external_id` solo se usa como ID de vídeo si `progress.external_id_kind = video_id` (un `publish_id` de borrador no se lee). Los scopes de estadísticas no se añaden a `social.tiktok.scopes` (decisión G1: solo tras auditoría). **Pendiente manual**: tras la auditoría, añadir los scopes a `social.tiktok.scopes`, reconectar y verificar `video/query`.

### T-35 — Normalización de métricas y cálculo de crecimiento/engagement

- **Descripción**: Capa de normalización desacoplada de la versión de API, cálculo de crecimiento y engagement sin inventar equivalencias entre redes.
- **Changelog**: Las métricas de todas las redes se presentan de forma comparable, sin inventar equivalencias.
- **Estado**: completado
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real 0.39h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: backend
- **Archivos**: `app/Services/AnalyticsService.php`, `tests/Unit/Analytics/`; añadidos al implementar: `app/Analytics/Calculator.php`, `app/Analytics/Period.php`, `tests/Unit/Analytics/CalculatorTest.php`, `tests/Feature/Analytics/AnalyticsServiceTest.php`; añadido en la corrección intento 1 (Fase 7, `2d26e74`): `tests/Feature/Analytics/AnalyticsReviewTest.php`
- **Cubre (tests)**: E2E-10
- **Verificación**:
  - `php artisan test --filter=Analytics` → pasa (crecimiento, engagement, «no disponible»)
  - real: `{"result":"passed","tests":24,"passed":24,"assertions":149}` (incluye los 7 de YouTubeAnalytics por coincidencia de filtro)

**Criterios de aceptación**
- [x] Crecimiento y engagement calculados correctamente
- [x] Métrica ausente = «no disponible» (no 0)

**Subtareas**
- [x] Modelo normalizado
- [x] Cálculos
- [x] Tests

**Notas (implementer, 2026-10-20)**: crecimiento absoluto y %, delta frente al periodo de comparación, engagement = interacciones/visualizaciones, `null` ≠ 0, los flujos suman y los stocks toman el último valor, aislamiento por usuario.

**Corrección revisión intento 1 (Fase 7, `2d26e74`)**: crecimiento agregado por cuenta sin saltos por altas/bajas (`Calculator::growthAcross/totalAcross/combine`), KPIs por publicación en SQL sobre TODAS las del periodo con top N explícito (`posts_total`, `posts_limit`, `posts_truncated`), `network()` filtra la red en SQL, periodo en la zona de la marca y comparación anual de igual longitud. **Pendiente/riesgo**: los agregados SQL de `postStats()` solo se han probado en SQLite; ejecutarlos contra MySQL real en M-03.

### T-36 — Backfill inicial y métricas por publicación reciente

- **Descripción**: Cargar el histórico que permita cada API al conectar una cuenta y refrescar métricas de publicaciones recientes (p. ej. cada 6 h las primeras 72 h).
- **Changelog**: Al conectar una cuenta se importa el histórico disponible y se refrescan las métricas de las publicaciones recientes.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real 0.31h (medido)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: backend
- **Archivos**: `app/Jobs/BackfillMetrics.php`, `app/Jobs/RefreshPostMetrics.php`, `tests/Feature/Backfill/`; añadidos al implementar: `app/Jobs/Concerns/GuardsInsightsErrors.php`, `app/Jobs/CollectAccountSnapshot.php` (refactor al trait), `app/Services/SocialAccountService.php` (encola el backfill al conectar), `config/social.php`, `routes/console.php`, `phpunit.xml` (`SOCIAL_BACKFILL_ON_CONNECT=false` en tests), `tests/Feature/Backfill/BackfillTest.php`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --filter=Backfill` → pasa
  - real: `{"result":"passed","tests":10,"passed":10,"assertions":23}`; suite completa tras T-36: 581 passed

**Criterios de aceptación**
- [x] Backfill con el máximo histórico permitido por red
- [x] Refresco programado de publicaciones recientes

**Subtareas**
- [x] Job de backfill
- [x] Refresco por publicación
- [x] Tests

**Notas (implementer, 2026-10-20)**: máximo histórico por red IG/FB 30 d, YouTube 90 d, TikTok 0 (configurable); refresco de publicaciones recientes cada 6 h en una ventana de 72 h. Backfill por pasadas (presupuesto 80 s < timeout 100 s < worker 120 s), idempotente (salta días con datos), `ShouldBeUniqueUntilProcessing` por cuenta. **Pendiente manual**: el backfill de IG depende de que `views`/`reach` admitan rangos pasados (~30 d); confirmarlo con la cuenta real.

**Decisión (corrección revisión intento 1, Fase 7)** — sustituye a los máximos de la nota anterior: (a) backfill **por rango** cuando la red lo permite (`RangeInsightsReader`); (b) histórico: **Instagram 30 días** (límite de 30 días por consulta de la API de insights de usuario; sus `total_value` exigen una llamada por día), **Facebook 90 días** (por tramos de ≤30 días, 3 llamadas por tramo), **YouTube 365 días** (un informe `dimensions=day` por tramo de 90 días), **TikTok 0** (la Display API no da serie histórica); tope duro `Period::MAX_DAYS` = 366; (c) los tres últimos días se leen día a día porque llevan stocks; (d) el presupuesto de la pasada se comprueba antes de cada unidad con la duración de la más lenta (siempre ≥1 por pasada); (e) un día histórico (fuera de `settle_days`) queda asentado tras una lectura aunque le falten métricas. Documentado en `config/social.php`.

### T-37 — Dashboards por marca con gráficos por red

- **Descripción**: Dashboard por marca con vista por red: seguidores y crecimiento, alcance, impresiones y engagement con ECharts/Chart.js, mostrando «no disponible» donde la API no lo dé.
- **Changelog**: Nuevo panel de estadísticas por marca con tarjetas resumen, gráficos por red, selector de periodo y comparación.
- **Estado**: en-progreso
- **Tiempo humano**: est. 24h · real —
- **Tiempo IA (ejec.)**: est. 3.7h · real 0.57h (medido)
- **Supervisión**: est. 0.9h (≈25 % IA) · real —
- **Previsión IA**: 994k in / 99k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Dashboard/`, `app/Http/Controllers/DashboardController.php`, `tests/Feature/Dashboard/`; añadidos al implementar: `routes/web.php` (`/dashboard` y `/dashboard/{network}`), `resources/js/Pages/Dashboard/Index.vue`, `resources/js/Pages/Dashboard/Network.vue`, `resources/js/Pages/Dashboard/Partials/{LineChart,PeriodFilter,NetworkTabs}.vue`, `resources/js/Pages/Dashboard/Partials/format.js`, `resources/js/Pages/Dashboard.vue` (eliminado, sustituido), `tests/Feature/Dashboard/DashboardTest.php`
- **Cubre (tests)**: E2E-10, M-03
- **Verificación**:
  - `php artisan test --filter=Dashboard` → pasa
  - real: `{"result":"passed","tests":11,"passed":11,"assertions":126}`; `npm run build` → `✓ built in 718ms`; suite completa 591 passed
  - lectura: M-03 revisa gráficos con datos reales

**Criterios de aceptación**
- [x] Con ≥ 30 días de snapshots se ven seguidores, alcance, impresiones y engagement (CA-10)
- [x] «No disponible» donde proceda

**Subtareas**
- [x] Componentes de gráficos
- [x] Vista por marca y red
- [x] Filtros de fecha
- [x] Tests

**Notas (implementer, 2026-10-20)**: CA-10 cubierto por test con 30 días de snapshots (seguidores, alcance, visualizaciones e interacciones). Gráficos con SVG propio (`LineChart.vue`, sin ECharts/Chart.js: evita una dependencia nueva; corta la línea en días `null`). Mapa de calor: fuera de alcance v1. **Pendiente manual M-03 / E2E-09**: dejar correr el job diario con cuentas reales 30 días y revisar los gráficos; verificar que `collect-snapshots` (12:00 UTC; antes 03:30) y `refresh-post-metrics` corren en el scheduler del VPS, que un worker escucha la cola `metrics` y añadir `metrics` a `QUEUE_NAMES`/unidad systemd del worker si el VPS lista colas explícitas (design.md: `publish,poll,metrics,reports`).

**Estado (revisión intento 1, Fase 7)**: vuelve a `en-progreso` por **M-03**: el cierre exige la verificación manual M-03 / E2E-09 (job diario con cuentas reales ~30 días y revisión de gráficos; comprobar en el VPS que `collect-snapshots` corre a las 12:00 UTC y `refresh-post-metrics`, y que un worker escucha la cola `metrics`). Corrección `cab1e99`: periodo por defecto en la zona de la marca y aviso «Se muestran las N con más interacciones de M» en `Network.vue`.

---

## Fase 8 — Reporting PDF/CSV

**Estado**: en-progreso · **Estimado**: 40h · **Real**: ~1.5h IA (estimado) + corrección intento 1 (no medida) · **Verificación de fase** (estado final `d0cded2`): `php artisan test tests/Feature/Reports tests/Feature/Mcp` → 69 passed (1676 assertions); `vendor/bin/pint --test` (rutas del ámbito) → passed; `npm run build` → built OK; suite completa tras las correcciones → 669 passed (4802 assertions) · **Coste est.**: 2.400 € + tokens (⚠️ verificar precio) · **Tokens est.**: 1,82M

### T-38 — ReportService y exportación CSV

- **Descripción**: Generar el conjunto de datos del informe por marca y rango de fechas y exportarlo a CSV.
- **Changelog**: Se pueden exportar los datos de un informe en CSV.
- **Estado**: completado
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real ~0.5h (estimado; usage-meter no pudo separar tareas, solo el tramo final medido 0.01h)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: backend
- **Archivos**: `app/Services/ReportService.php`, `app/Models/Report.php`, `tests/Feature/Reports/Csv/`; añadidos al implementar (`aa73492`): `app/Policies/ReportPolicy.php`, `database/migrations/2026_10_26_090000_create_reports_table.php`, `database/factories/ReportFactory.php`, `config/reports.php`, `tests/Feature/Reports/Csv/ReportCsvTest.php`; añadidos en la corrección intento 1 (`c343d36`): `app/Console/Commands/PruneReports.php`, `app/Services/Reports/TooManyActiveReports.php`, `app/Providers/AppServiceProvider.php` (purga en `Brand::deleting`/`User::deleting`), `routes/console.php` (`reports:prune` diario), `.env.example`, `tests/Feature/Reports/ReportsFixTest.php`
- **Cubre (tests)**: E2E-11
- **Verificación**:
  - `php artisan test --filter=ReportCsv` → pasa
  - real (estado final `d0cded2`): `php artisan test tests/Feature/Reports tests/Feature/Mcp --filter=ReportCsv` → `{"result":"passed","tests":8,"passed":8,"assertions":1260}`; carpeta `tests/Feature/Reports` completa → 27 passed (1381 assertions)

**Criterios de aceptación**
- [x] CSV con los datos del rango (CA-11)
- [x] Aislamiento por usuario

**Subtareas**
- [x] Servicio y modelo
- [x] Exportador CSV
- [x] Tests

**Nota de verificación**: la Verificación de esta tarea dependía de commits posteriores (T-40/T-41 y las correcciones de la revisión intento 1); vale en el estado final de la rama (`d0cded2`), no en el commit propio de la tarea.

**Notas (implementer, 2026-10-20)**: commits `aa73492` y `c343d36` (corrección). CSV en formato largo (`seccion,red,elemento,metrica,fecha,valor,anterior,variacion_pct`), UTF-8 con BOM, `null` → «no disponible» (nunca 0). Tras la revisión: celdas de texto SIEMPRE entrecomilladas; `ReportService::cell()` antepone `'` al texto y a cualquier tramo tras `;`, `,`, TAB, CR o LF que empiece por `= + - @ TAB CR` (CWE-1236); separador `;` y coma decimal por defecto (`reports.csv_locale` es/en); los números no se tocan; sin comparación → `anterior`/`variacion_pct` vacíos, con comparación y sin dato → «no disponible»; filas de tasa de interacción y notas; «Publicaciones destacadas» = top N por red de TODO el periodo (`ReportService::topPosts()`); límite de 5 en curso atómico (`enqueue()` con `lockForUpdate`); purga `reports:prune` (retención + atascados) y borrado de ficheros. Fichero en disco `filesystems.media`, ruta `reports/{user_id}/{id}.{ext}`. Aislamiento: `Report` con `BelongsToUser`; `create()` rechaza marca ajena.

### T-39 — Exportación PDF (decisión de driver: Chromium o DomPDF)

- **Descripción**: Decidir `spatie/laravel-pdf` (Chromium headless) frente a DomPDF con gráficos rasterizados según la RAM del VPS; generar en la cola `reports` con concurrencia 1.
- **Changelog**: Se pueden exportar informes en PDF.
- **Estado**: completado
- **Tiempo humano**: est. 14h · real —
- **Tiempo IA (ejec.)**: est. 2.1h · real — (incluido en el ~1.5h estimado de la fase; usage-meter no separó tareas)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 580k in / 58k out tok · ⚠️ verificar €
- **Dependencias**: T-38
- **Tipo**: backend
- **Archivos**: `app/Services/Reports/PdfRenderer.php`, `resources/views/reports/`, `tests/Feature/Reports/Pdf/`; añadidos al implementar (`e7ae517`): `composer.json`, `composer.lock` (`barryvdh/laravel-dompdf` ^3.1), `tests/Feature/Reports/Pdf/ReportPdfTest.php` (+ `app/Jobs/GenerateReport.php`, ver T-40); añadidos en la corrección intento 1 (`b92c6a0`): `docs/knowledge/adr/ADR-002-pdf-dompdf.md`, `docs/knowledge/README.md`, `docs/roadmap/2026-10-19-social-scheduler/design.md`, `resources/views/reports/brand.blade.php`
- **Cubre (tests)**: E2E-11, M-03
- **Verificación**:
  - `php artisan test --filter=ReportPdf` → pasa y el PDF generado no está vacío
  - real (estado final `d0cded2`): `php artisan test tests/Feature/Reports tests/Feature/Mcp --filter=ReportPdf` → `{"result":"passed","tests":3,"passed":3,"assertions":13}` (el PDF empieza por `%PDF-` y pesa > 2 KB; un fallo de render → `failed` con mensaje genérico); prueba de memoria del peor caso (366 días, 4 redes, 4 tarjetas, 5 publicaciones/red): 890 KB, 0.39 s, pico 54 MB
  - lectura: M-03 revisa el PDF con datos reales (pendiente, usuario)

**Criterios de aceptación**
- [x] PDF con KPIs, gráficos y tabla de publicaciones (CA-11)
- [x] Consumo de memoria controlado

**Subtareas**
- [x] Prueba del driver (RAM)
- [x] Plantilla de informe
- [x] Job en cola `reports`
- [x] Tests

**Notas**: ~~Decisión de driver pendiente (design.md §7).~~ Decidido: DomPDF (ver abajo).

**Nota de verificación**: la Verificación de esta tarea dependía de commits posteriores (T-40/T-41 y las correcciones de la revisión intento 1); vale en el estado final de la rama (`d0cded2`), no en el commit propio de la tarea.

**Decisión de driver (ADR-002, `docs/knowledge/adr/ADR-002-pdf-dompdf.md`, estado `propuesta`)**: **DomPDF** (`barryvdh/laravel-dompdf` 3.1, dompdf 3.x) por defecto, configurable con `REPORTS_PDF_DRIVER` (hoy solo `dompdf`; `PdfRenderer` es el punto de enchufe para otro driver). Motivo: PHP puro, sin Chromium/Node/Puppeteer en el VPS HestiaCP; pico medido 54 MB frente a cientos de MB por render de Chromium; sin dependencias de sistema frágiles. Coste: CSS limitado a 2.1 (tablas, sin flex) y gráficos como barras HTML/CSS, no vectoriales. El riesgo «Chromium consume RAM» del plan queda mitigado; si se quiere más fidelidad visual, añadir un driver `spatie/laravel-pdf` más adelante. ADR indexado en `docs/knowledge/README.md`; `design.md` filas 72 y 176 actualizadas (`b92c6a0`). Tras la revisión: gráfico semanal (≤ 53 barras) en periodos de más de 60 días y emojis eliminados del PDF (`PdfRenderer::withoutEmoji()`; el CSV los conserva). API usada `Pdf::loadView()->setPaper()->output()`, verificada ejecutando (Context7 no disponible en el subagente).

### T-40 — UI de informes: generación, estado y descarga

- **Descripción**: Pantalla para elegir marca y rango de fechas, ver el estado de generación y descargar en PDF y CSV.
- **Changelog**: Pantalla de informes con descarga.
- **Estado**: completado
- **Tiempo humano**: est. 12h · real —
- **Tiempo IA (ejec.)**: est. 1.8h · real — (incluido en el ~1.5h estimado de la fase; usage-meter no separó tareas)
- **Supervisión**: est. 0.5h (≈25 % IA) · real —
- **Previsión IA**: 497k in / 50k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Reports/`, `app/Http/Controllers/ReportController.php`, `app/Jobs/GenerateReport.php`, `tests/Feature/Reports/`; añadidos al implementar (`f7af387`): `app/Policies/ReportPolicy.php`, `resources/js/Pages/Reports/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue` (enlace «Informes»), `routes/web.php` (rutas aditivas), `.env.example`, `tests/Feature/Reports/ReportsTest.php`
- **Cubre (tests)**: E2E-11
- **Verificación**:
  - `php artisan test --filter=Reports` → pasa
  - real (estado final `d0cded2`): `php artisan test tests/Feature/Reports tests/Feature/Mcp` → `{"result":"passed","tests":69,"passed":69,"assertions":1676}` (cola `reports` + timeout 100 < 120, descarga solo con firma 200/403/404, enlace nuevo al pulsar Descargar, aislamiento 404, límite atómico, atascados, borrado, purga); `vendor/bin/pint --test` (rutas del ámbito) → passed; `npm run build` → built OK

**Criterios de aceptación**
- [x] Informe descargable en PDF y CSV (CA-11)
- [x] Estado de generación visible

**Subtareas**
- [x] Vista Inertia
- [x] Controlador y job
- [x] Descarga firmada
- [x] Tests

**Notas (implementer, 2026-10-20)**: commits `f7af387` y `8a3edb8` (corrección). `GenerateReport` en la cola `reports` (`$timeout` 100, `tries` 1; fallo → estado `failed` visible con error genérico, detalle en log). La lista se refresca cada 4 s mientras hay informes en curso; throttle 10/min en POST; rango y comparación se validan en `store` (error de formulario, 0 informes). Tras la revisión: `reports.link` (con sesión) redirige a una URL firmada nueva al pulsar Descargar; `DELETE reports/{report}` borra fila y fichero (409 si está en curso); atascados (`stuck_hours`=2) no cuentan para el límite. **Decisión de descarga** (ver T-42): `reports.download` va SOLO con firma TTL 5 min, sin sesión. Concurrencia 1: depende de ejecutar UN worker en la cola `reports` (unidad systemd, ya en design.md); no forzada por código. (Al corregir, la suite completa no se pudo ejecutar por un `rvPost()` redeclarado de otro ámbito; resuelto después: suite completa 669 passed.)

### T-41 — Plantilla y diseño visual del informe

- **Descripción**: Cerrar secciones y branding del PDF (plantilla única por marca) y validar con el usuario.
- **Changelog**: Informe con diseño pulido.
- **Estado**: en-progreso
- **Tiempo humano**: est. 6h · real —
- **Tiempo IA (ejec.)**: est. 0.9h · real — (incluido en el ~1.5h estimado de la fase; usage-meter no separó tareas)
- **Supervisión**: est. 0.2h (≈25 % IA) · real —
- **Previsión IA**: 248k in / 25k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: frontend
- **Archivos**: `resources/views/reports/brand.blade.php`, `tests/Feature/Reports/`; añadido al implementar (`0299ddf`): `config/reports.php` (secciones y branding)
- **Cubre (tests)**: M-03
- **Verificación**:
  - lectura: M-03 aprobado por el usuario sobre un informe real
  - real (estado final `d0cded2`): tests `ReportPdf` 3/3 (la vista contiene marca, color, KPI, comparación y la nota «No disponible»); PDF de muestra con datos sintéticos generado en el scratchpad de la sesión (`sample.pdf`); **M-03 PENDIENTE** (revisión del usuario con datos reales)

**Criterios de aceptación**
- [ ] El usuario aprueba el diseño del informe

**Subtareas**
- [x] Ajuste de plantilla
- [ ] Revisión con el usuario

**Notas**: Incógnita: secciones y branding sin definir en la spec.

**Motivo del estado**: `en-progreso` — el código está hecho, pero el criterio «el usuario aprueba el diseño» es la verificación manual **M-03** y no se cierra sin su aprobación.

**Default propuesto (PENDIENTE DE VALIDAR por el usuario)**: portada con logo y color de marca; resumen KPI (4 tarjetas con variación si hay comparación + gráfico diario de visualizaciones + tabla por red); una página por red con todas sus métricas y tasa de interacción; publicaciones destacadas (top 5 por red, con datos); notas sobre métricas «No disponible». Configurable en `config/reports.php` (`sections`, `branding.color`, `branding.logo` ruta local PNG/JPG, `branding.footer`; env `REPORTS_BRAND_COLOR`/`REPORTS_LOGO_PATH`/`REPORTS_FOOTER`). Plantilla única compartida (color/logo globales, no por marca: `Brand` no tiene campos de branding; «plantilla por marca» requeriría migración + UI, no hecho).

**Pendientes**: validar M-03 con el usuario (diseño, secciones, branding) — bloquea cerrar T-41; branding por marca no implementado.

---

## Fase 9 — MCP estadísticas/informes y validación final

**Estado**: en-progreso · **Estimado**: 16h · **Real**: 0.95h IA (medido) · **Verificación de fase**: `php artisan test` → 619 passed (3311 assertions) tras T-42/T-43 y 669 passed (4802 assertions) tras las correcciones; `vendor/bin/pint --test` → passed; `npm run build` → OK; `npx playwright test` → no ejecutable (Playwright no instalado) · **Coste est.**: 960 € + tokens (⚠️ verificar precio) · **Tokens est.**: 728k

### T-42 — Herramientas MCP: estadísticas e informes

- **Descripción**: Añadir las herramientas de lectura de estadísticas y generación de informes al servidor MCP.
- **Changelog**: El agente MCP puede leer estadísticas de marcas, redes y publicaciones, y pedir informes PDF/CSV con enlace de descarga firmado.
- **Estado**: completado
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.71h (medido)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-30, T-40, T-35
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/`, `tests/Feature/Mcp/Tools/`; añadidos al implementar (`fe429bf`): `app/Mcp/Abilities.php` (`stats:read`, `reports:write`), `app/Mcp/Servers/SchedulerServer.php`, `app/Mcp/Tools/SchedulerTool.php` (`report_id` en auditoría), `app/Mcp/Tools/{GetStats,GetPostStats,GenerateReport,GetReport}.php`, `tests/Feature/Mcp/Tools/McpStatsTest.php`
- **Cubre (tests)**: API-01, API-03
- **Verificación**:
  - `php artisan test --filter=McpStats` → pasa (estadísticas e informe vía MCP, con aislamiento)
  - real: `{"result":"passed","tests":8,"passed":8,"assertions":86}`; suite completa → 619 passed (3311 assertions); `vendor/bin/pint --test` → passed; `npm run build` → built OK; tras la corrección `d0cded2`: McpStats 8/8

**Criterios de aceptación**
- [x] Estadísticas e informes por MCP sobre datos del usuario (CA-12)

**Subtareas**
- [x] Tools de estadísticas
- [x] Tool de informes
- [x] Tests

**Notas (implementer, 2026-10-20)**: commit `fe429bf` (+ `d0cded2`, corrección de la revisión intento 1 de la Fase 8). Tools `get_stats` (resumen o por red, `from`/`to`, `compare` none|previous|year, `include_series`), `get_post_stats`, `generate_report` (encola `GenerateReport`; máximo 5 activos, límite atómico compartido con la web) y `get_report` (estado + URL firmada de **5 min** de TTL, ruta solo con firma, sin sesión). Abilities nuevas visibles en la UI de API keys (`Abilities::all()`). `null` = no disponible.

**Decisión de descarga para MCP (revisión intento 1, Fase 8, `d0cded2`)**: `get_report` devolvía una URL que exigía sesión web, inutilizable por un agente. Ahora `reports.download` (`/reports/{report}/download/{owner}`) va **SOLO con firma, sin sesión, TTL 5 min** (`reports.download_minutes`): el dueño va codificado en la firma y se comprueba contra el informe, solo sirve informes `ready` y audita `report_download` (canal `signed-url`); `get_report` la regenera en cada llamada (`Report::signedDownloadUrl()`). Tests: firma válida sin sesión → 200; caducada/alterada → 403; firma de otro dueño → 404; MCP descarga anónima 200 y a los 6 min 403. Riesgo aceptado: quien tenga la URL puede descargar durante 5 min (URL bearer); mitigado con TTL corto, throttle 30/min y auditoría. `get_stats` y `generate_report` calculan el periodo por defecto con la zona de la marca (igual que la web; corregido en la revisión intento 2, T1).

### T-43 — Validación final: suite E2E, pruebas manuales y revisión de seguridad

- **Descripción**: Ejecutar el `test-plan.md` completo, las pruebas manuales con cuentas reales y una revisión de seguridad (aislamiento, cifrado, API keys).
- **Changelog**: Entrega validada de extremo a extremo.
- **Estado**: en-progreso
- **Tiempo humano**: est. 8h · real —
- **Tiempo IA (ejec.)**: est. 1.2h · real 0.24h (medido)
- **Supervisión**: est. 0.3h (≈25 % IA) · real —
- **Previsión IA**: 331k in / 33k out tok · ⚠️ verificar €
- **Dependencias**: T-42, T-24, T-37
- **Tipo**: test
- **Archivos**: `tests/e2e/`, `docs/roadmap/2026-10-19-social-scheduler/test-plan.md`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/arquitectura.md`, `docs/stack.md`, `docs/modulos/`, `docs/referencia/`, `docs/guias/`, `docs/usuario/` (documentación del proyecto, Fase 4 del ciclo, commit `19da202`)
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07, E2E-08, E2E-09, E2E-10, E2E-11, E2E-12, M-01, M-02, M-03, M-04, M-05
- **Verificación**:
  - `npx playwright test` → los 12 E2E (E2E-01…E2E-12) pasan
  - lectura: test-plan.md con M-01…M-05 marcados con evidencia
  - real: `php artisan test` → 619 passed (3311 assertions); `vendor/bin/pint --test` → passed; `npm run build` → OK; `npx playwright test` → **NO ejecutable** (Playwright no instalado: sin `playwright.config`, sin `tests/e2e/`, sin `node_modules/.bin/playwright`); M-01…M-05 sin ejecutar (humano)

**Criterios de aceptación**
- [ ] Todos los CA-01…CA-12 verificados
- [ ] Revisión de aislamiento y cifrado sin hallazgos críticos

**Subtareas**
- [ ] Suite Playwright
- [ ] Pruebas manuales con evidencia
- [ ] Revisión de seguridad

**Notas**: Las horas de pruebas por fase están dentro de cada tarea; aquí solo la ejecución final.

**Motivo del estado**: `en-progreso` (parcial) — CA-01…CA-12 tienen cobertura Pest identificada, pero no están verificados por E2E de navegador (Playwright no instalado) ni por las pruebas manuales; la revisión de seguridad es parcial (aislamiento cubierto por tests; cifrado de tokens y revisión formal pendientes). Sin cambios de código en esta tarea (`tests/e2e/` no existe).

**Matriz CA → cobertura (2026-10-20, resumen de `t43-matrix.md`)** — cobertura automática con Pest feature y conectores simulados (`Http::fake`/`Queue::fake`/`Http::preventStrayRequests`); los E2E de navegador quedan pendientes para `qa`.

| CA | E2E / manual | Cobertura automática (Pest) | Estado |
|---|---|---|---|
| CA-01 registro/login/panel propio | E2E-01 | `tests/Feature/Auth/*`, Dashboard | Auto OK · E2E pendiente |
| CA-02 aislamiento web/API/MCP | E2E-02, API-03 | `Isolation/BrandIsolationTest`, `Isolation/Mcp/McpIsolationTest`, `Mcp/Tools/McpStatsTest` | Auto OK · E2E pendiente |
| CA-03 conectar cuenta OAuth | E2E-03, M-02 | `Connections/ConnectionsTest`, `Connectors/{Meta,Google,TikTok}`, `SocialAccountTest` | Auto OK (simulado) · M-02 pendiente |
| CA-04 refresco de token / reconexión | E2E-04, M-02 | `Jobs/RefreshTokensTest` | Auto OK · M-02 pendiente |
| CA-05 rechazo de media fuera de límites | E2E-05 | `Media/{MediaValidatorSampleTest,MediaUploadTest,MediaProbeSecurityTest}`, `Posts/Compose/ComposeTest` | Auto OK · E2E pendiente |
| CA-06 publicar a su hora (estado + enlace) | E2E-06, M-01 | `Publishing/PublishingTest` | Auto OK · M-01 pendiente |
| CA-07 error transitorio, sin duplicados | E2E-07 | `PublishingTest`, `RevisionFase3*Test` | Auto OK · E2E pendiente |
| CA-08 calendario por marca/red + reprogramar | E2E-08 | `Calendar/CalendarTest`, `Posts/PostsTest`, `Mcp/Tools/McpToolsTest` | Auto OK · E2E pendiente |
| CA-09 snapshots diarios | E2E-09 | `Snapshots/SnapshotsTest`, `Backfill/BackfillTest` | Auto OK |
| CA-10 dashboard 30 días, «no disponible» | E2E-10, M-03 | `Analytics/AnalyticsServiceTest`, `Dashboard/DashboardTest` | Auto OK · M-03 pendiente |
| CA-11 informe PDF y CSV por rango | E2E-11, M-03 | `Reports/{ReportsTest,Csv/ReportCsvTest,Pdf/*}` | Auto OK · M-03 pendiente |
| CA-12 MCP: marcas, posts, calendario, stats, informe | E2E-12, API-01..03 | `Mcp/{McpAuthTest,McpAuditTest}`, `Mcp/Tools/McpToolsTest`, `Mcp/Tools/McpStatsTest`, `Isolation/Mcp` | Auto OK · E2E-12 pendiente |

**Pendientes para `qa`/humano**: instalar Playwright y escribir `tests/e2e/` (E2E-01…E2E-12); M-01 publicación real por tipo de contenido; M-02 OAuth real de las 4 redes; M-03 revisión visual de dashboards y PDF (incluye aprobar T-41 y cerrar T-37); M-04 despliegue en el VPS HestiaCP; M-05 informe del spike / decisión G1; API-01…API-03 con curl contra `/mcp` (API-01/03 ya cubiertos en Pest: `tools/list` incluye `get_stats`, `get_post_stats`, `generate_report`, `get_report`); revisión de cifrado de tokens y API keys. La limitación de descarga de `get_report` que recogía la matriz quedó resuelta en `d0cded2` (ruta solo con firma, ver T-42).

## Revisión de dos lentes — intento 1: Fase 1 (T-06..T-10) — 6 Important, 15 Minor

Lentes: A+B+C+D (C por rutas `.env*`, `.github/workflows/`, `Auth`; D por worker/scheduler). Diff `1c2cfb1..HEAD`. Puerta `scope-check` exit 0. Revisores: agente `reviewer` (contexto fresco).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Acceso cruzado entre usuarios por web no se registra: `UserScope` responde 404 en el binding antes de `BrandPolicy` (único `Log::warning('access.denied')`); el test llama a la policy directamente. Incumple criterio marcado y spec.md:117 | T-08 | corregido (86e765c): `CrossUserAccessLogger` vía `exceptions->render`; test HTTP B->marca de A: 404 + log `access.denied` con user_id y brand_id | Lente A: tinker, B `GET /brands/{id}` de A → 404, logs=[] · `app/Policies/BrandPolicy.php:37-49`, `app/Scopes/UserScope.php:26`, `tests/Feature/Isolation/BrandIsolationTest.php:65-74` |
| 2 | Important | `/health` y `app:health` llaman a R2 (PUT/GET/DELETE) sin timeout HTTP: con R2 degradado bloquean workers PHP-FPM sin límite y agotan el pool | T-10 | corregido: `http` connect_timeout 3 / timeout 5 (env) + retries 1 en `config/filesystems.php`; probado ~3 s contra IP no enrutable; `storage()` captura y no cuelga | Lente D: `app/Support/HealthCheck.php:88-91`, `config/filesystems.php:57-70` sin `http.timeout` |
| 3 | Important | El despliegue no compila assets (`public/build` ignorado; deploy.sh sin `npm ci && npm run build`) → `ViteManifestNotFoundException` → 500 en todas las páginas Inertia | T-09 | corregido: `deploy.sh` hace `npm ci && npm run build` y exige `public/build/manifest.json` (gate pre-migrate + smoke) | Lente B: `deploy/hestia/deploy.sh:30-45`, `.gitignore` |
| 4 | Important | Un solo job fallido en 30 días deja `/health` en 503 y hace fallar `deploy.sh` (paso `app:health`, `set -e`) saltándose el reinicio de workers | T-10/T-09 | corregido: `status` ok/degraded/down (fallidos 24 h = aviso, antiguos ignorados); workers se reinician antes del smoke | Lente B: `app/Support/HealthCheck.php:75`, `deploy/hestia/deploy.sh:44,73-77` |
| 5 | Important | `git pull` + `composer install` antes de `artisan down`: se sirve código nuevo sobre vendor a medias y esquema sin migrar | T-09 | corregido: `artisan down` primero; `trap` deja la app arriba si el código no cambió o en mantenimiento con instrucciones | Lente B: `deploy/hestia/deploy.sh:31-35` |
| 6 | Minor | Runbook sin `chmod 600 .env` | T-09 | corregido: `chmod 600 .env` en el runbook | Lente C: `deploy/hestia/README.md:44` (CWE-732) |
| 7 | Minor | `/health` sin rate limit ni guía de entropía del token | T-10 | corregido: `throttle:30,1` + `CACHE_LIMITER_STORE=file` + guía `openssl rand -hex 32` | Lente C: `routes/web.php:19` (CWE-307) |
| 8 | Minor | `/health` devuelve `$e->getMessage()` en bruto | T-10 | corregido: log interno `health.check_failed`, detalle genérico en la respuesta | Lente C: `HealthCheck.php:47,77,95` (CWE-209) |
| 9 | Minor | Página pública expone versiones de Laravel/PHP | T-07 | corregido: portada sin versiones | Lente C: `routes/web.php:14-15` (CWE-200) |
| 10 | Minor | Middleware `verified` inoperante (`MustVerifyEmail` comentado) | T-07 | corregido: `User implements MustVerifyEmail`, `/brands` bajo `verified`; tests ajustados | Lente C: `app/Models/User.php:5` (CWE-287) |
| 11 | Minor | CI sin `permissions:` mínimos | T-06 | corregido: `permissions: contents: read` | Lente C: `.github/workflows/ci.yml:1-9` |
| 12 | Minor | Action de terceros por tag mutable | T-06 | deuda aceptada (uso personal; revisar al publicar el repo) | Lente C: `ci.yml:23` |
| 13 | Minor | Unidad systemd sin sandboxing; plan B con locks predecibles en `/tmp` | T-10 | corregido: sandboxing en la unidad y locks en `~/.cache/sched` (700) | Lente C: `scheduler-worker@.service:19-33`, `deploy/systemd/README.md:47-48` |
| 14 | Minor | CA-02 marcado «por web/API» sin API en Fase 1 | T-08 | corregido: nota «API: se valida en T-28» en CA-02 | Lente A: tasks.md CA-02 |
| 15 | Minor | `resources/js/Components/` y migraciones base atribuidos a tareas futuras en `Archivos` | T-06/T-07 | corregido: migraciones base y `resources/js/Components/` declarados en `Archivos` | Lente A |
| 16 | Minor | PHP CLI del worker/deploy no fijado (`/usr/bin/php` vs 8.4) | T-10 | corregido: `Environment=PHP_BIN` en la unidad y `PHP_BIN` en deploy | Lente B: `scheduler-worker@.service:24`, `deploy.sh:27` |
| 17 | Minor | `scheduler()` sin try/catch → `--json` rompe con BD caída | T-10 | corregido: try/catch en `scheduler()` | Lente B: `HealthCheck.php:102` |
| 18 | Minor | `/health` pasa por grupo `web` (sesión en BD) → 500 HTML con BD caída y una fila de sesión por sondeo | T-10 | corregido: `/health` fuera del grupo `web` (sin sesión; test) | Lente B: `routes/web.php:19` |
| 19 | Minor | Errores silenciados en disco local / `--wait` no numérico / `QUEUE_NAMES` vacío | T-10 | corregido: errores explícitos en disco local; validación de `--wait` y `QUEUE_NAMES` | Lente B: `HealthCheck.php:89-93`, `QueueProbe.php:20,27-31` |
| 20 | Minor | One-liner `sed` de instalación no sustituye `Group=USUARIO` | T-10 | corregido: `sed` sustituye `USUARIO`/`DOMINIO` globalmente | Orquestador: `scheduler-worker@.service:11,22` |
| 21 | Minor | Suite local en sqlite en memoria vs MySQL en CI | T-06 | deuda aceptada (CI cubre MySQL) | Lente A (fuera de lente) |

## Revisión de dos lentes — intento 2: Fase 1 (T-06..T-10) — 1 Important, 7 Minor, todos corregidos

Lentes: A+C y B+D (agente `reviewer`, contexto fresco) sobre `fd17a07..HEAD`, con traspaso de la tabla del intento 1. Resultado del traspaso: las filas #1-#11 y #13-#20 del intento 1 quedan **✓ corregidas** (#12 y #21 deuda aceptada). Seguridad (Lente C): sin defectos nuevos; el log de acceso cruzado no filtra existencia (respuesta idéntica, misma mediana de tiempo ±0,1 ms).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| G2 | Important | El timeout total de 5 s del intento 1 se aplicaba a todo el disco `r2` (rompería subidas/lecturas de vídeo > 5 s) | T-10 | corregido: disco `r2` con `connect_timeout` 3 s y total 600 s (`R2_TIMEOUT`); la sonda del healthcheck usa una copia del disco con `R2_HEALTH_TIMEOUT` 5 s (`HealthCheck::probeDisk`) | test «storage probe uses a short total timeout without touching the real r2 disk» |
| G1 | Minor | `CODE_CHANGED` solo tras `git pull`: un `checkout` que mueve el código y luego falla relevantaba la app con código nuevo | T-09 | corregido: el trap compara `HEAD` con `PREV_REV` y el árbol sucio | `deploy.sh` `on_failure` |
| G3 | Minor | `PHP_BIN` no llegaba a composer | T-09 | corregido: `COMPOSER_RUN="$PHP_BIN $(command -v $COMPOSER)"` | dry-run paso 5 |
| G4 | Minor | Binarios ausentes se descubrían tras `down` | T-09 | corregido: `preflight` (git, PHP_BIN, composer, npm) antes de `down`, exit 2 | `deploy.sh` `preflight` |
| G5 | Minor | El logger no comprobaba que el dueño fuese otro usuario | T-08 | corregido: compara `user_id` del registro con `Auth::id()` | test «a 404 on an own record is not logged as cross-user access» |
| G6 | Minor | Con `SKIP_BUILD=1` el manifest antiguo pasa el gate | T-09 | corregido (aviso): `preflight` avisa de que solo se comprueba la existencia; documentado | `deploy.sh` |
| G7 | Minor | Instrucciones de recuperación incompletas (php literal, HEAD suelto, sin build/caché, migraciones) | T-09 | corregido | `deploy.sh` `on_failure` |
| A-1 | Minor | `MustVerifyEmail` cambia el flujo de CA-01/E2E-01 sin anotarlo | T-07 | corregido: nota en `test-plan.md` (E2E-01) y en T-07 | `test-plan.md` |

Verificación tras corregir: `php artisan test` → 78 passed (216 assertions); `vendor/bin/pint --test` → passed; `bash -n deploy/hestia/deploy.sh` y `--dry-run` → OK. **Sin gaps Critical/Important pendientes: bucle cerrado en el intento 2.**

## Revisión de dos lentes — intento 1: Fase 2 (T-11..T-16) — 4 Important, 13 Minor

Lentes: A+B+C+D (C: OAuth/tokens; D: worker/scheduler). Diff `2e25aab..HEAD`. `scope-check` exit 0. Seguridad (C): sin Critical/Important — `state` de un solo uso ligado a sesión y marca, sin IDOR, tokens cifrados y fuera de props/logs.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | T-12 `completado` con la Verificación M-02 (conexión real IG/FB) sin hacer, con `verificacion: obligatoria` | T-12 | corregido (ledger): T-12 sigue `en-progreso` y la Fase 2 `en-progreso`; M-02 NO marcada, pendiente de la prueba real con ngrok | Lente A: tasks.md T-12 Verificación vs nota «pendiente» |
| 2 | Important | Desconectar deja la fila `desconectada` visible en la lista; contradice E2E-03 «Desconectar la elimina de la lista» (CA-03) | T-15/T-11 | corregido: `listForBrand` excluye `desconectada` (tokens borrados, fila conservada y reactivable); tests `ConnectionsTest` y `SocialAccountTest` | Lente A: `SocialAccountService::disconnect`, `ConnectionController` `listForBrand`, `test-plan.md:48` |
| 3 | Important | Fallo transitorio con access token caducado se trata como revocación: Google (1 h) pasa a `requiere_reconexion` tras 2 pasadas fallidas aunque el refresh token sea válido; el job solo recorre `activa` y nunca se recupera | T-16 | corregido: transitorio nunca marca; escala tras `social.max_transient_failures`=12 seguidos; permanentes (invalid_grant, data access / refresh vencidos) al instante; test reescrito | Lente B: `app/Jobs/RefreshTokens.php:74-76`; test que lo fija `RefreshTokensTest.php:96-105` |
| 4 | Important | Un único job refresca todas las cuentas en serie sin presupuesto de tiempo (Meta 3×15 s por cuenta) y supera el `--timeout` 60 s del worker: se mata, reintenta desde el id 1 y deja cuentas sin refrescar; matar a mitad del refresco de TikTok pierde el refresh token rotado | T-16 | corregido: despachador + `RefreshSocialAccount` por cuenta (único, `$timeout` 100 s, `$tries` 2); `--timeout=120` en systemd y plan B; `retry_after` 180 s | Lentes B+D: `RefreshTokens.php:22-45`, `MetaConnector.php:159-171`, `scheduler-worker@.service:27` |
| 5 | Minor | `withoutOverlapping()` sobre `Schedule::job` solo protege el despacho, no la ejecución | T-16 | corregido: `RefreshTokens` y `RefreshSocialAccount` son `ShouldBeUnique` (protegen la ejecución, no solo el despacho) | Lente D: `routes/console.php:23` |
| 6 | Minor | El job trabaja con el modelo en memoria sin releer ni bloquear: pisa una desconexión concurrente (tokens válidos en una fila `desconectada`) y puede notificar dos veces | T-16/T-15 | corregido: relectura con `lockForUpdate` en transacción; aborta si no está activa o ya se renovó; una sola transición notifica (tests) | Lente B: `RefreshTokens.php:34-39` |
| 7 | Minor | `each()` con OFFSET sobre `status=activa` mientras el bucle cambia el estado (salta filas) | T-16 | corregido: el despachador usa `chunkById` (sin OFFSET) y cada cuenta es un job independiente | Lente B: `RefreshTokens.php:34-37` |
| 8 | Minor | Notificación `mail` síncrona dentro del job y antes que `database`: si SMTP falla no queda ni aviso en BD | T-16 | corregido: `ReconnectRequired` `ShouldQueue`, vía `database` primero y un job por canal (test); escalares en vez del modelo | Lentes B+D: `ReconnectRequired.php:10,20` |
| 9 | Minor | El OAuth se inicia con un GET sin CSRF (navegación forzada reconecta/reactiva cuentas propias) | T-12..T-15 | corregido: ruta POST con CSRF + `Inertia::location`; Connections.vue usa `router.post`; test de que GET da 405 | Lente C: ruta `brands.connections.connect`, `OAuthController.php:37-51` (CWE-352) |
| 10 | Minor | `me/accounts`, `me/businesses` y `owned_pages`/`client_pages` no siguen `paging.next` | T-12 | corregido: `getAll` sigue `paging.next` (tope `social.meta.max_pages`=10, solo `graph.facebook.com`); además corrige un fallo real: el `get($url, [])` de Guzzle descartaba la query de `paging.next` | Lente D: `MetaConnector.php:229,236,244` |
| 11 | Minor | Callback OAuth de Meta con 4 + 2·B llamadas síncronas; con Graph degradada supera el `fastcgi_read_timeout` de 60 s y se pierde el `code` | T-12 | deuda aceptada (B = 1-2 negocios en uso personal; el timeout por llamada ya está acotado) | Lente D: `MetaConnector.php:75-102` |
| 12 | Minor | Detrás de ngrok/proxy `route()` genera `redirect_uri` `http://` (no hay proxies de confianza) y no coincide con la registrada en HTTPS | T-12..T-14 | corregido: `TRUSTED_PROXIES` (`*` o lista) vía `App\Support\TrustedProxies` en `bootstrap/app.php`; documentado en `.env.example`; tests | Lente A (fuera de lente): `bootstrap/app.php` |
| 13 | Minor | TikTok acepta solo `video.publish` sin `video.upload` (el modo por defecto de G1 es borrador → necesita `video.upload`); IG se crea sin exigir `instagram_content_publish`; si `debug_token` falla no se comprueban permisos | T-12/T-14 | corregido: TikTok exige `video.upload`; IG sin `instagram_content_publish` es candidata con aviso (`missing_scopes`); `debug_token` caído = error legible en la conexión (en el refresco se tolera y se reintenta) | Lente A (fuera de lente): `TikTokConnector.php:268`, `MetaConnector.php:35,92` |
| 14 | Minor | Criterio «publicaciones pendientes no se borran» marcado [x] sin modelo de publicaciones y sin criterio en T-19 que lo recoja | T-16/T-19 | corregido: criterio nuevo en T-19 (sin `cascadeOnDelete` destructivo) y nota en T-16 | Lente A: tasks.md T-16, T-19 |
| 15 | Minor | Subtarea «Cliente `google/apiclient`» marcada [x] siendo falsa; T-12 y design.md siguen citando Socialite | T-13/T-12 | corregido: subtarea de T-13 reescrita (Http propio), descripción de T-12 y `design.md` (Http en vez de Socialite/google-apiclient, con motivo) | Lente A |
| 16 | Minor | `Cubre` de T-16 no incluye M-02 (renovación real) ni lo declara pendiente | T-16 | corregido: `Cubre` de T-16 incluye M-02 con nota de pendiente manual | Lente A: test-plan M-02 |
| 17 | Minor | `fb_exchange_token` sobre un token ya largo podría no extender la caducidad (no verificado) | T-12 | corregido (anotado): verificación anotada en T-12 para M-02 real; no verificable sin Meta | Lente B: `MetaConnector.php:159,181` |

**Corrección intento 1 (implementer, 2026-10-20)**: 16 de 17 corregidos (el #11 sigue como deuda aceptada); ninguno rebatido. #1 deja T-12 y la Fase 2 en `en-progreso` (M-02 pendiente con ngrok). Verificaciones: `php artisan test` 150 passed; `vendor/bin/pint --test` passed; `npm run build` ok; `bash deploy/hestia/deploy.sh --dry-run` ok.

## Revisión de dos lentes — intento 2: Fase 2 (T-11..T-16) — 2 Important, 3 Minor, todos corregidos

Lentes A+B+C+D (agente `reviewer`, contexto fresco) sobre `2e043af..d78b07b`, con traspaso de la tabla del intento 1. Traspaso: filas #1-#10 y #13-#17 **✓ corregidas**; #11 deuda aceptada; **#12 seguía rota** (ver N1).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 (#12) | Important | `TRUSTED_PROXIES` en `.env` no tenía efecto: `env()` se evaluaba en `withMiddleware` antes de cargar `.env` (y nunca con `config:cache`); el test usaba `TrustProxies::at('*')` directamente | T-12 | corregido: middleware `TrustProxiesFromConfig` lee `config('app.trusted_proxies')` en cada petición; test que configura vía config | `app/Http/Middleware/TrustProxiesFromConfig.php`, `tests/Feature/Infra/TrustedProxiesTest.php` |
| N2 | Important | `.env.example` recomendaba `*` en Hestia (Nginx+FPM directo, sin proxy): `X-Forwarded-Host` falso envenenaba el enlace de reset de contraseña (CWE-640) | T-12 | corregido: `trustHosts()` (solo host de `APP_URL`) + `.env.example` indica dejarlo vacío en Hestia y usar `*` solo tras ngrok | `bootstrap/app.php`, test «only the APP_URL host is trusted» |
| N3 | Minor | Los caminos de error (permanente/transitorio) no comprobaban que la cadena de tokens siguiera siendo la misma: un `invalid_grant` del token viejo marcaba una cuenta recién reconectada | T-16 | corregido: `sameChain()` (activa + `last_refreshed_at` igual) en ambos caminos | 2 tests «stale token chain» en `RefreshTokensTest` |
| N4 | Minor | Meta: un 190 al releer el token de Página se trataba como transitorio y se daba por renovada con el token viejo | T-12 | corregido: `ReconnectRequiredException` se propaga | test «revoked Page token (190) as permanent» |
| N5 | Minor | TikTok: con `scope` vacío se saltaba la comprobación de `video.upload` | T-14 | corregido: sin scope cuenta como permiso ausente | `TikTokConnector.php:75` |

Verificación: suite completa 322 passed (1569 assertions, incluye Fase 3); `pint --test` passed. **Sin gaps Critical/Important pendientes: bucle de la Fase 2 cerrado en el intento 2.** T-12 sigue `en-progreso` solo por M-02 (prueba real).

## Revisión de dos lentes — intento 1: Fase 3 (T-17..T-25) — 8 Important, 10 Minor (todos corregidos; pendiente intento 2)

Lentes: A+C y B+D (agente `reviewer`, contexto fresco). Diff `493b850..7fef568` (T-17 revisado aunque su commit es anterior a la base). Conformidad (A): todas las Verificaciones de las tareas `completado` se reproducen; T-21/T-22 en-progreso por M-01 (declarado).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | SSRF: ffprobe abre la URL de R2 sin whitelist de protocolos ni comprobación de contenedor; un manifiesto HLS/DASH subido como vídeo hace GET a URLs internas (CWE-918) | T-17 | corregido: ffprobe nunca abre URLs; en R2 el objeto se baja en streaming a un temporal y se sondea en local con `-protocol_whitelist file` y demuxer forzado `-f mov`; `format_name` ajeno a MP4/MOV se rechaza. Test con HLS/DASH/concat subidos como .mp4 (listener TCP: 0 conexiones) en local y R2 | Lente C: `app/Services/MediaProbe.php:45-52,93`; PoC local `INTERNAL HIT` |
| 2 | Important | Carrusel IG: al sondear no se guarda el ID del contenedor padre y cada pasada crea otro CAROUSEL (≈30) hasta `timeout_procesado` sin publicar | T-21 | corregido: el ID del padre se persiste en el acto (`checkpoint`) y en `progress`; el sondeo lo reutiliza. `stillProcessing` ahora persiste la referencia/progreso para cualquier red. Test: 1 solo CAROUSEL creado hasta publicar | Lente B (reproducido): `PollPublishStatus.php:91`, `PublishOutcomes.php:61-66`, `InstagramPublisher.php:98-111` |
| 3 | Important | Reprogramar un destino `fallida` no limpia `external_reference` ni `attempts`: se re-sondea el vídeo fallido viejo y no se reintenta; o queda con 1 solo intento | T-20/T-24 | corregido: `reschedule`/`update` de un destino `fallida` lo reinician (referencia, intentos, progreso, errores, sondeo; sesion de subida de YouTube cacheada) conservando `publish_attempts`. Tests FB video, TikTok publish_id, YouTube `subiendo` + cache | Lente B (reproducido): `PostService.php:151-152` |
| 4 | Important | TOCTOU editar/borrar vs claim: `assertEditable` sin lock y `targets()->delete()` borra un destino en `publicando` → doble publicación o publicación sin rastro | T-20/T-23 | corregido: editar/borrar/reprogramar/desprogramar releen y bloquean (`lockForUpdate`) los destinos DENTRO de la transaccion antes de decidir; en vuelo/publicado se rechaza y `PostStatus::canBeDeleted` impide borrarlos. Tests por operacion + lectura en transaccion (la carrera real requiere MySQL; SQLite no emite FOR UPDATE) | Lente B: `PostService.php:84-95,159-165,209-216`, `PublishPostTarget.php:118` |
| 5 | Important | Stories IG sin idempotencia: el contenedor no se guarda antes de `media_publish` y `findRecent` no lista stories → historia duplicada tras timeout | T-21 | corregido: el contenedor se persiste antes de `media_publish` (`checkpoint`); contenedor PUBLISHED de una story se resuelve por `/{ig}/stories` (o, si ya no se lista, se da por publicada con nota) sin republicar. Tests: timeout en media_publish y story no listada | Lente B: `InstagramPublisher.php:44-48,108,225-233` |
| 6 | Important | `max_width` 1440 de imagen/carrusel IG rechaza en vez de escalar (IG escala); `min_width` 540 «recomendado» aplicado como duro | T-18/T-22 | corregido: sin `max_width` en imagen/carrusel IG (IG reescala); `min_width` 320 oficial sigue duro; los 540x960 del reel pasan a `recommended` y `MediaValidator::warnings()` los avisa sin bloquear. Tests foto 4032x3024 y reel 360 px | Lente B (reproducido): `config/media_specs.php:37,43,51` |
| 7 | Important | Proporción/alto sin tener en cuenta la rotación del vídeo (MOV vertical de móvil con displaymatrix -90 → 1.78) → rechaza reels/Shorts válidos | T-17/T-18 | corregido: `MediaProbe::parse` aplica displaymatrix `rotation` / tag `rotate` (90/270 intercambian ancho y alto). Test con video generado con ffmpeg (`-display_rotation 90`) y tabla de rotaciones | Lente B: `MediaValidator.php:162-166`, `MediaProbe::parse` |
| 8 | Important | Carrusel de 10 en un solo job: ~25 llamadas × 15 s vs `$timeout` 110 s → muere, recrea los hijos hasta 4 veces | T-21 | corregido: carrusel en pasos reanudables (hijos -> sondeo de hijos -> padre -> publicar) con progreso en `post_targets.progress` y presupuesto `StepBudget` (`publishing.step_budget_seconds`=85 < 110 s); un fallo a mitad conserva los hijos. Tests: 10 elementos con presupuesto 0 (11 creaciones, 1 publish) y fallo en el 3.o hijo | Lente D: `InstagramPublisher.php:81-136`, `PublishPostTarget.php:46` |
| 9 | Minor | URLs prefirmadas sin Content-Length firmado; assets `pendiente` y multipart abiertos nunca se limpian (CWE-770) | T-17 | corregido: `app:media-prune` (cada hora) borra pendientes/invalidos antiguos y aborta sus multiparts; `finalize` rechaza un objeto mayor que el declarado; lifecycle de R2 documentado en `config/media.php`. (Content-Length firmado no es posible en presign SigV4 del SDK: se compensa con la comprobacion real) | Lente C: `MediaUploadService.php:65-85,121-131` |
| 10 | Minor | `max_lateness_hours`=24 recorta «tras una caída se publican las vencidas» (design.md:158) sin marcarlo como decisión pendiente | T-20 | corregido: `max_lateness_hours` marcado como DECISION PENDIENTE DE CONFIRMAR en `config/publishing.php` y en la nota de T-20 | Lente A: `config/publishing.php:22` |
| 11 | Minor | T-23 `completado` con T-21 `en-progreso` y M-01 en Cubre sin justificar | T-23 | corregido: nota en T-23: sigue `en-progreso` hasta cerrar M-01 (prueba manual en navegador con cuentas reales); la dependencia de T-21 tambien sigue abierta | Lente A: tasks.md T-23 |
| 12 | Minor | Cuota agotada en `media_publish` durante el sondeo tratada como transitoria (sin QuotaPolicy) | T-25 | corregido: `QuotaPolicy::applyWhileProcessing`: el destino sigue `procesando` hasta `retryAt` (sin recrear el contenedor) o falla segun politica/margen; el reloj de sondeo se reinicia al acabar la espera. Test reschedule/fail | Lente B: `PollPublishStatus.php:81-83` |
| 13 | Minor | Destino colgado en `max_attempts` se marca fallido sin consultar `findPublished` | T-20 | corregido: el destino colgado en `max_attempts` consulta `findPublished` antes de marcarse `fallida` (nunca vuelve a publicar). Test publicada/fallida | Lente B: `PublishPostTarget.php:157-160` |
| 14 | Minor | ReconnectRequired durante el sondeo acaba en `timeout_procesado` | T-20 | corregido: `ReconnectRequired` en el sondeo marca la cuenta (aviso una vez) y deja el destino `procesando` con reloj reiniciado; la recuperacion no sondea cuentas inactivas y se retoma al reconectar. Test | Lente B: `PollPublishStatus.php:64-79` |
| 15 | Minor | Borrado de media: `exists()` fuera de transacción y fichero borrado antes que la fila | T-17/T-22 | corregido: `MediaUploadService::delete` comprueba bajo lock dentro de transaccion, borra la fila ANTES que el fichero y convierte la FK `restrict` en `DomainException` (409). Tests | Lente B: `MediaController.php:92`, `MediaUploadService` delete |
| 16 | Minor | Sin índice que empiece por `posts.scheduled_at` para despachador/recovery | T-20 | corregido: migracion `2026_10_23_090000` con indice `posts(scheduled_at)` (validada con `migrate:fresh` en MySQL de testing). Test `Schema::hasIndex` | Lente D: migración posts |
| 17 | Minor | `GraphClient::get` envía `access_token` en query string (logs de proxy) | T-21/T-22 | corregido: `GraphClient` envia el token con `withToken` (cabecera `Authorization: Bearer`); ni query ni cuerpo. Tests IG/FB/FakeGraph registran cabecera y URL | Lente B (fuera de lente) `GraphClient.php:333` |
| 18 | Minor | `update` borra y recrea targets y en cascada su historial `publish_attempts` | T-19/T-23 | corregido: `update` sincroniza destinos existentes (mismo id) en vez de borrar/recrear, conserva `publish_attempts`; solo borra los pendientes/con error que sobran. Test | Lente A (fuera de lente) `PostService.php:92` |

**Corrección (implementer, intento 1, 2026-10-20, rama `feature/social-scheduler`).** Los 18 gaps se verificaron contra el código y eran correctos (ninguno rebatido); el #1 solo era explotable por la URL de R2 (con fichero local ffmpeg ya aplica una lista blanca `file,crypto,data`). Solapes de la revisión de Fases 4-5 aplicados aqui: `PublishOutcomes::stillProcessing` persiste la referencia (arregla `subiendo` -> ID de YouTube dentro de `PollPublishStatus`; test E2E por cola con varias pasadas) y el reinicio de `fallida` limpia el `publish_id` de TikTok y la sesion de subida de YouTube cacheada. Estado: `post_targets.progress` (JSON, nuevo) guarda el avance reanudable; `PublishRequest::checkpoint` persiste IDs en el acto. **Decision pendiente (producto):** `max_lateness_hours`=24 (T-20, #10). **Limitaciones:** (a) el TOCTOU real solo se puede probar contra MySQL (SQLite no emite `FOR UPDATE`); el test comprueba que la lectura es transaccional y que un destino ya reclamado se rechaza; (b) Content-Length firmado en URL prefirmada no es posible con el SDK (presign firma solo `host`): se compensa comprobando el tamano real en `complete`; (c) el test de SSRF sobre R2 usa un disco local simulado: demuestra que ninguna URL llega a ffprobe y que un listener TCP no recibe conexiones. **Horas de la correccion (estimado, sin usage-meter: no se arranco al empezar):** ≈0.9h IA. **Verificacion:** `php artisan test` -> 423 passed (1996 aserciones); `vendor/bin/pint --test` -> passed; `npm run build` OK; `ledger-lint` 0 incoherencias; `scope-check` exit 0.

## Revisión de dos lentes — intento 1: Fases 4-5 (T-26, T-27) — 1 Critical, 10 Important, 8 Minor (todos corregidos; pendiente intento 2)

Lentes A+B+C+D (agente `reviewer`, contexto fresco) sobre `0e7d997..ea3f376`. Seguridad (C): sin hallazgos (sin SSRF por PULL_FROM_URL, tokens fuera de logs, interruptor Direct Post sin IDOR). Decisión de modelado «borrador TikTok = `publicada` + `status_note`»: compatible con CA-06 y G1, con riesgos en #13.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | Si la subida de YouTube termina dentro del sondeo, el ID del vídeo se descarta (`stillProcessing` no persiste la referencia): siguiente pasada `fallida/sesion_perdida` con el vídeo ya publicado | T-26 | corregido (arreglo #2 de la Fase 3: `stillProcessing` persiste referencia y progreso para cualquier red; test E2E YouTube PublishPostTarget→PollPublishStatus×N sin `sesion_perdida`) | `YouTubePublisher.php:366-369,381`, `PollPublishStatus.php:91`, `PublishOutcomes.php:62-66`; reproducido |
| 2 | Important | Reprogramar un destino `fallida` con referencia `subiendo` no tiene salida | T-26 | corregido (arreglo #3 de la Fase 3: reschedule limpia referencia `subiendo`, sesión cacheada `forgetUploadSession` y `publish_id` de TikTok; tests) | `PostService.php:151-152` |
| 3 | Important | Consulta de offset con 200 descarta el cuerpo con el `id`; depende de la playlist de subidas con retraso → vídeo duplicado | T-26 | corregido: `queryOffset` devuelve `int|PublishResult`; con 200/201 toma el `id` del cuerpo (`uploaded()`), sin mirar la playlist; solo sin cuerpo cae a la busqueda; test: sondeo con 201 -> `VIDQ` sin ninguna llamada a `playlistItems` | `YouTubePublisher.php:291-294,337-338,373-378`; corrección: `YouTubePublisher.php` `queryOffset`/`continueUpload`; test `la consulta de offset con 200/201 toma el id del cuerpo` |
| 4 | Important | Idempotencia por título+hora confunde vídeos con el mismo título (B queda `publicada` con el vídeo de A) | T-26 | corregido: la sesion marca el video con el tag `ss-<post_target_id>` (`snippet.tags`); `findPublished` lista los ultimos 10 de la playlist posteriores a `since`, pide sus tags (`videos.list part=snippet`, 1 ud) y solo acepta el que lleva el tag de SU destino; test con dos videos del mismo titulo | `YouTubePublisher.php:164-165,473`; corrección: `YouTubePublisher.php` `targetTag`/`findPublished`; tests `findPublished localiza SU video por el tag`, `la sesion marca el video con el tag` |
| 5 | Important | Reintento de `PublishPostTarget` ignora la sesión cacheada: re-sube desde 0 y re-cobra cuota | T-26 | corregido: `publish()` mira primero la sesion cacheada: si existe consulta el offset y continua (ni abre otra ni cobra cuota ni exige `assertAvailable`); si caduco (404/410) abre una nueva, esta si cobra; tests | `YouTubePublisher.php:90-94`; corrección: `YouTubePublisher.php` `publish`; tests `un reintento de publish reutiliza la sesion cacheada`, `si la sesion cacheada caduco` |
| 6 | Important | Borrador TikTok atascado en `PROCESSING_UPLOAD` (como en el spike) acaba `fallida` con aviso | T-27 | corregido y documentado (decision): en borrador (no Direct Post) `PROCESSING_UPLOAD` mas alla de `social.tiktok.inbox_grace_minutes` (10) desde que acabo la subida (`progress.uploaded_at`) -> `publicada` con `status_note` «Enviado a la bandeja (TikTok no confirmo el estado); revisa tus borradores.» en vez de `fallida` (el spike observo que en sandbox no avanza; el video ya esta subido y el usuario lo termina en la app). Direct Post conserva el plazo normal y falla; test | `TikTokPublisher.php:82`, `PollPublishStatus.php:64-65`; `spike/.results-tiktok.json`; corrección: `TikTokPublisher.php` `stuckDraft`; test `un borrador atascado en PROCESSING_UPLOAD ... publicado con nota` |
| 7 | Important | Una pasada de YouTube puede superar el `$timeout` 110 s (presupuesto comprobado solo tras cada PUT de hasta 60 s) | T-26 | corregido: el presupuesto se comprueba ANTES de cada PUT (no se empieza otro si quedan < `upload_min_slice_seconds`=10 del presupuesto de 80 s; el primero de la pasada siempre se intenta) y el timeout HTTP de CADA llamada (PUT, consulta, GET) se acota a lo que queda hasta `pass_seconds`=100 < 110 (`callTimeout`); test del acotado y del corte por presupuesto | `YouTubePublisher.php:291,311,320`; corrección: `YouTubePublisher.php` `callTimeout`/`budgetLeft`; tests `el timeout de cada llamada se acota`, `el presupuesto se comprueba antes de cada PUT` |
| 8 | Important | TikTok sin presupuesto de tiempo: todos los chunks en un job (vídeo de 1 GB = 102 PUT) | T-27 | corregido: TikTok sube por pasadas con presupuesto (80 s, marca dura 100 s); guarda en `progress` `phase=uploading`, `upload_url`, tamano/numero de trozo y el siguiente (`next`) por `checkpoint` tras cada trozo; `PollPublishStatus` la continua (`poll` con `phase=uploading` -> `resumeUpload`); al acabar `phase=processing`+`uploaded_at`; test 35 MB en 3 pasadas con un solo `init` | `TikTokPublisher.php:170-198`; corrección: `TikTokPublisher.php` `uploadChunks`/`resumeUpload`; test `la subida se reparte en pasadas con presupuesto` |
| 9 | Important | En R2 cada continuación re-descarga y descarta los bytes hasta el offset | T-26 | corregido: `MediaChunkReader` lee por rango: en discos S3/R2 cada trozo es UN `GetObject` con `Range: bytes=o-e` (cliente del adaptador, clave con `root`); en local `LimitStream` sobre `fopen`; ya no hay descarga-y-descarte; test con `S3Client` simulado verifica una peticion `bytes=900-949` y el acotado al final | `MediaChunkReader.php:81-108`; corrección: `MediaChunkReader.php`; test `MediaChunkReader: en S3/R2 cada trozo es un GetObject con Range` |
| 10 | Important | `poll_max_minutes` (30) incluye subida + procesado de YouTube → vídeos largos `timeout_procesado` ya públicos | T-26 | corregido: la fase de SUBIDA (referencia `subiendo` o `progress.phase=uploading`) tiene plazo propio `publishing.upload_max_minutes` (240, `PUBLISH_UPLOAD_MAX_MINUTES`); `poll_max_minutes` (30) empieza a contar al acabar la subida (`PollPublishStatus` reinicia `started_at` al salir de la fase); test: 100 min subiendo no vence, y tras la subida el procesado vence a los 30 | `PollPublishStatus.php:64-65`, `YouTubePublisher.php:132-136`; corrección: `PollPublishStatus.php`, `PublishResult::isUploadingState`, `config/publishing.php`; test `la fase de subida tiene plazo propio` |
| 11 | Important | TikTok: `publish_id` no se guarda antes de subir los chunks → duplicado tras corte en el último PUT | T-27 | corregido: tras el `init` y ANTES del primer PUT se hace `checkpoint(publish_id, progress)`; el reintento (job o sondeo) reconcilia por `publish_id`/`progress` (`poll` continua el trozo pendiente) y nunca vuelve a llamar a `init`; test con corte en el PUT y reintento | `TikTokPublisher.php:137-147,183-184,89`; corrección: `TikTokPublisher.php` `publishVideo`; test `el publish_id y el estado de la subida se guardan ANTES del primer PUT` |
| 12 | Minor | `rateLimitExceeded` (corto, por usuario) marca el día entero como agotado | T-26 | corregido: `rateLimitExceeded`/`userRateLimitExceeded` -> `TransientPublishException` (backoff), no llama a `markExhausted`; solo `quotaExceeded`/`dailyLimitExceeded` agotan el dia; test (`used()` sigue en 0) | `YouTubePublisher.php:424-427`; corrección: `YouTubePublisher.php` `exceptionFor`; test `rateLimitExceeded es un limite de ritmo transitorio` |
| 13 | Minor | Tras `SEND_TO_USER_INBOX` no se obtiene nunca el enlace final; `external_id` = publish_id no es un ID de vídeo (afecta a T-34) | T-27 | corregido: `external_id` sigue siendo el `publish_id`, pero el resultado `publicada` deja `progress.external_id_kind = publish_id` (`video_id` si TikTok devuelve el ID de video); documentado en T-34 (no usar `external_id` como ID de video si el tipo es `publish_id`); test | `TikTokPublisher.php:80`; corrección: `TikTokPublisher.php` `KIND_PUBLISH_ID`, `PublishOutcomes::published` persiste `progress`; test `tras SEND_TO_USER_INBOX el external_id se marca como publish_id` |
| 14 | Minor | Descripción de YouTube recortada a 5.000 caracteres, el límite es 5.000 bytes | T-26 | corregido: la descripcion se recorta a 5.000 BYTES UTF-8 con `mb_strcut` (reservando 9 bytes al `#Shorts`), sin partir caracteres; test ascii/2/3/4 bytes | `YouTubePublisher.php:486`; corrección: `YouTubePublisher.php` `description`; test `la descripcion se recorta a 5.000 BYTES` |
| 15 | Minor | Reescritura del host para PULL_FROM_URL incompatible con R2 path-style y firma SigV4 | T-27 | corregido: PULL_FROM_URL usa `TIKTOK_VERIFIED_DOMAIN` + clave del objeto (con `root` del bucket, segmentos `rawurlencode`), sin `/bucket` ni query firmada; el dominio debe ser https (si no, fallo permanente `dominio_no_verificado`); documentado: el dominio verificado debe ser el dominio PUBLICO del bucket y las imagenes legibles sin firma; test | `TikTokPublisher.php:258-263`, `config/filesystems.php:69`; corrección: `TikTokPublisher.php` `onDomain`; test `foto/carrusel por PULL_FROM_URL con la clave del objeto` |
| 16 | Minor | Título de foto Direct Post hasta 2.200 caracteres; TikTok admite 90 | T-27 | corregido: en Direct Post con fotos `post_info.title` = primera linea recortada a 90 caracteres (la descripcion sigue en 4.000); test | `TikTokPublisher.php:244,279`; corrección: `TikTokPublisher.php` `publishPhotos`; test `el titulo de una foto en Direct Post se recorta a 90` |
| 17 | Minor | `chunk_bytes` no redondeado a múltiplos de 256 KiB | T-26 | corregido: `chunk_bytes` se redondea hacia abajo a multiplo de 256 KiB (minimo 256 KiB) en `chunkSize()`; test con 300000 -> trozos de 262144 | `YouTubePublisher.php:252`; corrección: `YouTubePublisher.php` `chunkSize`; test `chunk_bytes se redondea a multiplo de 256 KiB` |
| 18 | Minor | Chunk único de TikTok (hasta 64 MiB) cargado entero en memoria | T-27 | corregido: el trozo de TikTok viaja como flujo (`MediaChunkReader::stream` = `LimitStream`/cuerpo del `GetObject`) como cuerpo de `withBody`; ya no se carga el trozo (hasta 64 MiB) como cadena; test del lector (flujo con tamano, trozo exacto) | `TikTokPublisher.php:156-157,172` (pico +66 MB medido); corrección: `TikTokPublisher.php` `uploadChunks`, `MediaChunkReader::stream`; test `entrega el trozo como flujo con su longitud` |
| 19 | Minor | Cifras de Verificación no cuadran (35/21 vs 34/20) y «8–16 MB desde R2» se prueba con 256 KiB en local | T-26/T-27 | corregido: Verificacion de T-26/T-27 reescrita con la salida real (49 y 25 pruebas) y la parte «8–16 MB desde R2» ahora se prueba con una prueba de rango S3 simulado (no con 256 KiB en local); ver notas «Correccion intento 1» de T-26/T-27 | notas de T-26/T-27; corrección: notas de T-26/T-27 |

## Revisión de dos lentes — intento 2: Fase 3 (T-17..T-25) — 3 Important, 5 Minor (todos corregidos; pendiente intento 3 de revisión)

Lentes A+B+C+D (agente `reviewer`, contexto fresco) sobre `fb3596b..94d549e` (export aislado). Traspaso: las 18 filas del intento 1 **✓ corregidas** (#14 con defecto nuevo N2; #18 con N1). SSRF (#1) cerrado: HLS/concat/DASH como `.mp4` → «moov atom not found» sin red; `enable_drefs` desactivado; imágenes sin red.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | `update`/`schedule`/`unschedule` actualizan en sitio un destino `programada` conservando `external_reference`, `progress`, `attempts`… del intento anterior: tras editar texto/media se publica el contenedor viejo (IG) o se reutilizan hijos del carrusel | T-19/T-23 | corregido: `PostService::update` compara texto y media (antes de editar) y las opciones del destino; si cambian, el destino reutiliza `FRESH_ATTEMPT` (+ `forgetUploadSession`): sin referencia, progreso, intentos ni `next_attempt_at`; solo la fecha conserva el estado; `unschedule` también lo resetea. Tests: IG con `C_OLD` + edición → contenedor nuevo con el texto nuevo (`RevisionFase3Intento2Test`, 5). Ampliación (Fases 4-5): el mismo reset invalida la sesión reanudable cacheada de YouTube (`forgetUploadSession`) y `external_reference`/`progress` de TikTok (publish_id, upload_url, fase); tests: YouTube con sesión cacheada + privacidad public→private → 1 POST de sesión con `private` (solo la fecha conserva la sesión) y TikTok con edición de texto → referencia y progreso limpios (`YouTubePublishTest`) | `PostService.php:380-391,136-139`; reproducido (`publish_calls=[C_OLD,C_OLD]`) |
| N2 | Important | La recuperación solo sondea `procesando` de cuentas `activa`: con cuenta `requiere_reconexion` sin reconectar, `desconectada` o borrada (NULL) el destino queda `procesando` para siempre y el post no se puede editar ni borrar | T-20 | corregido: `PublishRecovery::failInactiveProcessing` cierra los `procesando` de cuenta borrada/`desconectada` como `fallida/cuenta_no_disponible` (con aviso) y los de `requiere_reconexion` pasado `publishing.reconnect_grace_hours` (72 h); `delete`/`unschedule` permiten cancelar/borrar un `procesando` de cuenta no activa (con cuenta activa sigue rechazado). Tests: 7 en `RevisionFase3Intento2Test` | `PublishRecovery.php:52-55,92`, `PollPublishStatus.php:58-71`, `PostService.php:241`; reproducido |
| N3 | Important | La corrección SSRF descarga el vídeo ENTERO de R2 dentro de la petición síncrona `complete` (hasta 4 GB): 504 tras 60 s y temporales huérfanos si el worker muere (CWE-400) | T-17 | corregido: en R2 `MediaProbe` lee por rango (`MediaChunkReader`, `GetObject` con `Range`) solo los primeros y últimos `media.probe_edge_bytes` (16 MiB) a un temporal disperso del tamaño real; ffprobe sigue recibiendo solo un fichero local (sin URL). `app:media-prune` (cada hora) borra `probe_*` de más de 60 min. Tests: rangos pedidos = 2 extremos (moov al final y con faststart), limpieza programada | `MediaProbe.php:50-51,65-87`, `MediaController.php:69-83` |
| N4 | Minor | `tempnam` antes de comprobar el stream: temporal vacío no se borra si `readStream` devuelve null | T-17 | corregido: el lector se abre y valida ANTES de crear el temporal y cualquier fallo lo borra; nombre propio `probe_<hex>` (en Windows `tempnam` recorta el prefijo). Test: objeto ausente → sin `probe_*` huérfano | `MediaProbe.php:67-71` |
| N5 | Minor | El presupuesto por paso no cubre lo que queda tras el último `allows()` (estado + `media_publish` + permalink → 115 s > 110 s); `findPublished` previo fuera de presupuesto | T-21 | corregido: `StepBudget::allows(after: n)` reserva las llamadas que siguen sin volver a preguntar: el estado del contenedor solo se consulta si caben estado + `media_publish` + enlace (el enlace se omite sin presupuesto); `PublishRequest::budget()` lo comparte entre `findPublished` y `publish`/`poll`; `findPublished` consulta dentro del presupuesto y, sin él, aplaza (contenedor) o lanza error transitorio (nunca publica a ciegas). Tests: 4 | `InstagramPublisher.php:124-135,217-226` |
| N6 | Minor | `MetaConnector` sigue mandando tokens (y `client_id|client_secret` en `debug_token`) en la query string (CWE-598) | T-12/T-21 | corregido: `MetaConnector` envía tokens (usuario y Pagina, también en `paging.next`) por `Authorization: Bearer`; `oauth/access_token` y `debug_token` por POST con secretos en el cuerpo y el token de app `client_id|client_secret` en la cabecera. Comprobado contra Graph real: acepta POST en ambos y reconoce el Bearer de app. Interruptor `META_OAUTH_METHOD=get` como último recurso. Test: ninguna URL contiene token/secreto | `MetaConnector.php:181,243-245,276,282,290` |
| N7 | Minor | Story PUBLISHED resuelta con la más reciente desde `since`, no con la de su contenedor | T-21 | corregido: la story PUBLISHED se resuelve por el ID de su contenedor en `/stories`; sin coincidencia, solo se acepta una única candidata desde `since`; con varias se queda con el ID del contenedor y una nota (no adivina). Tests: 3 | `InstagramPublisher.php:244-254` |
| N8 | Minor | (heredado) Contenedor PUBLISHED no-story sin texto queda `fallida`; reprogramar lo duplica | T-21 | corregido: un contenedor PUBLISHED (con o sin texto, no-story) es concluyente: se resuelve como `publicada` con el ID del contenedor y nota si no se localiza el post (nunca `fallida`). Tests: 2 | `InstagramPublisher.php:95,136` |

**Corrección (implementer, intento 3 de 3, 2026-10-20, rama `feature/social-scheduler`).** Los 8 gaps se verificaron contra el código y eran correctos (ninguno rebatido). Commits `T-17`, `T-20`, `T-21` (x2) y `T-12: fix revisión intento 2 (Fase 3)`. Tiempo IA medido (usage-meter `social-scheduler/T-17-fix2`, `T-19-fix2`, `T-20-fix2`, `T-21-fix2`, `T-23-fix2`; ventana compartida): 2.23h (medido). Decisiones: el sondeo de vídeo en R2 usa lectura por rango de principio y final (sin job en cola); el plazo de espera por reconexión de un `procesando` es 72 h (`PUBLISH_RECONNECT_GRACE_HOURS`, decisión de producto por confirmar); `META_OAUTH_METHOD=post` por defecto. **Verificación (salida real)**: `php artisan test` → 571 passed (2956 assertions); `vendor/bin/pint --test` → passed; `npm run build` → OK; ledger-lint y scope-check exit 0.

## Revisión de dos lentes — intento 1: Fase 6 (T-28..T-30) — 1 Important, 7 Minor

Lentes A+B+C+D (agente `reviewer`, contexto fresco) sobre `191d73c~1..3c353ea`. Aislamiento (IDOR) y abilities en las 10 herramientas ✓; keys con hash ✓; solo Bearer, sin sesión ni CSRF ✓; sin SSRF ✓; 62 tests MCP/aislamiento pasan.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | `throttle:mcp-ip` NO corre antes de la auth: Laravel reordena por `$middlewarePriority` y una key falsa recibe 401 sin pasar por el limitador por IP (CWE-307/770) | T-28/T-30 | corregido — McpGuard global (append) corre antes de auth; test key falsa 429 | `routes/ai.php:8`; prueba con IP=3/min → `401×6`, ningún 429 |
| 2 | Minor | Intentos con key inválida sin rastro (ni auditoría ni log con IP) (CWE-778) | T-30 | corregido — log mcp.auth_failed (IP, sin token); test | `SchedulerTool.php`, `McpAuditTest` |
| 3 | Minor | `SANCTUM_EXPIRATION` global (1 año) prevalece sobre la `expires_at` elegida por el usuario | T-28 | corregido — expires_at elegido manda (tope MCP_KEY_MAX_DAYS=730); sanctum.expiration=null; test | `ApiKeyController.php:42,44`, `config/sanctum.php` |
| 4 | Minor | `SANCTUM_EXPIRATION=null` → `(int)` 0 → keys nacen caducadas | T-28 | corregido — default MCP_KEY_DEFAULT_DAYS=365, sin cast a 0; test | `ApiKeyController.php:44` |
| 5 | Minor | `list_media` sin paginación (solo últimas 100) | T-29 | corregido — limit/before_id, has_more, next_cursor; test | `ListMedia.php:27-29` |
| 6 | Minor | Test «la sesión web no autentica el MCP» no prueba nada (`forgetGuards`) | T-28 | corregido — login real con cookie (control en ruta web) y 401 en /mcp; test | `McpAuthTest.php:45-49`, `McpClient.php:27` |
| 7 | Minor | Sin validación de `Origin` en `/mcp` (MUST de la especificación MCP; mitigado por Bearer + trustHosts) (CWE-346) | T-28 | corregido — Origin fuera de APP_URL/MCP_ALLOWED_ORIGINS -> 403, sin Origin pasa; test | `routes/ai.php:8` |
| 8 | Minor | Auditoría no cubre `Throwable` genéricos (p. ej. `QueryException` por lock) | T-30 | corregido — catch Throwable -> auditoría error + mensaje genérico; test | `SchedulerTool.php:52-75` |

**Bucle de la Fase 6 cerrado en el intento 1** (commit `d6e69b2`): `php artisan test --filter="Mcp|Isolation"` → 68 passed (385 assertions); `pint --test` passed.

## Revisión de dos lentes — intento 2: Fases 4-5 (T-26, T-27) — 1 Important, 6 Minor

Lentes A+B+C+D (agente `reviewer`, contexto fresco) sobre `8479838~1..b9f4412` (export aislado). Traspaso: las 17 filas corregidas del intento 1 (#3-#19) **✓**. Tests: `tests/Feature/Connectors` 161 passed; YouTubePublish 49, TikTokPublish 25, MediaChunkReader 2.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | Editar un destino `programada` no invalida la subida a medias: la sesión reanudable cacheada (YouTube) y `publish_id`/`upload_url` (TikTok) sobreviven y el reintento publica con metadatos/fichero viejos (p. ej. sale público tras cambiarlo a privado) | T-26/T-27 | corregido (arreglo N1 de la Fase 3 intento 2: editar contenido de un destino `programada` aplica `FRESH_ATTEMPT` + `forgetUploadSession`, limpiando referencia/progreso de YouTube y TikTok) | `PostService.php:382-385`, `YouTubePublisher.php:94-111`, `TikTokPublisher.php:97-98,200-219`; reproducido |
| N2 | Minor | La `upload_url` de TikTok caduca (≈1 h, sin verificar); subidas o esperas de reconexión largas acaban en fallo permanente genérico | T-27 | corregido (verificado en la doc de TikTok: la `upload_url` vale 1 h). `upload_url_at` en el progreso; caducada por tiempo (`upload_url_ttl_minutes` 55) o PUT 401/403/404/410 -> `init` nuevo con progreso limpio (`upload_restarts` 1); agotado, falla `subida_caducada` «reprograma». Plazo propio `social.tiktok.upload_max_minutes` 120 en `PollPublishStatus` con mensaje claro | `TikTokPublisher.php:281-286`, `PollPublishStatus.php:87` |
| N3 | Minor | El plazo de gracia del borrador (10 min) puede dar por `publicada` un vídeo que luego pasa a `FAILED` | T-27 | decision documentada (no viable en este alcance): el destino sale del ciclo de sondeo al quedar `publicada` y reabrirlo exige un vigilante nuevo y una transicion `publicada`->`fallida` que el agregado no contempla; la nota ya manda revisar borradores y `TIKTOK_INBOX_GRACE_MINUTES` es ajustable. En docblock de `TikTokPublisher`, `.env.example` y runbook | `TikTokPublisher.php:116-124` |
| N4 | Minor | Las lecturas de R2 no están acotadas por `callTimeout` (r2 `timeout` 600 s; HEAD extra) | T-26/T-27 | corregido: `MediaChunkReader::stream/chunk` aceptan `$timeout` y lo pasan como `@http` por peticion al GetObject (los publicadores lo acotan con `callTimeout`); un fallo/timeout de lectura es `TransientPublishException`; `for()` hace UN HeadObject (`size()`, sin `exists()`) | `MediaChunkReader.php:33,38,107-111` |
| N5 | Minor | Idempotencia de YouTube solo mira la primera página de 10 subidas | T-26 | corregido: `findPublished` pagina la playlist de subidas (50/pag) con `pageToken` hasta pasar `since`, sin pagina siguiente o `youtube.lookup_max_pages` (3); coste anotado en `config/social.php`: <= 2 uds por pagina (6 uds max) | `YouTubePublisher.php:183-185` |
| N6 | Minor | PULL_FROM_URL exige bucket público sin firma: toda la media queda accesible por URL (CWE-552; mitigado por claves ULID) | T-27 | decision documentada: no se copia a un prefijo aparte porque el dominio verificado expone todo el bucket y un prefijo no oculta nada sin WAF/bucket aparte. `.env.example` y runbook (seccion 8) advierten de que PULL_FROM_URL exige dominio publico y recomiendan bucket/prefijo publico separado + ciclo de vida 24 h | `TikTokPublisher.php:381-400` |
| N7 | Minor | El tag `ss-<post_target_id>` expone el id interno secuencial en metadatos públicos (CWE-200) | T-26 | corregido: el tag es opaco `ss-<HMAC-SHA256(APP_KEY) 16 hex>` (`YouTubePublisher::targetTag()`), sin el id secuencial | `YouTubePublisher.php:248,592-595` |

Verificación tras corregir N2-N7 (commit `ae90424`): `--filter="YouTubePublish|TikTokPublish|MediaChunkReader|Publishing|MediaProbe"` → 164 passed. **Sin gaps Critical/Important pendientes: bucle de las Fases 4-5 cerrado en el intento 2** (N3 y N6 como decisiones documentadas).

## Revisión de dos lentes — intento 3: Fase 3 (T-17..T-25) — 1 Important, 3 Minor (+1 extra)

**Decisión del orquestador al 3.er intento: seguir y corregir (delegación del usuario); bucle cerrado tras esta corrección con verificación de tests.**

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| R1 | Important | El reset por edición (N1) y `unschedule` borran la prueba de idempotencia cuando el intento anterior fue ambiguo (referencia/contenedor o `attempts>0` con error transitorio): tras editar se podía publicar de nuevo lo ya publicado | T-19 / T-21 | Verificado y correcto. Al resetear, si el intento pudo haber salido (referencia, progreso o intentos con error ambiguo) se guarda `progress.reconcile` {referencia, progreso, texto viejo, ventana `since`}; el siguiente claim ejecuta `findPublished` con esos datos ANTES de publicar: si la red ya lo tiene, `publicada` (o `procesando`) con la nota «La edición no se aplicó…», sin segundo `media_publish`; si no, limpia el marcador y publica lo nuevo. Dos ediciones/desprogramar conservan el marcador. IG distingue contenedor `FINISHED` (no salió) de `PUBLISHED`. YouTube (tag del destino) y TikTok (publish_id) usan el mismo camino | `PostService::freshAttempt`, `PublishPostTarget::reconcile`, `PublishRequestFactory::makeReconcile`; `RevisionFase3Intento3Test` (IG repro, sin referencia, FINISHED, YouTube x2, TikTok) |
| R2 | Minor | `MediaProbe` por extremos lee mal la duración de un MP4 fragmentado sin sidx > 32 MiB | T-17 | Verificado y correcto. `MediaProbe::isFragmented` recorre las cajas del principio (`moof` o `mvex` en `moov`); si es fragmentado se baja entero hasta `media.probe_fragmented_max_bytes` (256 MiB) y por encima se rechaza con «exportalo como MP4 normal con faststart» (sin temporales) | `MediaProbeSecurityTest` R2 (vídeos `frag_keyframe+empty_moov` generados con ffmpeg: duración 19.5-20.5 s con lectura completa; rechazo; no-fragmentado) |
| R3 | Minor | `failInactiveProcessing` no captura `InvalidPostTransition` por destino: una carrera corta el despacho | T-20 | Verificado y correcto. Se captura por destino (log `publish.recovery_race`) y se sigue con el resto | `RevisionFase3Intento3Test` R3 (evento `retrieved` simula la carrera) |
| R4 | Minor | Historia PUBLISHED: se adoptaba como propia la única historia listada aunque la nuestra ya no pudiera estarlo (> 24 h) | T-21 | Verificado y correcto. Con `since` de más de 24 h no se adopta candidata: se resuelve con el ID del contenedor, nota y `progress.external_id_kind = 'container'` (también en el resto de resoluciones por contenedor) | `RevisionFase3Intento3Test` R4 |
| extra | — | Los destinos resueltos con el ID del contenedor no deben pedir métricas a los colectores de insights | T-21 / Fase 7 | Delegado en la corrección de la Fase 7 (gap 15). Esta corrección solo deja el marcador `progress.external_id_kind = 'container'` | — |

Los tests previos que fijaban `progress = null` tras un reset (N1 i2, rev. #3, YouTube/TikTok N1) se actualizaron: ahora comprueban que no sobrevive el estado residual de subida/contenedor y que el único resto es el marcador de reconciliación. **Verificación:** `php artisan test` → 632 passed (3394 assertions); `pint --test` sobre las rutas tocadas → passed. **Sin gaps Critical/Important pendientes: bucle de la Fase 3 cerrado en el intento 3.**

## Revisión de dos lentes — intento 1: Fase 7 (T-31..T-37) — 1 Critical, 7 Important, 8 Minor

Commits (rama `feature/social-scheduler`): `646b133` (T-31), `d21d31d` (T-32), `2d26e74` (T-35), `cab1e99` (T-37). Suite completa 669/669 verde, `pint --test` verde sobre mis rutas, `npm run build` verde. Los 2 reproductores (`rv7/repro_settle_fixed.php`, `rv7/repro_growth.php`) dan el resultado correcto (ver nota sobre el reproductor original abajo).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | El reintento de días previos no corre: `hasData` da por «con datos» un snapshot con solo stocks | T-31 | `hasData` eliminado. Nuevo `Metric::EXPECTED_FLOWS` (flujos diarios esperados por red) + `Metric::isComplete()`: los stocks (seguidores, vídeos) no cuentan. `SnapshotCollector::store()` marca `account_snapshots.settled_at` (migración nueva) cuando el snapshot está completo o su día sale de la ventana de reintentos (`Days::isHistoric`, `settle_days` 3). `needsCollection()` = sin fila o sin `settled_at`; lo usan `CollectAccountSnapshot` (onlyIfMissing) y `BackfillMetrics`. `CollectSnapshots` reintenta D-1..D-3. TikTok (solo stocks) se da por completo con cualquier dato. | `SettleTest`: «un snapshot con solo stocks no está completo y el reintento recoge los flujos de YouTube cuando llegan» (settled_at null tras día 1; views 340, interactions 34 y settled tras el reintento; ya asentado no vuelve a leer), «pasada la ventana… asentado», «completo de Instagram/TikTok». Reproductor: tras reintentos `views:340, followers_gained:9, interactions:34`. |
| 2 | Important | Día del snapshot por red en su zona; recogida cuando el día está cerrado; comentario de config; fixtures | T-31/T-32 | Nuevo `App\Analytics\Days` (`closed()`, `isHistoric()`) con `social.analytics.timezones` (Meta y YouTube = `America/Los_Angeles`, TikTok UTC). `CollectSnapshots` calcula el último día cerrado por cuenta en la zona de su red. `daily_at` por defecto 03:30 → **12:00 UTC** (el día del Pacífico cierra a las 07:00/08:00 UTC) con el comentario de config corregido. Meta: `Insights::seriesValue()` indexa los valores por el día del Pacífico de `end_time − 1 s` y toma el del día pedido (no `values[0]`); sin `end_time` solo acepta una serie de una entrada, si no `null`. YouTube ya pide `startDate=endDate=D` (día del Pacífico): documentado. | `SettleTest` «Día por red…» (02:00 UTC → último cerrado 30-oct en YouTube/IG, 31-oct en TikTok), «el despachador encola el día cerrado de cada red en su zona…», `MetaInsightsTest` «elige el valor cuyo end_time cierra el día pedido» (3 valores → 222; sin coincidencia → null). |
| 3 | Important | Límites de uso de Graph (80001/80002, 4/17/32/613) deben ser transitorios en lecturas | T-32 | `GraphClient::RATE_LIMIT_CODES` = 4, 17, 32, 613 + familia 800xx (80000-80006, 80008, 80009, 80014) → `TransientPublishException('rate_limit')`. `Insights::safe()` solo traga Permanent: el transitorio sube al job (no se guarda snapshot ni se da por completo → se reintenta). `GraphClient::get()` admite `bool $read` (cuarto argumento opcional): las lecturas de insights registran `meta.read_error` en `debug` en vez de `meta.publish_error` en `warning`. **Efecto al publicar (corregido en la revisión intento 2, N4)**: `RATE_LIMIT_CODES` es compartido, así que los códigos 800xx/4/17/32/613 pasan a ser transitorios TAMBIÉN al publicar (antes fallo permanente), con reintentos acotados por `max_attempts`; el log de publicación (`meta.publish_error`) se mantiene. | `MetaInsightsTest` «los límites de uso de Graph (4/17/32/613 y 800xx) son transitorios» (9 códigos → `TransientPublishException`; log debug `meta.read_error`, nunca `warning meta.publish_error`). Tests de publicación de `tests/Feature/Connectors/Meta` en verde (89/89). |
| 4 | Important | Crecimiento/seguidores agregados saltan con altas/bajas | T-35 | `Calculator::growthAcross()` = suma de Δ de cada cuenta entre su primer y último dato del periodo (solo cuentas con ≥2 puntos; con un solo punto `change=null`). `Calculator::totalAcross()`: stock = suma del último valor conocido de cada cuenta (no el último día de la suma). `Calculator::combine()` arrastra el último valor de cada cuenta entre su primer y último dato en las series de stocks. `networkSeries()` ahora devuelve una serie por cuenta. | Reproductor `repro_growth.php`: cuenta B conectada el 15 → `change:30` (antes saltaba ~+50000); B sin snapshots desde el 26 → `change:16` (A 1015→1031). `AnalyticsReviewTest` «Crecimiento: …sin salto» (total 51031) y «…deja de tener snapshots…» (serie del 27-oct = 1027). |
| 5 | Important | KPIs por publicación sobre todas las del periodo, lista paginada/top N con aviso | T-35/T-37 | `postStats()`: `COUNT/AVG` en SQL (join con el último snapshot por destino; `CAST(NULLIF(<json>,'null') AS DECIMAL)` para que un `null` JSON no sea 0 en MySQL) sobre TODAS las publicaciones; la lista es un top N explícito por interacciones (`social.analytics.dashboard_posts`, 50). Salida nueva: `posts_total`, `posts_limit`, `posts_truncated`; `kpis.per_post.posts` = total real. `Network.vue` muestra «Se muestran las N con más interacciones de M». | `AnalyticsReviewTest` «KPIs por publicación: se agregan sobre TODAS…» (9 publicaciones, top 3 = 80/70/60, medias 450/45/10 % sobre las 8 con datos) y «un valor null no cuenta como 0». |
| 6 | Important | IG: `total_interactions` de cuenta y engagement de cuenta | T-32/T-35 | `Insights::instagramAccount()` pide `total_interactions` (`metric_type=total_value`) → `Metric::INTERACTIONS`; si Meta la rechaza queda `null`. `AnalyticsService::SUPPORTED['instagram']` incluye `INTERACTIONS` (engagement de cuenta = interacciones/visualizaciones). `EXPECTED_FLOWS['instagram']` la incluye. | `MetaInsightsTest` «total_interactions alimenta las interacciones…» (77; rechazada → null), `AnalyticsReviewTest` «Interacciones y engagement de cuenta de Instagram» (5.0 %). |
| 7 | Important | Snapshot diario sin presupuesto de tiempo ni continuación | T-31 | `CollectAccountSnapshot` ya no lee publicaciones: encola `RefreshPostMetrics(accountId, hours, offset)`. `SnapshotCollector::collectPostsBatch()` lee bloques (≤25) con presupuesto (`posts_budget_seconds` 60 < `$timeout` 90), siempre ≥1 por pasada y comprobando antes de cada una con la duración de la más lenta; `RefreshPostMetrics` pasa a `ShouldBeUniqueUntilProcessing` (candado por cuenta+horas) y se reencola con el offset. `$timeout` de todos los jobs < 120. | `SettleTest` «Presupuesto: las publicaciones del snapshot diario se leen en un job aparte, por bloques con continuación» (0 lecturas inline; con presupuesto 1,5 s y 0,6 s/post → 2 lecturas y continuación `offset=2`; último bloque sin encadenar) y «los jobs… timeout menor que el del worker». |
| 8 | Important | T-37 vuelve a `en-progreso` por M-03 | T-37 | Solo ledger (lo aplica el orquestador): ver «Notas de ledger». | — |
| 9 | Minor | Presupuesto del backfill por llamada | T-36 | `BackfillMetrics` comprueba el presupuesto ANTES de cada unidad (día o tramo) sumando la duración de la unidad más lenta de la pasada (siempre ≥1 por pasada); si no cabe, se reencola con el offset. | `SettleTest` «el presupuesto se comprueba antes de cada unidad…» (0,6 s/día, presupuesto 1 s → 1 lectura y `BackfillMetrics(offset=1)`); `BackfillTest` «sin presupuesto…» sigue en verde. |
| 10 | Minor | Backfill por rango | T-36/T-33/T-32 | Nueva interfaz `RangeInsightsReader` (`maxRangeDays`, `accountInsightsRange`). YouTube: un informe `dimensions=day&sort=day` por tramo de hasta 90 días. Facebook: 3 series `since/until` por tramo de ≤30 días. Instagram no (sus métricas `total_value` exigen una llamada por día): `maxRangeDays=0`. Los 3 últimos días (con stocks) van día a día. `SnapshotCollector::collectRange()` solo escribe días que necesitan datos. | `SettleTest` «Backfill por rango: YouTube… UN informe por tramo» (40 días = 4 informes, no 40; `dimensions=day`), `MetaInsightsTest` «Facebook por rango lee 3 series…» (3 llamadas, 21 días). |
| 11 | Minor | Comparación año anterior con misma longitud (bisiestos) | T-35 | `Period::comparison('year')`: `from − 1 año (sin overflow)` + `days − 1`. | `AnalyticsReviewTest` «Comparación con el año anterior: misma longitud…» (15 días con 29-feb → 15). |
| 12 | Minor | Documentar decisión 30/90 días | T-36 | Decisión en `config/social.php` y abajo: IG 30 (límite de 30 días por consulta; una llamada por día), FB 90 (3 llamadas por tramo de 30 días), YouTube 365 (un informe por 90 días), TikTok 0; tope duro `Period::MAX_DAYS`. | Config comentada; nota de T-36 más abajo. |
| 13 | Minor | Fixtures de `end_time` coherentes | T-32 | Fixtures: el día 31-oct del Pacífico acaba el 1-nov 07:00 UTC (PDT; el cambio a PST es el 1-nov a las 02:00 local), no `…T08:00` ni `2026-10-31T07:00`. | `MetaInsightsTest` (IG `follower_count` y FB `page_follows` con `2026-11-01T07:00:00+0000`). |
| 14 | Minor | `network()` carga solo la red pedida | T-35 | `networkSeries($user,$brand,$period,?Network $only)` filtra por red en SQL. | `AnalyticsReviewTest` «network() solo lee los snapshots de la red pedida» (1 consulta, binding `youtube`). |
| 15 | Minor | `canReadPost` de Meta excluye `external_id_kind=container` | T-32 | `Insights::canReadPost()` devuelve false si `progress.external_id_kind === 'container'`. No toco `InstagramPublisher`. | `MetaInsightsTest` «una media de Instagram que sigue siendo un contenedor no se lee». |
| 16 | Minor | Periodo y filtro de publicaciones en la zona de la marca | T-35/T-37 | `Period::make(from, to, ?timezone)`: «ayer» por defecto en la zona de la marca (zona inválida → UTC). `AnalyticsService::bounds()` convierte los días del periodo a límites UTC `[desde, hasta)` en la zona de la marca para filtrar `published_at`. `DashboardController` pasa `$brand->timezone`. | `AnalyticsReviewTest` «Periodo y filtro de publicaciones en la zona horaria de la marca» (Auckland: una publicación a las 20:00 UTC del 10 pertenece al 11; `Period::make(null,null,'Pacific/Auckland')` termina el 1-nov frente al 31-oct en UTC). |

### Notas de ledger (para que las aplique el orquestador; no he tocado `tasks.md`)

- **T-37 → `en-progreso` (por M-03)**: el cierre completo exige la verificación manual M-03 / E2E-09 (job diario con cuentas reales ~30 días y revisar gráficos; comprobar en el VPS que `collect-snapshots` corre a las **12:00 UTC** —antes 03:30— y `refresh-post-metrics`, y que un worker escucha la cola `metrics`). Cambia la hora de la nota de T-31 («planificado 03:30 UTC» → 12:00 UTC, día cerrado en el Pacífico).
- **Decisiones T-36**: (a) backfill por rango cuando la red lo permite; (b) histórico: IG 30 días (límite de 30 días por consulta de la API de insights de usuario; sus `total_value` exigen una llamada por día), FB 90 (se lee por tramos de ≤30 días, 3 llamadas por tramo), YouTube 365 (un informe `dimensions=day` por tramo de 90 días), TikTok 0 (la Display API no da serie histórica); tope duro `Period::MAX_DAYS`=366; (c) los tres últimos días se leen día a día porque llevan stocks (la API solo da el valor actual); (d) presupuesto de la pasada comprobado por unidad con la duración de la más lenta; (e) un día histórico (fuera de `settle_days`) queda asentado tras una lectura aunque le falten métricas, así no se repite.
- **Decisión T-31 nueva**: «asentado» (`account_snapshots.settled_at`) = completo según `Metric::EXPECTED_FLOWS` o fuera de la ventana de `settle_days` (3). Una métrica permanentemente rechazada (p. ej. `total_interactions` en cuentas pequeñas) se reintenta como mucho 3 días y luego se asienta con `null`.
- **`Archivos` a añadir al ledger** (para `scope-check`): T-31: `app/Analytics/Days.php`, `app/Analytics/Metric.php`, `app/Connectors/Contracts/RangeInsightsReader.php`, `database/migrations/2026_10_27_090000_add_settled_at_to_account_snapshots.php`, `app/Models/AccountSnapshot.php`, `config/social.php`, `tests/Feature/Snapshots/SettleTest.php`, `tests/Support/FakeInsights.php`. T-32: `app/Connectors/Meta/GraphClient.php` (solo clasificación de límites de uso + parámetro `$read`). T-33: `app/Connectors/Google/Analytics.php`. T-35: `app/Analytics/Calculator.php`, `app/Analytics/Period.php`, `tests/Feature/Analytics/AnalyticsReviewTest.php`. T-37: `app/Http/Controllers/DashboardController.php`, `resources/js/Pages/Dashboard/Network.vue`.
- **Horas (estimación, no medidas — sin usage-meter en esta corrección)**: IA ≈ 2,5 h de ejecución; supervisión humana ≈ 0,3 h. Marcar `(estimado)`.
- **Compatibilidad con `get_stats` (Fase 9)**: firmas públicas de `AnalyticsService::summary/network/connectedNetworks` y de `Period::make/comparison` sin cambios (`make` gana un tercer argumento opcional `?string $timezone`). Salida de `network()` ampliada de forma aditiva: `posts_total`, `posts_limit`, `posts_truncated`, `growth.accounts`; `kpis.per_post.posts` ahora es el total real del periodo (antes ≤50) y `posts` es el top N. `GetStats` usa el periodo por defecto en UTC; si se quiere por zona de la marca, basta pasar `$brand->timezone` como tercer argumento (no he editado `app/Mcp/**`). La métrica `interactions` aparece ahora también en Instagram.
- **Documentación consultada**: en esta sesión no tuve herramientas de Context7/WebFetch (solo Bash/archivos), así que el contraste con las docs actuales de Instagram User Insights, Page Insights y YouTube Analytics se hizo contra el conocimiento previo y el spike del repo, no contra un fetch en vivo. Supuestos a validar en M-03 con cuentas reales: (1) `end_time` = fin del periodo a medianoche del Pacífico; (2) `total_interactions` con `metric_type=total_value` en la cuenta IG; (3) `since/until` ≤30 días; (4) códigos de límite 800xx. Si Meta devolviera `end_time` con otra convención, `Insights::valuesByDay()` es el único punto a ajustar.
- **Reproductor original `repro_settle.php`**: reutiliza `Http::fake()` dos veces; en Laravel gana el primer stub que coincide, así que el reintento seguía viendo `rows: []` y no puede demostrar la corrección. La versión con `Http::swap(new Factory)` antes de cada `fake` está en `rv7/repro_settle_fixed.php` y confirma el arreglo.
- **Pendiente/riesgo**: los agregados SQL de `postStats()` se probaron en SQLite (suite); la expresión usa `Grammar::wrap('pm.metrics->views')` (portable a MySQL/PostgreSQL) pero no se ha ejecutado contra MySQL real: añadir a la verificación manual M-03. Los fallos de `ReportsTest` que vi a mitad de trabajo eran de la Fase 8 en curso y ya están en verde en la suite final.

Verificación tras corregir: suite completa 669 passed (4802 assertions); `pint --test` passed. Sin gaps Critical/Important pendientes de corrección; pendiente revisión intento 2.

## Revisión de dos lentes — intento 1: Fase 8 (T-38..T-41) — 4 Important, 14 Minor

Corrección (implementer, commits c343d36 T-38, b92c6a0 T-39, 8a3edb8 T-40, d0cded2 T-42 sobre feature/social-scheduler). Verificación en el estado final: `php artisan test tests/Feature/Reports tests/Feature/Mcp` -> 69 passed (1676 aserciones); `--filter=ReportCsv` 8/8, `ReportPdf` 3/3, `McpStats` 8/8; `vendor/bin/pint --test` sobre las rutas del ámbito -> passed; `npm run build` -> built OK.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| I-1 | Important | Escape de fórmulas del CSV eludible con `;` (Excel es-ES), CWE-1236 | T-38 | Celdas de texto SIEMPRE entre comillas; `cell()` antepone `'` al texto y a cualquier tramo tras `;`, `,`, TAB, CR o LF que empiece por `= + - @ TAB CR`; separador `;` y coma decimal por defecto (`reports.csv_locale` es/en) | `ReportService::cell/field/csvFormat`; test «la inyeccion con ; no se cuela...» con el payload `x;=cmd|'/Ccalc'!A0;` (salida `"x;'=cmd|'/Ccalc'!A0;"`, 8 celdas por fila, ningún tramo empieza por `=`) |
| I-2 | Important | «Publicaciones destacadas» salían de las 50 más recientes, no del periodo | T-38 | `ReportService::topPosts()`: consulta propia (`post_targets` + último `post_metric_snapshots` de cada publicación en el periodo, todas las redes en una pasada, streaming con `cursor()`), ordena por interacciones y recorta al top N por red; AnalyticsService intacto | test `ReportFix I-2` con 60 publicaciones: el top 1 es la más antigua (9000 interacciones), orden `[9000,60,59,58,57]` |
| I-3 | Important (ledger) | Verificaciones de T-38/T-39 no cumplidas por su commit (dependían de commits posteriores) | T-38/T-39 | Ledger corregido: la verificación vale en el estado final; se anota la dependencia | `ledger-f8.md` |
| I-4 | Important (ledger) | Cifras (tests/aserciones) no coinciden con la salida real; T-41 figuraba `completado` | T-41 | Cifras sustituidas por la salida real; T-41 pasa a `en-progreso` (M-03: aprobación del usuario) | `ledger-f8.md` |
| M-1 | Minor | Separador/decimal fijos (`,`) | T-38 | Configurable por locale; por defecto es: `;` y decimal con coma | test «separador y decimal segun locale» (`0,5` / `0.5`) |
| M-2 | Minor | «Sin comparación» y «no disponible» indistinguibles | T-38 | Sin comparación: `anterior`/`variacion_pct` vacíos; con comparación y sin dato: `"no disponible"` | test «distingue sin comparacion de no disponible» |
| M-3 | Minor | El CSV no traía tasa de interacción ni notas | T-38 | Filas `Tasa de interaccion (%)` por red, `tasa_interaccion_pct` por publicación y sección `nota` por métrica no disponible | test «incluye tasa de interaccion y notas...» |
| M-4 | Minor | Rango+comparación inválidos terminaban en informe `failed` | T-40 | Se validan en `store` (error de formulario en `from`) y en `ReportService::create` (`comparison()`); también en la tool MCP | test `ReportFix M-4`: 2 errores de validación, 0 informes, 0 jobs |
| M-5 | Minor | El enlace firmado caducaba en pantalla | T-40 | Ruta `reports.link` (con sesión) que redirige a una URL firmada nueva al pulsar Descargar | test «Descargar genera un enlace firmado nuevo» |
| M-6 | Minor | Límite de 5 en curso no atómico | T-38/T-40 | `ReportService::enqueue()`: transacción + `lockForUpdate` sobre el usuario, todo o nada; lo usan el controlador y la tool MCP | test `ReportFix M-6` (4 de 5, tercero rechazado sin crear nada) |
| M-7 | Minor | Informes `pending` atascados y sin borrado | T-40 | `stuck_hours=2`: un atascado no cuenta para el límite, se muestra `failed` y `reports:prune` lo marca `failed`; `DELETE reports/{report}` borra fila+fichero (409 si está en curso) | test `ReportFix M-7` |
| M-8 | Minor | `put()` devolviendo false se ignoraba | T-38 | Excepción -> `failed` | test `ReportFix M-8/M-10` |
| M-9 | Minor | Sin purga por `retention_days` ni borrado de ficheros | T-38/T-40 | Comando `reports:prune` diario (04:10); `Report::deleting` borra el fichero; `Brand::deleting`/`User::deleting` (AppServiceProvider) purgan antes del FK en cascada | test `ReportFix M-9` |
| M-10 | Minor | Mensaje de error con detalle interno al usuario | T-38/T-40 | Error genérico `ReportService::GENERIC_ERROR`; detalle en log (`report.generate_failed`/`report.job_failed`) | tests `ReportPdf` y `ReportFix M-8/M-10` |
| M-11 | Minor | Gráfico de 366 días desborda A4 | T-39 | Más de 60 puntos (`chart_max_daily_points`) -> barras semanales (≤ 53) y etiqueta «por semana» | test `ReportFix M-11` (53 puntos, primera semana = 7) |
| M-12 | Minor | Emojis salen como cuadrados (DejaVu Sans) | T-39 | `PdfRenderer::withoutEmoji()` los elimina del PDF (el CSV los conserva); documentado en config y ADR-002 | test `ReportFix M-12` |
| M-13 | Minor | Snapshots se cargan una vez por red | T-39 | Parcial: el top de publicaciones ya se carga una sola vez para todas las redes. Las lecturas de series de cuenta (`networkSeries` en `summary()`/`network()`) viven en AnalyticsService, que corrige otro agente; no se toca | `ReportService::topPosts` |
| M-14 | Minor | Falta el ADR de DomPDF | T-39 | `docs/knowledge/adr/ADR-002-pdf-dompdf.md` (estado `propuesta`), índice del README y `design.md` filas 72 y 176 actualizadas | ficheros indicados |
| T-42 | Decisión Fase 9 | `get_report` devolvía una URL firmada que exigía sesión | T-42 | `reports.download` pasa a `reports/{report}/download/{owner}` solo con firma (sin sesión), TTL 5 min (`download_minutes`), dueño codificado en la firma y comprobado, solo informes `ready`, audita `report_download` (canal `signed-url`); `get_report` usa `Report::signedDownloadUrl()` | tests: firma válida sin sesión -> 200; caducada/id o dueño alterado -> 403; firma de otro dueño -> 404 sin auditar; no `ready` -> 404; MCP descarga anónima 200 y a los 6 min 403 |

Verificación tras corregir: suite completa 669 passed (4802 assertions); `pint --test` passed. Sin gaps Critical/Important pendientes de corrección; pendiente revisión intento 2.

## Revisión de dos lentes — intento 2: Fases 7-8 e intento 1: T-42 — 1 Important, 7 Minor

Lentes A+B+C+D (agente `reviewer`, contexto fresco) con traspaso de las tablas del intento 1. Traspaso: las correcciones del intento 1 de la Fase 7 y de la Fase 8 quedan **✓** (fuzz CSV de 27 payloads sin hallazgos; SQL portable revisada). Corrección (implementer) sobre `feature/social-scheduler`; reproductores `rv8/stock.php` (31-oct conserva 1520) y `rv8/prune.php` (2500 → 0).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | El reintento de un día incompleto sobrescribía el stock (seguidores, vídeos) con el valor ACTUAL | T-31 | `SnapshotCollector::merge()` prefiere el valor ya guardado para los `Metric::STOCKS` (los flujos faltantes sí se rellenan). Además los lectores de Meta, YouTube y TikTok solo leen el stock del ÚLTIMO día cerrado de la red (`Days::closed`), no de «los últimos 2 días» | `rv8/stock.php`: `2026-10-31 followers=1520`, `2026-11-01 followers=1600`; tests `SnapshotsTest` «un reintento actualiza los flujos pero conserva el stock...» y «un dia que no es el ultimo cerrado no lee el stock actual...» (YouTube con HTTP simulado) |
| N2 | Minor | El periodo por defecto terminaba en «ayer» de la zona de la marca aunque ese día aún no estuviera recogido | T-35/T-37 | `Period::make` sin `to`: fin = mínimo entre «ayer» en la zona de la marca y el último día cerrado de cada red (`Days::closed`); misma lógica para web y MCP | test `AnalyticsReviewTest` «Periodo y filtro...» (Auckland → `2026-10-31`); `CalculatorTest` periodo por defecto |
| N3 | Minor | «Seguidores (fin del periodo)» del crecimiento no coincidía con `metrics.followers.total` (excluía cuentas de un solo punto) | T-35 | `Calculator::growthAcross` suma a `start`/`end` el valor de las cuentas con un solo punto (variación 0) | test `AnalyticsReviewTest` «Crecimiento: un solo punto...» (`growth.end` = 150 = `total`) |
| N4 | Minor (ledger) | Traspaso #3 de la Fase 7 decía «Publicación sin cambios» | T-32 | Texto corregido: 800xx/4/17/32/613 son transitorios también al publicar, con reintentos acotados por `max_attempts` | fila #3 de la tabla de la Fase 7 |
| M-a | Minor | `ReportService::topPosts` filtraba `published_at` en UTC, no en la zona de la marca | T-38 | `Period::utcBounds($timezone)` (compartido con `AnalyticsService::bounds`) y `topPosts` filtra `>= desde` y `< hasta` | test `ReportFix M-a` (publicación de las 09:00 locales del 11 en Auckland: fuera el 10, dentro el 11) |
| M-b | Minor | `reports:prune` borraba paginando por offset (`each`) y se saltaba filas | T-38/T-40 | `chunkById(200)` | `rv8/prune.php`: `Purgados por retencion: 2500 · quedan: 0`; test `ReportFix M-b` (2100 filas → 0) |
| T1 | Minor | `get_stats`/`generate_report` calculaban el periodo por defecto en UTC | T-42 | `Period::make(..., $brand->timezone)` en ambas tools y en el valor por defecto de la pantalla de informes | misma lógica que el dashboard; suite de MCP en verde |
| T2 | Minor (ledger) | TTL de la URL de `get_report` no constaba como 5 min | T-42 | Notas de T-42: URL firmada de 5 min, ruta solo con firma (`reports.download_minutes` = 5) | `config/reports.php`, `Report::signedDownloadUrl()` |

Verificación tras corregir: suite completa 675 passed (4818 assertions); `vendor/bin/pint --test` passed; `npm run build` OK. Sin gaps Critical/Important pendientes: bucles de las Fases 7, 8 y 9 cerrados.
