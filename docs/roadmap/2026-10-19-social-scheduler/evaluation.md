---
generacion:
  inicio: 2026-10-19T14:52:49Z
  fin: 2026-10-19T14:58:58Z
  fuente: medido          # incluye también la redacción de spec.md (misma ventana, sin solapar marcadores)
  tokens_reales: { entrada: 22, salida: 39766, cache_creacion: 84546, cache_lectura: 326685 }
  eur: null             # precioTokens no fiable (no hay .claude/rates.json) — ejecuta rates-verify
  horas_ia: 0.41
  duracion: 25m
  ratio_usado: 300000          # default no calibrado (no existe CALIBRATION.md)
---

# 2026-10-19-social-scheduler

> Evaluación y presupuesto de una aplicación web personal tipo Metricool (programación, publicación automática, analítica, reporting y servidor MCP sobre IG, FB, YouTube y TikTok), dividida en 6 fases entregables por separado, para decidir go/no-go y el orden de construcción.

| | |
|---|---|
| **Fecha** | 2026-10-19 |
| **Estado** | completado |
| **Prioridad global** | Alta 🟠 |
| **Solicitante** | Usuario del proyecto (vía `/pm-cycle`) |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) (borrador) |
| **Características evaluadas** | 6 |

---

## 📊 Cuadro de mando

| Métrica | Total estimado | Confianza |
|--------|----------------|-----------|
| Esfuerzo humano | **606h** (505h base +20 %) | Media-Baja |
| Tiempo IA (ejecución) | **92h** (+ 23h supervisión + 73h revisión/QA manual y trámites de plataforma) | Baja |
| Coste | **30.300 € + tokens IA** (tokens ⚠️ verificar precio) | Media (parte humana) |
| Tokens IA | **~23,0M facturables** (in 20,9M / out 2,09M) | Baja |
| Multiplicador productividad | **×3,2** | — |
| Características | **6** | — |

> Las horas humanas (lo que costaría a una persona: justifican el valor) y las horas-IA (lo que tardaría el ciclo con agentes: fijan el plazo) son magnitudes distintas y **no se suman** (doctrina LES-007).

---

## 🧭 Resumen ejecutivo

Llegan los requisitos confirmados de una aplicación personal multiusuario (aislamiento estricto, sin billing) para gestionar 4 redes sociales: conexión OAuth, publicación automática de 13 tipos de contenido, calendario, analítica con histórico propio, informes PDF/CSV y un servidor MCP para agentes. Se presupuesta en **6 fases** con un total de **~606 h humanas equivalentes (30.300 € a 50 €/h) más tokens**, o **~188 h** de calendario con agentes de IA más supervisión. El grueso del coste y del riesgo está en **C-03 (publicación)** y en las **dependencias externas de plataformas** (Meta App Review, auditoría de TikTok, verificación y cuota de YouTube), no en el código propio. Esta evaluación soporta la decisión de construir por fases, empezando por un spike de viabilidad de plataformas antes de invertir en la publicación.

---

## 📥 Requerimientos recibidos

Mapa de la spec ([`spec.md`](spec.md)) a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | Núcleo: auth, usuarios, marcas, aislamiento, infraestructura | spec §Alcance (registro/login, marcas, aislamiento) · §Decisiones (VPS + Docker Compose) | ✅ claro |
| C-02 | Conexiones OAuth por red (IG, FB, YouTube, TikTok) | spec §Flujo paso 3 · §Configuración (refresco de tokens) · §Supuestos | ⚠️ ambiguo (acceso real a las APIs sin review/auditoría) |
| C-03 | Publicación programada + validación de media + calendario | spec §Alcance (13 tipos de contenido, cola/workers/reintentos) · CA-05…CA-08 | ⚠️ ambiguo (límites exactos de media y comportamiento de cuotas) |
| C-04 | Analítica + snapshots diarios + dashboards por marca | spec §Alcance (estadísticas, snapshots) · CA-09, CA-10 | ⚠️ ambiguo (métricas disponibles por red; «impresiones» en IG) |
| C-05 | Reporting por rango de fechas (PDF/CSV) | spec §Alcance · CA-11 | ⚠️ ambiguo (contenido y diseño del PDF no definidos) |
| C-06 | Servidor MCP con autenticación por usuario | spec §Alcance · CA-12 | ✅ claro (salvo elección API key vs. OAuth) |

**Ambigüedades / información que falta:**

- **Restricción nueva (2026-10-19): VPS con HestiaCP.** Favorece un stack que encaje con lo que Hestia gestiona de serie (Nginx/PHP-FPM, MariaDB/PostgreSQL, cron, Let's Encrypt), p. ej. Laravel; un stack Node/Docker es viable tras un proxy de Hestia. Lo decide `architect`; las cifras no cambian materialmente (±15 % ya contemplado).
- **Stack sin fijar** → supuesto de evaluación: **TypeScript full-stack** (p. ej. Next.js/React + API Node + PostgreSQL + Redis/BullMQ o pg-boss + S3 SDK + SDK MCP de TypeScript). La decisión final es de `architect`; un stack distinto mueve las cifras ±15 %.
- **Acceso de producción a las APIs**: no está confirmado si basta el modo desarrollo de las apps (uso personal) o si hace falta App Review / auditoría / verificación. Condiciona si la publicación en TikTok y YouTube es pública o queda privada.
- **Diseño del informe PDF** (secciones, branding) no definido → supuesto: plantilla única por marca con KPIs + gráficos + tabla de publicaciones.
- **Autenticación MCP**: «token/API key u OAuth» → supuesto: **API key personal** en v1; OAuth 2.1 del MCP como ampliación opcional (+12–16 h).
- **Notificaciones** de fallos de publicación (email, in-app): no especificadas → supuesto: aviso in-app y email simple.
- **Zonas horarias**: supuesto de una zona por usuario/marca configurable.
- **Volumen**: supuesto de uso personal (decenas de publicaciones/semana); no se dimensiona para escala SaaS.

---

## 📋 Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades (funcionales confirmados; quedan las ambigüedades listadas arriba)
- [x] **Alcance** de cada característica acotado (qué entra y qué NO) — ver spec §Alcance
- [x] **Criterios de aceptación / éxito** por característica — CA-01…CA-12 en la spec
- [ ] **Restricciones** (deadline, presupuesto, compliance, técnicas) — sin deadline ni presupuesto declarados
- [ ] **Dependencias externas** (equipos, proveedores, APIs) identificadas — identificadas pero **sin verificar** su acceso real (App Review, auditoría TikTok, verificación Google)
- [ ] **Contexto técnico** del proyecto (stack, integraciones) disponible — greenfield, stack por decidir (`architect`)
- [ ] **Tarifa/hora y supuestos de coste** confirmados — se usan defaults (no existe `.claude/rates.json`)

---

## 💶 Supuestos económicos (ajustables)

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**.

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default del fragmento compartido (no hay `.claude/rates.json`) |
| Modelo IA asumido | claude-opus-4-8 | Default del fragmento compartido |
| Precio input | ⚠️ verificar € / 1M tokens | No hay precio fiable configurado; ejecuta la skill `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem; coste tokens = (20,9M × P_in + 2,09M × P_out) |
| Tipo de cambio | 1 USD = 0.92 € | Default |
| Margen de contingencia | 20 % | Sobre horas base (humanas e IA) |
| Ratio de supervisión | 25 % de las horas IA | Default |
| Ratio tokens→hora-IA | 300.000 tok/h ⚠️ no calibrado | No existe `docs/roadmap/CALIBRATION.md`; en el histórico del plugin las horas-IA se han **sobreestimado** (−77 % a −96 %), así que el plazo IA real probablemente sea menor |
| Horas por FTE | 160 h/mes | Default |

**Método de estimación de horas humanas:** descomposición por componentes (pantallas, endpoints, conectores × tipos de contenido, jobs) con un desarrollador senior full-stack que conoce TypeScript pero no las APIs concretas; incluye tests y documentación técnica mínima. **Tokens:** ~40–45k tokens facturables de entrada por hora humana base de código de producto y ratio salida/entrada ≈ 10 % (supuesto, sin calibrar). **Horas-IA** = tokens facturables ÷ 300.000.

---

## 🔍 Evaluación por característica

### C-01 — Núcleo: auth, usuarios, marcas, aislamiento e infraestructura

- **Requisito origen**: spec §Alcance (registro, login, aislamiento), §Decisiones (jerarquía, despliegue), CA-01, CA-02.
- **Descripción**: esqueleto del proyecto (monorepo, CI, lint/test), Docker Compose (app, workers, PostgreSQL, Redis, MinIO, proxy TLS), registro/login/sesión/recuperación de contraseña, CRUD de marcas y capa de servicios con aislamiento por `user_id` reutilizable por web, API y MCP.
- **Complejidad**: Media
- **Esfuerzo**: 60h base → **72h** con margen · confianza Alta
- **Previsión IA**: 2,5M in / 0,25M out tok · ⚠️ verificar € · 9,2h-IA base (11,0h con margen)
- **Coste**: (72h × 50 €/h) + tokens = **3.600 € + tokens**
- **Impacto / áreas afectadas**: `infra/docker-compose`, `apps/web` (auth, marcas), `packages/domain` (servicios, guardas de tenencia), `db/migrations`
- **Dependencias y prerequisitos**: decisión de stack (`architect`); VPS con dominio y TLS.
- **Riesgos**: un fallo de aislamiento se propaga a todo lo demás (mitigación: guarda obligatoria en la capa de servicio + tests de aislamiento desde el día 1; opcional RLS); deriva de alcance en auth (2FA, OAuth social login) no pedida.
- **Incógnitas / preguntas abiertas**: ¿email transaccional (SMTP propio o proveedor) para recuperar contraseña? ¿2FA deseado?

### C-02 — Conexiones OAuth por red

- **Requisito origen**: spec §Flujo paso 3, §Configuración (refresco), §Manejo de errores (token caducado), CA-03, CA-04.
- **Descripción**: flujos OAuth para Meta (IG Business/Creator + páginas de FB), Google (YouTube) y TikTok; selección de página/cuenta/canal; tokens cifrados en reposo; job de refresco; estado `requiere reconexión`; desconexión. Incluye un **spike de viabilidad de plataformas** al inicio (8–12h) y la preparación de apps de desarrollador.
- **Complejidad**: Alta
- **Esfuerzo**: 70h base → **84h** con margen · confianza Media
- **Previsión IA**: 3,0M in / 0,3M out tok · ⚠️ verificar € · 11,0h-IA base (13,2h con margen)
- **Coste**: (84h × 50 €/h) + tokens = **4.200 € + tokens**
- **Impacto / áreas afectadas**: `packages/connectors/{meta,google,tiktok}`, `apps/web` (pantalla de conexiones), `workers/token-refresh`, tabla `social_accounts`
- **Dependencias y prerequisitos**: C-01; cuentas de desarrollador en Meta, Google Cloud y TikTok; cuentas IG Business/Creator vinculadas a página de FB; dominio con HTTPS para los redirect URIs.
- **Riesgos**: **Meta App Review** para `instagram_content_publish`, `pages_manage_posts`, `pages_read_engagement`, `instagram_manage_insights`/`read_insights` (en modo desarrollo solo funcionan cuentas con rol en la app — puede bastar para uso personal ⚠️ verificar); **Google OAuth en estado «Testing»** emite refresh tokens que caducan a los 7 días ⚠️ verificar → obliga a publicar/verificar la app; **TikTok** tokens de acceso de corta vida (≈24h) con refresh largo ⚠️ verificar; tokens de Meta de larga duración (~60 días) que hay que renovar.
- **Incógnitas / preguntas abiertas**: ¿se acepta operar en modo desarrollo/testing indefinidamente? ¿login de IG vía Facebook Login o vía «Instagram API with Instagram Login»? (cambia permisos y métricas disponibles).

### C-03 — Publicación programada, validación de media y calendario

- **Requisito origen**: spec §Alcance (13 tipos de contenido, cola + workers + reintentos + estados), §Manejo de errores, CA-05…CA-08.
- **Descripción**: subida de media a S3 (multipart para vídeo), validación de specs por red y tipo (dimensiones, duración, peso, códec, nº de elementos de carrusel), compositor por red/tipo, cola persistente con workers, máquina de estados (`borrador → programada → publicando → procesando → publicada/fallida`), reintentos con backoff e idempotencia, polling del procesamiento asíncrono (contenedores de IG, `publish_status` de TikTok, procesamiento de YouTube), 13 flujos de publicación y calendario por marca/red con reprogramación.
- **Complejidad**: Muy alta
- **Esfuerzo**: 180h base → **216h** con margen · confianza Baja
- **Previsión IA**: 7,5M in / 0,75M out tok · ⚠️ verificar € · 27,5h-IA base (33,0h con margen)
- **Coste**: (216h × 50 €/h) + tokens = **10.800 € + tokens**
- **Impacto / áreas afectadas**: `packages/media` (validación, S3), `packages/connectors/*` (publish), `workers/publisher`, `apps/web` (compositor, calendario), tablas `posts`, `post_targets`, `media_assets`, `publish_attempts`
- **Dependencias y prerequisitos**: C-01, C-02; bucket S3 con URL pública o firmada accesible por Meta/TikTok (IG y TikTok *PULL_FROM_URL* descargan desde URL; TikTok exige dominio verificado para pull ⚠️ verificar).
- **Riesgos**: **TikTok sin auditoría** → publicaciones solo privadas (`SELF_ONLY`) ⚠️ verificar; **YouTube**: cuota ~1.600 unidades por subida sobre 10.000/día (≈6 subidas/día) y proyectos API no verificados suben vídeos como **privados** hasta superar la auditoría ⚠️ verificar; **stories de IG** vía API sin stickers/enlaces/música y solo cuentas Business ⚠️ verificar; **reels de FB** y **shorts** con requisitos propios de formato; **vídeos grandes** (cientos de MB/GB): disco y ancho de banda del VPS, subidas reanudables a YouTube; límite de publicaciones por 24h en IG por API ⚠️ verificar; un VPS único es punto único de fallo para publicar a la hora exacta.
- **Incógnitas / preguntas abiertas**: ¿transcodificar vídeo en el servidor (ffmpeg) o solo validar y rechazar? (transcodificar añade ~20–30h); ¿política ante cuota agotada (reprogramar automáticamente o fallar)?; ¿publicación simultánea multi-red desde un mismo compositor o una publicación por red?

### C-04 — Analítica, snapshots diarios y dashboards por marca

- **Requisito origen**: spec §Alcance (estadísticas, snapshots), CA-09, CA-10.
- **Descripción**: colectores diarios por red (métricas de cuenta y por publicación), modelo de snapshots normalizado, cálculo de crecimiento y engagement, backfill inicial con el histórico que permita cada API, gráficos y dashboard por marca con vista por red.
- **Complejidad**: Alta
- **Esfuerzo**: 110h base → **132h** con margen · confianza Baja
- **Previsión IA**: 4,5M in / 0,45M out tok · ⚠️ verificar € · 16,5h-IA base (19,8h con margen)
- **Coste**: (132h × 50 €/h) + tokens = **6.600 € + tokens**
- **Impacto / áreas afectadas**: `packages/connectors/*` (insights), `workers/metrics-collector`, `packages/analytics` (normalización), `apps/web` (dashboards, gráficos), tablas `account_snapshots`, `post_metrics_snapshots`
- **Dependencias y prerequisitos**: C-02 (permisos de insights); C-03 para métricas por publicación de contenido programado desde la app (también puede leer publicaciones nativas).
- **Riesgos**: **histórico limitado** en las APIs (insights de IG/FB con ventanas cortas; la Display API de TikTok ofrece pocas métricas) → el valor llega con el tiempo; **deprecaciones de métricas** (Meta ha retirado métricas como `impressions` en favor de `views` en versiones recientes ⚠️ verificar) → el requisito «impresiones» puede no ser satisfacible en todas las redes; métricas heterogéneas entre redes (normalizar sin inventar equivalencias); YouTube Analytics API es otra API y otro scope.
- **Incógnitas / preguntas abiertas**: ¿qué hacer con métricas no disponibles (mostrar «no disponible»)?; ¿frecuencia de refresco de métricas por publicación reciente (p. ej. cada 6h las primeras 72h)?

### C-05 — Reporting por rango de fechas (PDF/CSV)

- **Requisito origen**: spec §Alcance (informes exportables), CA-11.
- **Descripción**: generación de informes por marca y rango de fechas con KPIs, gráficos y tabla de publicaciones; exportación CSV (datos) y PDF (render HTML → PDF con navegador headless o librería), generación en worker y descarga.
- **Complejidad**: Media
- **Esfuerzo**: 40h base → **48h** con margen · confianza Media
- **Previsión IA**: 1,6M in / 0,16M out tok · ⚠️ verificar € · 5,9h-IA base (7,0h con margen)
- **Coste**: (48h × 50 €/h) + tokens = **2.400 € + tokens**
- **Impacto / áreas afectadas**: `packages/reports`, `workers/report-generator`, `apps/web` (pantalla de informes), almacenamiento S3 de informes
- **Dependencias y prerequisitos**: C-04 (datos y gráficos reutilizables).
- **Riesgos**: render de gráficos en PDF (headless Chromium añade peso a la imagen Docker y memoria al VPS); deriva de alcance en diseño visual.
- **Incógnitas / preguntas abiertas**: secciones y branding del PDF; ¿informes programados/enviados por email? (no pedido; fuera de alcance).

### C-06 — Servidor MCP con autenticación por usuario

- **Requisito origen**: spec §Alcance (MCP), §Decisiones (acceso para IA), CA-12.
- **Descripción**: servidor MCP (transporte HTTP en el VPS) con herramientas para listar marcas/cuentas, crear/editar/borrar/reprogramar publicaciones, consultar calendario, leer estadísticas y generar informes; gestión de API keys personales (crear, revocar, hash en reposo) y tests de aislamiento. Reutiliza la capa de servicios de C-01…C-05.
- **Complejidad**: Media
- **Esfuerzo**: 45h base → **54h** con margen · confianza Media
- **Previsión IA**: 1,8M in / 0,18M out tok · ⚠️ verificar € · 6,6h-IA base (7,9h con margen)
- **Coste**: (54h × 50 €/h) + tokens = **2.700 € + tokens**
- **Impacto / áreas afectadas**: `apps/mcp-server`, `packages/domain` (reutilizado), tabla `api_keys`, `apps/web` (gestión de API keys)
- **Dependencias y prerequisitos**: C-01 (auth y servicios); cada grupo de herramientas depende de su fase (C-03 para publicaciones, C-04 para estadísticas, C-05 para informes) → se puede entregar **incrementalmente**.
- **Riesgos**: subida de media desde un agente (las herramientas MCP manejan mal binarios grandes → supuesto: el agente pasa una URL o un ID de media ya subida); cambios de la especificación MCP (autorización); exposición pública del endpoint (rate limiting, auditoría de acciones).
- **Incógnitas / preguntas abiertas**: ¿basta API key o se quiere OAuth 2.1 del MCP para clientes que lo exijan? (+12–16h); ¿transporte local (stdio) además del remoto?

---

## ⚖️ Comparativa

| # | Característica | Complejidad | Horas (con margen) | Coste € (sin tokens) | Tokens (in+out) | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-01 | Núcleo: auth, marcas, aislamiento, infra | Media | 72h | 3.600 € | 2,75M | Crítica 🔴 | Alta |
| C-02 | Conexiones OAuth (4 redes) | Alta | 84h | 4.200 € | 3,30M | Alta 🟠 | Media |
| C-03 | Publicación + media + calendario | Muy alta | 216h | 10.800 € | 8,25M | Alta 🟠 | Baja |
| C-04 | Analítica + snapshots + dashboards | Alta | 132h | 6.600 € | 4,95M | Media 🟡 | Baja |
| C-05 | Reporting PDF/CSV | Media | 48h | 2.400 € | 1,76M | Baja 🟢 | Media |
| C-06 | Servidor MCP | Media | 54h | 2.700 € | 1,98M | Media 🟡 | Media |
| | **Total** | | **606h** | **30.300 €** | **22,99M** | | |

---

## 💰 Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 505h × 50 €/h | 25.250 € |
| Margen de contingencia | +20 % sobre desarrollo base (101h) | 5.050 € |
| Tokens IA (input) | 20,9M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 2,09M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + design + plan) | ~250k tok facturables estimados (≈0,8h-IA); esta evaluación queda medida en `generacion:` | ⚠️ verificar |
| **Total estimado (con margen)** | 30.300 € + tokens | **30.300 € + tokens (⚠️ verificar precio)** |

> Coste externo no incluido: VPS, dominio y almacenamiento S3/R2 (decenas de €/mes en uso personal, ⚠️ verificar) y la posible ampliación de cuota de YouTube (gratuita pero sujeta a auditoría de Google).

---

## ⚡ Productividad IA (humano vs. IA)

Compara el esfuerzo **humano** estimado con el tiempo que tardaría un **agente de IA** en implementarlo (más la supervisión y el trabajo humano no delegable). Cifras aproximadas.

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 606 h (505 h base) |
| Horas IA (ejecución) | 92,0 h (76,7 h base) |
| Supervisión humana (25 % de horas IA) | 23,0 h |
| Revisión, QA manual y trámites de plataforma (no delegable) | 73,2 h (61 h base) |
| **Horas totales (IA + supervisión + no delegable)** | **188,2 h** |
| Horas ahorradas | 417,8 h |
| **Ahorro** | **68,9 %** |
| **Multiplicador de productividad** | **×3,2** |
| FTE equivalentes *(opcional)* | 2,6 |

> Horas mostradas **con el margen de contingencia (+20 %)** ya aplicado. La fila «no delegable» se presupuesta aparte siguiendo la doctrina LES-001/LES-009 (en código de producto la revisión es la partida grande): crear y configurar apps en Meta/Google/TikTok, App Review/auditorías, despliegue en VPS y **publicar de verdad cada uno de los 13 tipos de contenido en cuentas reales** (C-01 6h · C-02 20h · C-03 20h · C-04 8h · C-05 3h · C-06 4h, base). Sin esa fila el multiplicador sería ×5,3, pero no reflejaría el trabajo real. Las horas-IA usan el ratio no calibrado (300.000 tok/h); el histórico del plugin indica que suelen sobreestimarse.

---

## ✅ Recomendación

- **Veredicto**: **go condicionado** — construir por fases, condicionado a superar un **spike de viabilidad de plataformas** (8–12h, al inicio de C-02) que confirme: (a) que el modo desarrollo de Meta basta para publicar y leer insights de las cuentas propias; (b) qué ocurre en TikTok sin auditoría (¿solo privado?) y en YouTube con proyecto no verificado (¿solo privado?) y si el usuario acepta esa limitación o tramita auditoría/verificación; (c) el cupo diario de YouTube suficiente para su volumen. Si TikTok y YouTube solo pueden publicar en privado, la publicación automática en esas redes pierde buena parte de su valor y conviene decidirlo antes de invertir en C-03.
- **Quick wins** (bajo coste, alto valor): **C-06** (54h; convierte la app en algo operable por agentes en cuanto existen los servicios) y, dentro de C-04, **arrancar pronto el job de snapshots** (pocas horas) para empezar a acumular histórico aunque los dashboards lleguen después.
- **Costosas / a valorar**: **C-03** (216h, 36 % del total; riesgo concentrado en plataformas) y **C-04** (132h; métricas inciertas por red). Se puede recortar C-03 entregando primero IG + FB (mismo proveedor, Meta) y dejar YouTube y TikTok para una segunda iteración.
- **Orden sugerido**: **C-01 → C-02 (con spike) → C-03 → C-06a (MCP: marcas, publicaciones, calendario) → C-04 → C-05 → C-06b (MCP: estadísticas e informes)** — sigue las dependencias de datos (no hay analítica útil sin cuentas conectadas ni informes sin snapshots), entrega pronto el valor central (publicar) y adelanta el MCP porque reutiliza servicios ya hechos. El job de snapshots de C-04 puede adelantarse justo después de C-02.
- **Fuera de alcance recomendado**: transcodificación de vídeo en servidor, OAuth 2.1 del MCP (dejar API key en v1), informes programados por email, otras redes, inbox de comentarios.
- **Recomendación de diseño**: pasar por **`architect`** antes del plan — proyecto greenfield con stack sin fijar, cola/workers con requisitos de fiabilidad, aislamiento multiusuario, cuatro integraciones externas heterogéneas y autenticación del MCP.

---

## ⚠️ Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Meta App Review / permisos (`instagram_content_publish`, `pages_manage_posts`, insights) no concedidos o solo en modo desarrollo | Media | Alto | Spike inicial; operar en modo desarrollo con cuentas con rol en la app (uso personal) ⚠️ verificar; preparar screencasts y política de privacidad si se solicita review |
| TikTok Content Posting API sin auditoría → publicaciones solo privadas | Alta | Alto | Confirmarlo en el spike; solicitar auditoría o aceptar «borrador/privado» en TikTok; posponer TikTok si no compensa |
| YouTube: cuota (~1.600 u/subida, 10.000 u/día) y vídeos privados en proyectos no verificados; refresh tokens de 7 días en apps en «Testing» | Alta | Medio-Alto | Medir uso real de cuota; solicitar ampliación/auditoría; publicar la app OAuth; alertar ante reconexión necesaria |
| Histórico de insights limitado y deprecación de métricas («impresiones») | Alta | Medio | Snapshots diarios desde el primer día; mostrar «no disponible»; capa de normalización desacoplada por versión de API |
| Caducidad/revocación de tokens OAuth | Alta | Medio | Job de refresco con margen, cifrado en reposo, estado `requiere reconexión` y aviso al usuario |
| Stories de IG vía API con limitaciones (sin stickers/enlaces, solo Business) | Media | Bajo | Documentar la limitación en el compositor; validar antes de programar |
| Vídeos grandes: disco, ancho de banda y tiempo de subida desde el VPS | Media | Medio | Subida multipart directa a S3 desde el navegador; subidas reanudables a YouTube; límites por red validados antes de encolar |
| Fallo de aislamiento entre usuarios (web, API o MCP) | Baja | Alto | Guarda de tenencia única en la capa de servicio, tests de aislamiento obligatorios por endpoint/herramienta, opcional RLS |
| VPS único como punto de fallo (publicaciones perdidas si cae) | Media | Medio | Cola persistente; al arrancar, recuperar publicaciones vencidas; healthchecks y alertas; backups de la BD |
| Cambios de API de las plataformas durante el desarrollo | Media | Medio | Conectores aislados por red y versión de API fijada; tests con respuestas grabadas |

---

## ➡️ Siguiente paso

Para **ejecutar** lo aprobado: primero **`architect`** (stack, modelo de datos, cola/workers, aislamiento, auth del MCP) y después el agente **`planner`** (crea en `docs/roadmap/2026-10-19-social-scheduler/` el `improvement-plan.md` + `tasks.md`). Se proponen para planificar **las 6 características** en el orden sugerido, con el spike de viabilidad de plataformas como primera tarea de C-02 y punto de decisión antes de C-03. El `planner` heredará las horas y costes de esta evaluación por característica (no re-estima desde cero) y actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec al crear el plan.

---

## 📝 Changelog

- 2026-10-19 — Creación de la spec (`borrador`) y de esta evaluación (`en-revision`) por `evaluator`, orquestado por `/pm-cycle`.
