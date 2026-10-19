---
design: "social-scheduler"
titulo: "Social Scheduler — stack y arquitectura de despliegue en HestiaCP"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-19"
actualizado: "2026-10-19"
spec: spec.md                 # enlace hacia atrás (misma carpeta)
evaluacion: "evaluation.md"    # evaluation.md | n/a
plan: improvement-plan.md     # creado por el planner
adr: "docs/knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md"
opcion_elegida: "O1"    # validada por el usuario 2026-10-19 (vía /pm-cycle, pasada 2)
generacion:
  inicio: 2026-10-19T15:03:26Z
  fin: 2026-10-19T15:06:16Z
  fuente: medido          # usage-meter.py (pasada 1)
  tokens_reales: { entrada: 22, salida: 14517, cache_creacion: 81765, cache_lectura: 372924 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json) — ejecuta rates-verify
  horas_ia: 0.32
  duracion: 19m
  ratio_usado: 300000     # default no calibrado
  pasada2: { inicio: 2026-10-19T15:10:31Z, fin: 2026-10-19T15:11:47Z, fuente: medido, tokens_reales: { entrada: 18, salida: 7075, cache_creacion: 29269, cache_lectura: 601096 }, eur: null, horas_ia: 0.12, duracion: 7m }
---

# Diseño — Social Scheduler: stack y arquitectura de despliegue en HestiaCP

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) · **Plan:** [`improvement-plan.md`](improvement-plan.md) · **ADR:** [`ADR-001`](../../knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md) (propuesta)

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | O1 — Laravel monolito nativo en Hestia (PHP-FPM + workers systemd) |
| **Validada por el usuario** | 2026-10-19 (elección transmitida por `/pm-cycle`, pasada 2) |

## 1. Contexto y restricciones

La spec pide una app web personal multiusuario con aislamiento estricto por `user_id` (CA-01, CA-02, CA-12). Debe conectar 4 redes por OAuth y publicar 13 tipos de contenido mediante una cola con reintentos, idempotencia y polling asíncrono (CA-06, CA-07). También pide snapshots diarios, dashboards, informes PDF/CSV y un servidor MCP HTTP autenticado por usuario que reutilice la capa de servicios.

El repo está vacío (greenfield). Hoy solo existen `docs/roadmap/README.md` y la carpeta de esta iniciativa (`spec.md`, `evaluation.md`). No hay `docs/CONSTITUTION.md`, ni ADR en `docs/knowledge/`, ni journal de sesiones previas. Ningún principio ni decisión vigente acota el stack.

La evaluación estimó 606 h con el supuesto «TypeScript full-stack» y declaró ±15 % si cambia el stack. El riesgo dominante es externo (Meta App Review, auditoría de TikTok, cuota y verificación de YouTube), no el stack.

**Restricciones que fijan el espacio de soluciones**

- **HestiaCP manda en el borde.** Su Nginx ocupa 80/443 y emite TLS Let's Encrypt. Ofrece PHP-FPM (HestiaCP 1.10.x soporta PHP 8.5 y trae plantilla «Laravel Quick Install»), MariaDB/PostgreSQL, cron por usuario y backups. Cualquier proceso no-PHP necesita una plantilla proxy de Nginx hacia un puerto local.
- **Docker no es nativo de Hestia.** Si se usa, los contenedores escuchan en `127.0.0.1:<puerto>` tras una plantilla proxy.
- **Procesos persistentes obligatorios.** Workers de cola corren como unidades systemd. **Confirmado por el usuario (2026-10-19):** hay acceso root por SSH al VPS.
- **Redis no viene de serie.** Cualquier cola basada en Redis exige instalarlo y operarlo aparte.
- **Media en S3 compatible con URL pública** (R2 recomendado). IG y TikTok (`PULL_FROM_URL`) descargan desde URL; TikTok exige prefijo de URL/dominio verificado ⚠️ verificar → dominio propio sobre el bucket R2.
- **Volumen personal** (decenas de publicaciones/semana). No se diseña para escala SaaS.
- **MCP por HTTP** con API key personal en v1; OAuth 2.1 opcional (evaluación: +12–16 h).

## 2. Opciones

Las dos opciones comparten el modelo de dominio y los flujos de la spec. Difieren en lenguaje, en cómo encajan en Hestia y en cuánta pieza hay que ensamblar a mano. En ambas, la cola vive en la base de datos relacional para no depender de Redis en v1 (O1: MariaDB/MySQL de Hestia; O2: PostgreSQL, que exige pg-boss).

### O1 — Laravel monolito nativo en Hestia (PHP-FPM + workers systemd)

Una sola app Laravel 13 (`laravel/framework` v13.37, 2026-10-16) servida por el PHP-FPM de Hestia con su plantilla Laravel. Hestia gestiona dominio, TLS, BD MariaDB/MySQL y backups.

| Pieza | Elección | Estado verificado (2026-10-19) |
|---|---|---|
| Frontend | Inertia + Vue (o React) con Vite; gráficos con ECharts/Chart.js | `inertiajs/inertia-laravel` v3.5.1 (2026-10-01) |
| Auth web | Starter kit de Laravel (sesión, registro, reset de contraseña) | incluido en el framework |
| Aislamiento | Trait `BelongsToUser` con global scope + Policies en cada servicio (sin RLS: MariaDB/MySQL no la ofrece; el aislamiento es solo de aplicación) | patrón del framework |
| Cola | Driver `database` sobre MariaDB ≥ 10.6 / MySQL ≥ 8.0 (`SKIP LOCKED`; versiones anteriores pueden bloquear o duplicar con varios workers); `tries`, `backoff`, `ShouldBeUnique`, middleware `WithoutOverlapping` | incluido; Horizon v5.50.1 queda como ampliación si se instala Redis |
| Workers | `php artisan queue:work --queue=publish,poll,metrics,reports --max-time=3600` como unidades systemd | root confirmado |
| Scheduler | Cron de Hestia: `* * * * * php artisan schedule:run` (no requiere root) | nativo Hestia |
| OAuth redes | Conectores propios por red, TODOS con el cliente `Http` de Laravel (Meta, Google y TikTok): se descartó `laravel/socialite` (solo cubre el canje básico; los hallazgos del spike —portfolios `owned_pages`, `debug_token`, `fb_exchange_token`, `invalid_grant`— exigen control fino de cada llamada) y evita una dependencia más; implementado en T-12/T-13/T-14 | `socialiteproviders/tiktok` 5.2.0 sin releases desde 2024-04 ⚠️ no usar sin probar |
| YouTube | OAuth/refresh con `Http` propio (T-13, sin `google/apiclient`); la subida reanudable por chunks se decide en la fase de publicación (`google/apiclient` v2.20.1 u `Http` propio) | 2026-09-25 |
| Media | Flysystem S3 (`league/flysystem-aws-s3-v3` 3.35) contra R2; subida multipart directa navegador→R2 con URLs prefirmadas | 2026-08-08 |
| MCP | `laravel/mcp` v1.0.2 (2026-10-11): `Mcp::web('/mcp', ...)` + `auth:sanctum` (Bearer = API key personal); OAuth 2.1 vía Passport (`Mcp::oauthRoutes`) como ampliación | verificado en la doc oficial 13.x |
| PDF/CSV | **DomPDF** (`barryvdh/laravel-dompdf` ^3.1, PHP puro, driver configurable `REPORTS_PDF_DRIVER`; gráficos como barras HTML/CSS) — decidido en T-39, ver [ADR-002](../../knowledge/adr/ADR-002-pdf-dompdf.md); `spatie/laravel-pdf` (Chromium) descartado para el VPS HestiaCP | decidido 2026-10-20 (ADR-002, propuesta) |

| Criterio | Valoración |
|---|---|
| Complejidad | **Media.** Un proceso web (PHP-FPM gestionado por Hestia) + N workers systemd + cron. Cola, reintentos, unique jobs, scheduler, auth, cifrado (`encrypted` cast para tokens OAuth) y MCP vienen del framework o de paquetes oficiales. |
| Riesgo | **Medio-bajo.** `laravel/mcp` es 1.0 desde el 2026-09-14 (5 semanas). Los workers PHP de larga vida necesitan `--max-time`/`--max-jobs` contra fugas de memoria. La subida de vídeos grandes a YouTube debe hacerse en streaming para no cargar el fichero en memoria. Si el usuario no conoce PHP, la curva recae en revisión humana. |
| Coste relativo | **S.** Menos piezas que ensamblar: auth, cola, scheduler, cifrado, MCP+auth y storage salen del ecosistema oficial. La autenticación OAuth 2.1 del MCP baja de «+12–16 h» a configuración de Passport. |
| Reversibilidad | **Baja tras C-01.** Cambiar de lenguaje después supone reescribir. Lo portable es el modelo de datos (Eloquent/migraciones, portable entre MariaDB/MySQL y PostgreSQL) y el diseño de conectores. Dentro de la opción es reversible: driver de cola (`database`→Redis/Horizon), frontend (Inertia↔Livewire) y driver de PDF. |

### O2 — TypeScript full-stack tras proxy de Hestia (Node + pg-boss + PM2/systemd)

Next.js 16 (v16.4.2) para web y API, un proceso Node aparte para workers y el servidor MCP montado como ruta HTTP. Los procesos escuchan en `127.0.0.1` y una plantilla proxy de Nginx de Hestia los expone. Hestia aporta dominio, TLS, PostgreSQL y backups de BD; el ciclo de vida de los procesos es de PM2 o systemd.

| Pieza | Elección | Estado verificado (2026-10-19) |
|---|---|---|
| Frontend + API | Next.js 16 (App Router, route handlers) | `next` 16.4.2, Node ≥ 20.9 |
| Auth web | `better-auth` 1.7.11 (sesión, registro, reset) | 2026 |
| ORM | Drizzle (`drizzle-orm` 0.45.4) | Prisma 8 aún en RC (`8.0.0-rc.23`) |
| Aislamiento | Capa de repositorios que exige `userId`; RLS con `set_config` por transacción opcional | a construir |
| Cola | `pg-boss` 12.38 sobre PostgreSQL (reintentos, backoff, `singletonKey`, cron) | Node ≥ 22.12; BullMQ 6.3 exigiría Redis |
| Workers + scheduler | Proceso `worker` Node bajo PM2 o systemd; cron de pg-boss | root confirmado |
| OAuth redes | Clientes HTTP propios por red; `googleapis` para YouTube | a construir |
| Media | AWS SDK v3 S3 contra R2; multipart prefirmado navegador→R2 | estándar |
| MCP | `@modelcontextprotocol/sdk` 1.32.1 (Streamable HTTP); middleware Bearer propio contra tabla `api_keys`; OAuth 2.1 a montar | 2026-10-05 |
| PDF/CSV | Playwright/Puppeteer → Chromium headless | memoria en el VPS |

| Criterio | Valoración |
|---|---|
| Complejidad | **Media-alta.** 2–3 procesos Node que Hestia no conoce, plantilla proxy de Nginx a medida, gestor de versiones de Node y despliegue propio (build + restart). Auth, ORM, cola, MCP-auth y cifrado de tokens se ensamblan a partir de librerías independientes. |
| Riesgo | **Medio.** Las plantillas proxy a medida pueden romperse en upgrades de Hestia. Más superficie que mantener (versiones mayores frecuentes de Next/Node). El aislamiento depende de una disciplina de repositorio propia. A favor: es el stack del supuesto de la evaluación, así que las cifras no se mueven. |
| Coste relativo | **M.** Más integración y más trabajo de operación que O1. El OAuth 2.1 del MCP sigue costando las +12–16 h de la evaluación. |
| Reversibilidad | **Baja tras C-01**, igual que O1. Dentro de la opción: cambiar pg-boss por BullMQ es moderado; pasar de PM2/systemd a Docker tras proxy es barato si se empaqueta desde el inicio. |

### Alternativas consideradas y no presentadas como opción

- **O2 en Docker Compose tras proxy de Hestia.** No es una arquitectura distinta: es un modo de despliegue de O2. Añade un daemon Docker que Hestia no gestiona y no resuelve nada que systemd no resuelva a este volumen. Queda como pregunta abierta si se elige O2.
- **Python (Django 6.1 + Procrastinate/Celery + SDK `mcp` 2.3).** Tiene la forma de O2 (proceso tras proxy, piezas que ensamblar) sin el encaje nativo de O1 ni ser el supuesto de la evaluación. No aporta un trade-off nuevo.

## 3. Criterios de decisión

1. **Encaje operativo con HestiaCP** (peso alto). Es el único entorno de despliegue y lo opera una persona. Cuanto más gestione Hestia (TLS, PHP-FPM, cron, backups), menos operación propia.
2. **Fiabilidad de la cola sin Redis** (peso alto). CA-06/CA-07 exigen reintentos, idempotencia y recuperación tras caída del VPS único.
3. **Servidor MCP con auth por usuario reutilizando servicios** (peso medio-alto). CA-12 y la ampliación OAuth 2.1.
4. **Coste de ensamblaje** (peso medio). El grueso del presupuesto es C-03 y las plataformas; el stack no debe sumar trabajo de fontanería.
5. **Familiaridad del usuario con el lenguaje** (peso medio). El usuario domina PHP/Laravel (confirmado 2026-10-19). Condiciona la revisión humana (73 h no delegables en la evaluación).

Reversibilidad no discrimina: ambas son de ida tras C-01.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto:** **O1 — Laravel monolito nativo en Hestia.** Gana en los criterios 1–4. Hestia sirve la app con su plantilla Laravel y el scheduler usa su cron. La cola `database` sobre MariaDB/MySQL cubre reintentos, backoff y unicidad sin Redis. `laravel/mcp` 1.0 ofrece MCP HTTP con Sanctum (API key) y OAuth 2.1 vía Passport sin código propio. El riesgo principal es el criterio 5: si el usuario solo domina TypeScript, O2 pasa a ser la opción razonable.

**O1 — Laravel monolito nativo en Hestia.** El usuario la validó el 2026-10-19. Cumple los cinco criterios. (1) Hestia sirve la app y el cron con sus plantillas de serie. (2) La cola `database` sobre MariaDB/MySQL (la BD que Hestia trae de serie) da reintentos, backoff y unicidad sin Redis. (3) `laravel/mcp` + Sanctum cubren CA-12 sin auth propia. (4) El ensamblaje es el menor de las dos opciones (coste S). (5) El usuario domina PHP/Laravel, así que la revisión humana no paga curva de aprendizaje.

Descartadas: **O2 (TypeScript tras proxy)**. Pierde en encaje con Hestia (procesos y plantilla proxy propios) y en coste de ensamblaje (M), y no es el lenguaje que domina el usuario. **Docker Compose**: daemon extra sin beneficio a este volumen. **Python/Django**: misma forma que O2 sin trade-off nuevo.

## 5. Impacto en módulos y ficheros

El repo no tiene código: todas las rutas de código son **nuevas** y siguen la convención de Laravel 13 (O1).

| Módulo / fichero (ruta) | Cambio | Nuevo / modificado |
|---|---|---|
| `app/Models/{User,Brand,SocialAccount,Post,PostTarget,MediaAsset,PublishAttempt,AccountSnapshot,PostMetricSnapshot,Report}.php` | Modelo de dominio; trait `app/Models/Concerns/BelongsToUser.php` con global scope | Nuevo |
| `app/Services/` (`BrandService`, `PostService`, `CalendarService`, `AnalyticsService`, `ReportService`) | Capa de servicios única para web y MCP; toda consulta pasa por el usuario autenticado | Nuevo |
| `app/Connectors/{Meta,Google,TikTok}/` | OAuth, publicación por tipo, polling de estado, insights; interfaz común `app/Connectors/Contracts/` | Nuevo |
| `app/Jobs/{PublishPostTarget,PollPublishStatus,RefreshTokens,CollectSnapshots,GenerateReport}.php` | Jobs con `tries`, `backoff`, `ShouldBeUnique`; máquina de estados de publicación | Nuevo |
| `routes/console.php` | Schedule: despachar vencidas cada minuto, refresco de tokens, snapshots diarios | Nuevo |
| `app/Mcp/Servers/SchedulerServer.php` + `app/Mcp/Tools/*` · `routes/ai.php` | Servidor MCP `Mcp::web('/mcp', …)->middleware('auth:sanctum')` | Nuevo |
| `resources/js/Pages/` | Inertia: marcas, conexiones, compositor, calendario, dashboards, informes, API keys | Nuevo |
| `database/migrations/` | Esquema MariaDB/MySQL + tablas `jobs`, `failed_jobs`, `personal_access_tokens` | Nuevo |
| `config/filesystems.php` · `config/queue.php` | Disco `r2` (S3) y conexión de cola `database` con colas `publish,poll,metrics,reports` | Nuevo |
| `deploy/systemd/scheduler-worker@.service` | Unidades systemd de workers (`--max-time`, `Restart=always`) | Nuevo |
| `deploy/hestia/` | Notas/plantilla de dominio Hestia (Laravel, `public/` como docroot), cron y PHP 8.4/8.5 | Nuevo |
| `tests/Feature/Isolation/` | Tests de aislamiento web, API y MCP (CA-02, CA-12) desde C-01 | Nuevo |
| `docs/roadmap/2026-10-19-social-scheduler/spec.md` | Campo `design: design.md` + callout | Modificado |
| `docs/knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md` · `docs/knowledge/README.md` | ADR de la decisión (`propuesta`) + fila del índice | Nuevo |

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Pérdida futura del acceso root (cambio de proveedor/panel) | Baja | Medio | Root confirmado hoy. Plan B documentado: cron de Hestia con `queue:work --stop-when-empty` cada minuto (latencia ≤ 1 min) |
| `laravel/mcp` joven (1.0 desde 2026-09-14) cambia API o tiene bugs (O1) | Media | Medio | Fijar versión menor; tools finas sobre `app/Services`; si falla, servidor MCP con `@modelcontextprotocol/sdk` como proceso aparte que llama a la API |
| Workers PHP de larga vida con fugas de memoria o código obsoleto tras deploy (O1) | Media | Medio | `--max-time=3600 --max-jobs=500`, `Restart=always`, `php artisan queue:restart` en cada deploy |
| Vídeos grandes cargados en memoria al subir a YouTube | Media | Medio | Subida reanudable en streaming desde R2 (chunks de 8–16 MB); nunca pasar el binario por PHP-FPM (subida navegador→R2 prefirmada) |
| Publicación duplicada tras timeout | Media | Alto | `ShouldBeUnique`/`singletonKey` por `post_target_id`, estado `publicando` persistido antes de llamar a la red y consulta del estado externo antes de reintentar |
| VPS caído a la hora de publicar | Media | Medio | La cola vive en la BD (MariaDB/MySQL); el scheduler despacha cada minuto todo lo vencido no publicado; healthcheck externo |
| Chromium headless para PDF consume RAM en el VPS | Media | Bajo | Generar PDF en la cola `reports` con concurrencia 1; alternativa DomPDF con gráficos rasterizados |
| Upgrade de Hestia sobrescribe ajustes de Nginx/PHP-FPM | Baja | Medio | O1 usa la plantilla Laravel de serie; cualquier ajuste (p. ej. `client_max_body_size`) va versionado en `deploy/hestia/` |
| Desvío sobre la evaluación (supuesto TS) | Baja | Bajo | Ambas opciones dentro del ±15 % declarado; O1 probablemente por debajo |

## 7. Preguntas abiertas

**Resueltas por el usuario (2026-10-19, vía `/pm-cycle`):**

| # | Pregunta | Respuesta |
|---|---|---|
| 1 | ¿Qué lenguaje domina? | PHP/Laravel → O1 |
| 2 | ¿Acceso root SSH al VPS? | Sí → workers como unidades systemd |
| 3 | ¿Redis en v1? | No: cola driver `database` en la BD relacional; Horizon/Redis como ampliación |
| 4 | ¿Motor de BD? | **MariaDB/MySQL gestionada por Hestia** (cambio del usuario 2026-10-19, sustituye a PostgreSQL). Requisito: MariaDB ≥ 10.6 o MySQL ≥ 8.0 por `SKIP LOCKED`; verificar con `mysql --version` en C-01 |

**Pendientes para el planner / implementer:**

- ~~**PDF:** ¿Chromium headless o DomPDF?~~ **Resuelto en T-39: DomPDF** (ver [ADR-002](../../knowledge/adr/ADR-002-pdf-dompdf.md)).
- **Frontend:** Inertia + Vue (por defecto) o React; se fija al crear el esqueleto en C-01.
- **Conector TikTok:** cliente HTTP propio (por defecto) frente a `socialiteproviders/tiktok` (sin releases desde 2024-04 ⚠️).

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-19 | Diseño creado (`borrador`); opciones O1 y O2 presentadas al usuario vía `/pm-cycle` (pasada 1) |
| 2026-10-19 | Usuario elige O1 y responde preguntas 1–3 (PHP/Laravel, root SSH, PostgreSQL sin Redis); ADR-001 `propuesta`; estado → `aprobado` (pasada 2) |
| 2026-10-19 | Usuario cambia el motor de BD a MariaDB/MySQL (la de Hestia) en lugar de PostgreSQL; versión mínima MariaDB 10.6 / MySQL 8.0; sin RLS |
