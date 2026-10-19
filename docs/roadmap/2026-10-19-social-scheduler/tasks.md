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
| Fase 3 — Publicación base y Meta (IG + FB), calendario | 0 | 9 | 0% | 0 / 142h | 0 / 21.8h | 0 / 5.3h | 0 / 6,47M |
| Fase 4 — Publicación en YouTube (condicionada a G1) | 0 | 1 | 0% | 0 / 18h | 0 / 2.8h | 0 / 0.7h | 0 / 820k |
| Fase 5 — Publicación en TikTok (condicionada a G1) | 0 | 1 | 0% | 0 / 16h | 0 / 2.5h | 0 / 0.6h | 0 / 728k |
| Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación | 0 | 3 | 0% | 0 / 37h | 0 / 5.6h | 0 / 1.4h | 0 / 1,69M |
| Fase 7 — Analítica: snapshots, insights y dashboards | 0 | 7 | 0% | 0 / 106h | 0 / 16.2h | 0 / 4.1h | 0 / 4,83M |
| Fase 8 — Reporting PDF/CSV | 0 | 4 | 0% | 0 / 40h | 0 / 6h | 0 / 1.5h | 0 / 1,82M |
| Fase 9 — MCP estadísticas/informes y validación final | 0 | 2 | 0% | 0 / 16h | 0 / 2.4h | 0 / 0.6h | 0 / 728k |
| **TOTAL** | **12** | **43** | **28%** | **0 / 505h** | **0 / 76.3h** | **0 / 19.2h** | **0 / 23,00M** |

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
- **Archivos**: `app/Connectors/Meta/`, `app/Http/Controllers/OAuth/MetaController.php`, `config/services.php`, `tests/Feature/Connectors/Meta/`, `app/Connectors/Meta/MetaConnector.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `app/Http/Controllers/ConnectionController.php`, `routes/web.php`, `.env.example`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Brands/Connections.vue`, `tests/Feature/Connectors/Meta/MetaOAuthTest.php`, `app/Connectors/Data/ConnectionCandidate.php`, `app/Support/TrustedProxies.php`, `bootstrap/app.php`, `config/social.php`, `tests/Feature/Infra/TrustedProxiesTest.php`, `tests/Unit/TrustedProxiesTest.php`, `docs/roadmap/2026-10-19-social-scheduler/design.md`, `tests/Support/FakeConnector.php`, `tests/Feature/Connectors/Google/GoogleOAuthTest.php`
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

**Estado**: borrador · **Estimado**: 142h · **Real**: — · **Coste est.**: 8.520 € + tokens (⚠️ verificar precio) · **Tokens est.**: 6,47M

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
- **Archivos**: `app/Models/MediaAsset.php`, `app/Services/MediaUploadService.php`, `app/Http/Controllers/MediaController.php`, `resources/js/Components/MediaUploader.vue`, `tests/Feature/Media/`, `config/media.php`, `database/migrations/2026_10_21_090000_create_media_assets_table.php`, `database/factories/MediaAssetFactory.php`, `app/Services/MediaStorage.php`, `app/Services/MediaProbe.php`, `app/Policies/MediaAssetPolicy.php`, `routes/web.php`, `deploy/hestia/README.md`, `tests/Feature/Media/MediaUploadTest.php`
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
- **Archivos**: `app/Jobs/PublishPostTarget.php`, `app/Jobs/PollPublishStatus.php`, `routes/console.php`, `app/Services/PublishRecovery.php`, `tests/Feature/Publishing/`, `config/publishing.php`, `app/Jobs/DispatchDuePosts.php`, `app/Services/PublishOutcomes.php`, `app/Services/PublishRequestFactory.php`, `app/Connectors/ConnectorRegistry.php`, `app/Connectors/Contracts/Publisher.php`, `app/Connectors/Contracts/StatusPoller.php`, `app/Connectors/Data/PublishMedia.php`, `app/Connectors/Data/PublishRequest.php`, `app/Connectors/Data/PublishResult.php`, `app/Connectors/Exceptions/PublishException.php`, `app/Connectors/Exceptions/TransientPublishException.php`, `app/Connectors/Exceptions/PermanentPublishException.php`, `app/Connectors/Exceptions/QuotaExceededException.php`, `tests/Support/FakePublisher.php`, `tests/Feature/Publishing/PublishingTest.php`
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
**Notas (implementer, 2026-10-19)**: Contratos `Publisher` (publish + `findPublished`) y `StatusPoller` rehechos con `PublishRequest/Result` y excepciones transitoria/permanente/cuota (el `Publisher` previo era solo un esqueleto sin implementaciones). `DispatchDuePosts` (scheduler cada minuto, en linea y `withoutOverlapping`) ejecuta `PublishRecovery` (cuenta eliminada -> fallida; vencidas > `max_lateness_hours`=24 -> fallida `vencida`; `publicando` colgado -> se relanza; `procesando` con sondeo vencido -> se vuelve a sondear) y despacha un `PublishPostTarget` (cola `publish`, `ShouldBeUnique` por destino, timeout 110 < worker 120 < retry_after 180) por destino `programada` vencido con cuenta activa. El job reclama con `lockForUpdate` y persiste `publicando` antes de llamar a la red; en cualquier reintento (attempts>1 o referencia externa) consulta `findPublished` antes de publicar; transitorio -> `programada` con backoff 60/300/900 s hasta `max_attempts` (4) -> `fallida` con motivo; permanente -> `fallida` sin reintento; cuota -> aplazado sin gastar intento (la politica elegible llega en T-25); credenciales invalidas -> la cuenta pasa a `requiere_reconexion` (aviso una vez) y la publicacion se conserva `programada`. `PollPublishStatus` (cola `poll`) sondea `procesando` hasta `poll_max_minutes`; un error pasajero al sondear nunca falla el destino. Decision de producto por defecto (documentada en `config/publishing.php`): las vencidas se publican al volver solo si llevan < 24 h de retraso. **Verificacion**: `php artisan test --filter=Publishing` -> 24 passed, 105 assertions (reintentos con backoff, timeout-tras-enviar sin duplicado, recuperacion de vencidas y colgadas, cuota, reconexion, sondeo); suite completa 254 passed. Ejecuciones simultaneas del mismo destino publican una vez (test).

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
- **Archivos**: `app/Connectors/Meta/Instagram/`, `tests/Feature/Connectors/Meta/Instagram/`, `app/Connectors/Meta/GraphClient.php`, `app/Providers/AppServiceProvider.php`, `app/Enums/ContentType.php`, `app/Services/PublishRequestFactory.php`, `tests/Support/FakeGraph.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramPublishTest.php`
- **Cubre (tests)**: E2E-06, M-01
- **Verificación**:
  - `php artisan test --filter=InstagramPublish` → pasa con respuestas grabadas
  - lectura: M-01 registra la publicación real de los 4 tipos de IG con enlace/ID

**Criterios de aceptación**
- [ ] Cada tipo se publica y queda `publicada` con enlace/ID externo (CA-06)
- [x] Errores de la API mapeados a transitorios o permanentes
- [ ] Limitaciones de stories avisadas en el compositor (datos en ContentType::limitations(); se muestran en T-23)

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
- **Tiempo IA (ejec.)**: est. 2.5h · real 0.17h (medido)
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
**Decisión G1 (2026-10-19): YouTube = A.** Publicación con la visibilidad pedida (verificado: `public` no se fuerza a privado). Cuota 10.000 ud/día ≈ 6 subidas: bloquear/reprogramar al superar el cupo.

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
**Decisión G1 (2026-10-19): TikTok = D + B.** Modo por defecto: **borrador a la bandeja** (`/v2/post/publish/inbox/video/init/`, FILE_UPLOAD por chunks; estado final a la espera del usuario en la app). **Direct Post** (`/v2/post/publish/video/init/` con `post_info`) detrás de la opción por cuenta `tiktok.direct_post` (desactivada hasta aprobar la auditoría; sin auditoría la API responde `unaudited_client_can_only_post_to_private_accounts`). Validar vídeo ≥ 23 fps antes de subir (`frame_rate_check_failed`). PULL_FROM_URL solo con dominio verificado.

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
**Decisión G1 (2026-10-19): YouTube = A.** Analítica vía YouTube Analytics API (`yt-analytics.readonly`).

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
**Decisión G1 (2026-10-19): TikTok = D + B.** Analítica limitada a lo que den `user.info.stats`/`video.list` si se conceden en la auditoría; sin ellos, mostrar «no disponible» por red (documentado, no bloquea).

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
