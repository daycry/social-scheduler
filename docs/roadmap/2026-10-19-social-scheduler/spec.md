---
spec: social-scheduler
descripcion: Aplicación web personal tipo Metricool para programar, publicar y analizar contenido en Instagram, Facebook, YouTube y TikTok, con informes exportables y servidor MCP para agentes de IA.
estado: aprobada          # borrador | aprobada | implementada | obsoleta
creado: 2026-10-19
actualizado: 2026-10-19
evaluacion: evaluation.md
design: design.md         # design.md si el agente architect hizo el paso de diseño (opcional); n/a si no
plan: improvement-plan.md  # ruta al plan
generacion:
  inicio: 2026-10-19T14:52:49Z
  fin: 2026-10-19T14:56:30Z
  fuente: estimado        # ⚠️ estimado a juicio: redactada dentro de la ventana medida de evaluation.md (un solo marcador); sus tokens ya están contados allí, no sumar
  tokens_reales: { entrada: 0, salida: 14000, cache_creacion: 30000, cache_lectura: 120000 }
  eur: null               # precio de tokens no fiable (no hay .claude/rates.json) — ejecuta rates-verify
  horas_ia: 0.15
  duracion: 9m
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Social Scheduler — programación y analítica de redes sociales (uso personal)

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Diseño:** [`design.md`](design.md) (aprobado: O1, Laravel 13 nativo en HestiaCP)
> **Plan de implementación:** [`improvement-plan.md`](improvement-plan.md) (borrador)

> **Terminología:**
> **Usuario** = persona registrada; dueña exclusiva de sus datos. **Marca (brand)** = agrupación lógica de cuentas sociales de un usuario (p. ej. «Proyecto A»). **Cuenta conectada** = perfil/página/canal de una red enlazado por OAuth a una marca. **Publicación (post)** = contenido programado para una cuenta y un tipo de contenido concreto. **Snapshot** = foto diaria de métricas guardada por la aplicación para construir histórico propio. **MCP** = Model Context Protocol, servidor de herramientas que un agente de IA consume.

## Contexto y objetivo

El usuario quiere una herramienta propia, al estilo de Metricool, para gestionar su presencia en redes sociales sin depender de un SaaS de terceros. Es una aplicación **de uso personal** (no comercial): sin planes ni facturación, pero **con registro y login** y con **aislamiento estricto de datos por usuario**, de modo que pueda haber más de una persona usándola sin ver nada ajeno.

Debe permitir: conectar cuentas de Instagram, Facebook (páginas), YouTube y TikTok agrupadas en marcas; programar publicaciones con los tipos de contenido nativos de cada red y que se publiquen solas a su hora; ver un calendario; recoger estadísticas (con histórico propio mediante snapshots diarios); dashboards y reporting exportable; y exponer todo ello a un agente de IA mediante un servidor MCP autenticado por usuario.

Fuente: requisitos confirmados con el usuario y transmitidos por el orquestador `/pm-cycle` el 2026-10-19 (sin documento previo; esta spec es la primera versión escrita).

## Decisiones de diseño

| Decisión | Elección | Motivo |
|---|---|---|
| Modelo de uso | **Personal, multiusuario aislado, sin billing** | Requisito confirmado: registro/login sí; planes, facturación, equipos, roles y aprobaciones no |
| Jerarquía de datos | **Usuario → Marcas → Cuentas sociales (OAuth por red)** | Requisito confirmado |
| Aislamiento | **Toda entidad cuelga de `user_id`; filtrado obligatorio en la capa de servicio (BD MariaDB/MySQL de Hestia: sin RLS, aislamiento solo de aplicación)** | Requisito de aislamiento estricto; la decisión exacta es de `architect` |
| Redes v1 | **Instagram, Facebook (páginas), YouTube, TikTok** | Requisito confirmado |
| Publicación | **Cola persistente + workers + reintentos con backoff + máquina de estados por publicación** | Publicación automática fiable a la hora programada |
| Histórico de métricas | **Snapshots diarios propios** | Las APIs ofrecen poco histórico |
| Media | **Almacenamiento compatible S3 (MinIO o Cloudflare R2)** | Requisito de despliegue; IG/TikTok descargan la media desde URL pública |
| Despliegue | **VPS gestionado con HestiaCP** (Nginx de Hestia con TLS Let's Encrypt delante; Docker solo si convive tras un proxy de Hestia) | Requisito confirmado (2026-10-19) |
| Acceso para IA | **Servidor MCP con autenticación por usuario (API key personal; OAuth opcional)** | Requisito confirmado |
| Stack | **No fijado — lo decide `architect`.** Supuesto de evaluación: TypeScript full-stack | El usuario pidió «el que mejor se adapte» |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| Reintentos de publicación | config del worker | — | **3–5 intentos con backoff exponencial** (a fijar en diseño) |
| Hora de snapshot diario | cron del scheduler | — | **1 vez/día por cuenta conectada** (hora a fijar) |
| Margen de refresco de tokens | job de refresco | — | **Refrescar antes de caducar** (p. ej. 7 días antes para tokens de 60 días) |
| Cuota YouTube Data API | consola Google Cloud | 10.000 unidades/día | ~1.600 unidades por subida ⇒ ~6 subidas/día con la cuota por defecto ⚠️ verificar |
| Tamaño máximo de vídeo | validación por red | — | Según límites oficiales de cada red ⚠️ verificar en diseño |
| Almacenamiento de media | variables S3 (`endpoint`, `bucket`, credenciales) | — | **MinIO (self-hosted) o R2** |

## Arquitectura y componentes

Proyecto **greenfield** (repositorio vacío): todo es nuevo. Componentes previstos (a validar por `architect`):

- **Web app (frontend):** registro/login, gestión de marcas, conexión de cuentas, compositor de publicaciones por red y tipo, calendario, dashboards, informes.
- **API backend:** capa de servicios de dominio única, reutilizada por la web y por el servidor MCP.
- **Base de datos relacional:** usuarios, marcas, cuentas conectadas (tokens cifrados), publicaciones y su estado, media, snapshots de métricas, informes, API keys.
- **Cola + workers:** publicación programada, polling de estado de publicación (IG/TikTok/YouTube procesan en asíncrono), refresco de tokens, recogida diaria de métricas, generación de informes.
- **Almacenamiento de media S3:** subida (multipart para vídeos grandes), URLs firmadas/públicas para que las redes descarguen.
- **Conectores por red:** OAuth + publicación + insights para Meta (IG y FB), Google (YouTube Data + YouTube Analytics) y TikTok (Login Kit + Content Posting API + Display API).
- **Servidor MCP:** herramientas para marcas/cuentas, publicaciones, calendario, estadísticas e informes; autenticación por usuario.
- **Generador de informes:** PDF y CSV por rango de fechas.

## Flujo (paso a paso)

1. El usuario se registra e inicia sesión.
2. Crea una o varias marcas.
3. En cada marca conecta cuentas sociales vía OAuth (selecciona página de FB / cuenta IG Business o Creator / canal de YouTube / cuenta de TikTok).
4. Sube media; la aplicación valida las specs según la red y el tipo de contenido elegido.
5. Programa la publicación (red, cuenta, tipo, texto, media, fecha/hora, zona horaria).
6. A la hora programada un worker publica; consulta el estado asíncrono si la red lo requiere; reintenta ante errores transitorios; registra el estado final (publicada con enlace/ID externo, o fallida con motivo).
7. Un job diario guarda snapshots de métricas de cuenta y de publicaciones.
8. El usuario consulta calendario, dashboards por marca y genera informes (PDF/CSV) por rango de fechas.
9. Un agente de IA, autenticado con la credencial del usuario, hace lo mismo vía MCP.

## Alcance

- **Dentro (esta iniciativa, por fases):**
  - Registro, login, sesión, recuperación de contraseña; aislamiento por usuario.
  - CRUD de marcas; conexión/desconexión/reconexión OAuth de IG, FB, YouTube y TikTok; refresco de tokens.
  - Publicación programada: IG (post imagen, carrusel, reel, story), FB página (texto/enlace, foto, vídeo, reel), YouTube (vídeo, short), TikTok (vídeo, foto/carrusel). Validación de media por red. Cola, workers, reintentos y estados.
  - Calendario (programadas y publicadas) filtrable por marca y red; editar/reprogramar/borrar publicaciones pendientes.
  - Estadísticas por red (seguidores y crecimiento, alcance, impresiones, engagement, métricas por publicación) con gráficos y snapshots diarios.
  - Dashboards por marca; informes por rango de fechas exportables a PDF y CSV.
  - Servidor MCP con autenticación por usuario.
  - Despliegue en VPS gestionado con HestiaCP y media en S3 compatible.
- **Fuera (siguientes specs):**
  - Planes, facturación, equipos, compartir marcas, roles, flujos de aprobación.
  - Otras redes (X, LinkedIn, Pinterest, Threads, etc.).
  - Bandeja de mensajes/comentarios (inbox), escucha social, análisis de competidores, hashtags.
  - Editor de imagen/vídeo, generación de contenido con IA dentro de la app.
  - Apps móviles nativas.

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| Media no cumple specs de la red/tipo | Se rechaza al programar con mensaje concreto (dimensión, duración, peso, formato) |
| Error transitorio de la API al publicar (5xx, rate limit) | Reintento con backoff; tras agotar intentos, estado `fallida` con motivo y aviso al usuario |
| Error permanente (permiso revocado, contenido rechazado) | Sin reintento; estado `fallida` con motivo legible |
| Token OAuth caducado o revocado | Cuenta marcada `requiere reconexión`; las publicaciones afectadas no se pierden y se avisa al usuario |
| Cuota diaria agotada (p. ej. YouTube) | La publicación se reprograma o queda `fallida` con motivo «cuota»; decisión en diseño |
| Publicación en estado incierto (timeout tras enviar) | Comprobación de idempotencia/estado antes de reintentar para no duplicar |
| Acceso a recurso de otro usuario (web o MCP) | 404/403 sin filtrar existencia; registrado |
| API key MCP inválida o revocada | 401; ninguna herramienta ejecuta |
| Métrica no disponible en una red | Se muestra «no disponible» en lugar de 0 |

## Criterios de aceptación

- [ ] [GWT] CA-01 — Dado un visitante, Cuando se registra e inicia sesión, Entonces accede a su panel vacío y solo ve sus propios datos.
- [ ] [GWT] CA-02 — Dado el usuario A con una marca, Cuando el usuario B solicita esa marca por la web, la API o el MCP, Entonces recibe 404/403 y no obtiene ningún dato.
- [ ] [GWT] CA-03 — Dado un usuario con una marca, Cuando conecta una cuenta de IG, FB, YouTube o TikTok vía OAuth, Entonces la cuenta aparece conectada en esa marca con su estado.
- [ ] [GWT] CA-04 — Dada una cuenta con token próximo a caducar, Cuando corre el job de refresco, Entonces el token se renueva sin intervención; si no es posible, la cuenta queda `requiere reconexión`.
- [ ] [GWT] CA-05 — Dado un vídeo que excede los límites de un reel de IG, Cuando el usuario intenta programarlo como reel, Entonces la app lo rechaza indicando el límite incumplido.
- [ ] [GWT] CA-06 — Dada una publicación programada válida para cualquiera de los tipos de contenido soportados, Cuando llega su hora, Entonces se publica en la red y su estado pasa a `publicada` con el enlace/ID externo.
- [ ] [GWT] CA-07 — Dado un error transitorio de la red al publicar, Cuando el worker reintenta, Entonces la publicación termina `publicada` o `fallida` con motivo, sin duplicados.
- [ ] [GWT] CA-08 — Dadas publicaciones programadas y publicadas, Cuando el usuario abre el calendario filtrado por marca y red, Entonces ve cada publicación en su fecha con su estado y puede reprogramar las pendientes.
- [ ] [GWT] CA-09 — Dada una cuenta conectada, Cuando corre el job diario, Entonces se guarda un snapshot de métricas de cuenta y de publicaciones recientes.
- [ ] [GWT] CA-10 — Dados snapshots de al menos 30 días, Cuando el usuario abre el dashboard de una marca, Entonces ve seguidores y crecimiento, alcance, impresiones y engagement con gráficos por red (o «no disponible» donde la API no lo dé).
- [ ] [GWT] CA-11 — Dado un rango de fechas, Cuando el usuario genera un informe de marca, Entonces puede descargarlo en PDF y en CSV con los datos de ese rango.
- [ ] [GWT] CA-12 — Dada una API key válida del usuario, Cuando un agente MCP lista marcas, programa/edita/borra una publicación, consulta el calendario, lee estadísticas y genera un informe, Entonces cada operación se ejecuta solo sobre datos de ese usuario.
- [ ] El sistema completo se despliega en el VPS con HestiaCP (dominio con TLS gestionado por Hestia, workers y scheduler persistentes) y la media se guarda en S3 compatible (R2 o MinIO).
- [ ] Los tokens OAuth se almacenan cifrados en reposo.

## Pruebas

- Unitarias: validación de specs de media por red/tipo; máquina de estados de publicación; normalización de métricas; cálculo de crecimiento.
- Integración: conectores por red contra mocks/grabaciones de respuestas (y pruebas manuales contra cuentas reales de prueba); cola y reintentos; refresco de tokens.
- Seguridad: tests de aislamiento entre usuarios en web, API y MCP (CA-02, CA-12).
- E2E: cada criterio [GWT] CA-01…CA-12 se traduce 1:1 a un bloque E2E del test-plan.
- Manual: publicación real de cada tipo de contenido en cuentas de prueba antes de cerrar cada fase.

## Referencias

- Requisitos confirmados con el usuario, transmitidos por `/pm-cycle` (2026-10-19).
- Meta Graph API — Instagram Content Publishing, Pages API, Insights (doc oficial; límites ⚠️ verificar en diseño).
- YouTube Data API v3 (`videos.insert`, cuotas) y YouTube Analytics API (doc oficial ⚠️ verificar).
- TikTok for Developers — Login Kit, Content Posting API, Display API (doc oficial ⚠️ verificar).
- Model Context Protocol — especificación y autorización (doc oficial ⚠️ verificar versión vigente).

## Decisiones confirmadas (revisión del usuario · 2026-10-19)

1. Uso personal, sin planes ni facturación, pero con registro y login. **Confirmado.**
2. Usuarios independientes: sin compartir marcas, equipos, roles ni aprobaciones; aislamiento estricto. **Confirmado.**
3. Jerarquía Usuario → Marcas → Cuentas sociales por OAuth. **Confirmado.**
4. Redes v1: Instagram, Facebook, YouTube, TikTok, con los tipos de contenido listados en «Alcance». **Confirmado.**
5. Snapshots diarios propios para histórico. **Confirmado.**
6. Informes exportables (PDF/CSV). **Confirmado.**
7. Servidor MCP con autenticación por usuario. **Confirmado.**
8. Despliegue en VPS gestionado con HestiaCP (sustituye a «Docker Compose»): Hestia controla los puertos 80/443, el TLS y las bases de datos; workers y scheduler deben ejecutarse como servicios persistentes (systemd/supervisor) o en Docker tras un proxy de Hestia. Media en S3 compatible (R2 o MinIO). **Confirmado.**
9. Stack no fijado; lo decide `architect`. **Confirmado.**

## Supuestos

- Las cuentas de Instagram son Business o Creator vinculadas (según el método de login elegido) — lo verifica el diseño de conexiones.
- Al ser de uso personal, las apps de Meta pueden operar con los usuarios que tengan rol en la app sin App Review completo — ⚠️ verificar en la fase de conexiones.
- Sin auditoría, TikTok y YouTube pueden dejar las publicaciones como privadas — ⚠️ verificar; condiciona el valor real de la publicación automática en esas redes.
- Las métricas disponibles difieren entre redes; el dashboard muestra lo que cada API ofrezca, sin inventar equivalencias.
- Volumen bajo (uso personal): decenas de publicaciones/semana y pocas marcas por usuario; no se diseña para escala SaaS.
