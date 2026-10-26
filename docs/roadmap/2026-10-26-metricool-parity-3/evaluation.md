---
generacion:
  inicio: 2026-10-26T20:22:50Z
  fin: 2026-10-26T20:40:56Z
  fuente: medido              # incluye también la redacción de spec.md (misma ventana, un solo marcador)
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-ba610b3ccfc04ea39d7d9603606a587b
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 2405, salida: 72532, cache_creacion: 235704, cache_lectura: 6264591 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 1.04
  duracion: 1h 2m         # reloj: 18m
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# 2026-10-26-metricool-parity-3

> Evaluación y presupuesto de la tercera ola de paridad con Metricool. Incluye:
>
> - dos redes nuevas, Bluesky y Threads;
> - calendario y analítica: vistas guardadas, agregado entre marcas y CSV;
> - extras del compositor: hashtags, GIFs, lenguaje natural y notas del inbox.
>
> Cada entrega se presupuesta por separado para que el usuario decida el alcance en la puerta go/no-go.

| | |
|---|---|
| **Fecha** | 2026-10-26 |
| **Estado** | completado |
| **Prioridad global** | Media |
| **Solicitante** | daycry (usuario del proyecto), vía `/pm-cycle` |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | pendiente (handoff a planner) |
| **Características evaluadas** | 4, en 9 entregas separables: C-01, C-02, C-03a, C-03b, C-03c, C-04a, C-04b, C-04c y C-04d |

---

## Cuadro de mando

| Métrica | Total estimado (las 4) | Confianza |
|--------|----------------|-----------|
| Esfuerzo humano | **295,2h** (246h base +20 %) | Media |
| Tiempo IA (ejecución) | **39,9h** (+ 10,0h supervisión) | Baja |
| Coste | **14.760 € + tokens** (tokens: ⚠️ verificar) | Media |
| Tokens IA | **9,96M** (in 8,97M / out 1,00M) | Baja |
| Multiplicador productividad | **×5,9** | — |
| Características | **4** (9 entregas) | — |

> Las horas humanas equivalentes (base del valor) y las horas IA (base del plazo) son magnitudes distintas y no se suman (LES-007). Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).
>
> En `metricool-parity-2` el ledger registra **8,45h IA reales** frente a **20,9h base estimadas**, un 40 %. Parte de esas horas son estimadas y no medidas (`2026-10-24-metricool-parity-2/tasks.md`, resumen). Es un indicio de que el plazo real será menor, no una calibración.

---

## Resumen ejecutivo

El usuario ha aprobado el alcance de la tercera ola: dos redes nuevas (Bluesky y Threads) y siete funciones del inventario que faltan. Todo junto suma **295,2h con margen, ≈ 14.760 € + tokens**. Las dos redes son el 48 % del coste (142,8h) y concentran el riesgo.

El recon da seis hallazgos que condicionan el coste:

1. **Añadir una red no es local.** Hay listas fijas de redes en rutas, IA, MCP, métricas, analítica, iconos y opciones del compositor, y tests que esperan 13 reglas de media (§Arquitectura de la spec). La primera red paga esa generalización (≈10h base). Por eso **Bluesky va antes que Threads**.
2. **Bluesky rompe el contrato de conector.** `Contracts/Connector.php` asume OAuth. La app password (decisión D1) exige un contrato de credenciales nuevo: es **riesgo arquitectónico**. Hay tres sorpresas verificadas en la documentación oficial:
   - el límite de **1.000.000 bytes por imagen**, que obliga a recomprimir;
   - el **email verificado** y el límite diario para subir vídeo;
   - los *facets* en bytes, sin los que no hay enlaces clicables.
3. **El OAuth de atproto es caro.** Exige PAR, DPoP con *nonces* del servidor y metadatos del cliente en una URL pública, todo verificado. Por eso se elige app password: unas 24h menos con margen. La documentación la reserva a «herramientas de un solo propósito», así que es una decisión del usuario (D1).
4. **Threads encaja en el patrón de Instagram** (contenedor, sondeo y publicación por URL pública) y en el contrato OAuth. Su incógnita no es el código sino la **configuración de Meta**: el ID y el secreto de Threads, el *tester* y si basta el modo desarrollo (D2). Su documentación se renderiza en el navegador y solo se ha podido verificar la lista de permisos, así que sus límites quedan ⚠️.
5. **Tenor cierra** (sin claves nuevas desde el 2026-01-13 y apagado el 2026-06-30, verificado): se usa **Giphy**. Ninguna red acepta `image/gif`, así que el GIF se publica como **MP4** por el camino de importación de parity-2. Los GIF de menos de 3 s no valen para Reels ni TikTok.
6. **C-03 y C-04 reutilizan mucho:**
   - el CSV con BOM y la neutralización ya existen en `ReportService::csv()`;
   - `AnalyticsService::summary()` ya agrega por red;
   - `AiAssistant` ya tiene límites y redacción de datos;
   - el inbox hace *upsert*, así que las notas en una tabla aparte sobreviven.

**Veredicto: go**, condicionado a que el usuario resuelva D1–D6. C-02 (Threads) puede esperar a D2 sin bloquear lo demás.

> **Decisiones del usuario (2026-10-26, puerta go):** **go** con alcance **completo**, en el orden recomendado (notas del inbox → vistas guardadas → Bluesky → analítica entre marcas → CSV → hashtags → lenguaje natural → **Threads al final** → GIFs). **D1:** app password de Bluesky guardada cifrada. **D2:** sí, el usuario configurará la app de Meta para Threads (ID, secreto y tester). **D3:** aceptado. **D4:** sí, creará la clave de Giphy y acepta la atribución. **D5:** hashtags **solo desde el histórico propio**, sin IA. **D6:** lenguaje natural con IA, sí.

---

## Requerimientos recibidos

Mapa de la petición de `/pm-cycle` (fase 1) y de la spec a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | Bluesky: conexión, texto, 1-4 imágenes, vídeo y métricas | Petición (1); bundle §Redes (`actions/bluesky/suggestions`); fuentes §1.3 (l. 88) y §1.6 (l. 105); spec CA-01..CA-06, CA-10 | Claro. Autenticación decidida (app password), a confirmar (D1). Límite de 300 grafemas y vídeo ⚠️ |
| C-02 | Threads: app de Meta, texto, imagen, vídeo, carrusel y métricas | Petición (2); bundle §Redes (`catalogs/threads/*`); fuentes §1.3 (l. 87) y §1.6 (l. 103); spec CA-07..CA-10 | Ambiguo en lo externo: app, revisión y límites ⚠️ (D2) |
| C-03 | Vistas guardadas, analítica entre marcas y CSV | Petición (3); bundle §Planificación y analítica (`scheduler/calendars`, `analytics/aggregation` y `api/csv/export/file`); spec CA-11..CA-13 | Claro. Filtros de un solo valor (supuesto) |
| C-04 | Hashtags, GIFs, lenguaje natural y notas del inbox | Petición (4); bundle §Compositor y §Inbox (`inbox/notes`); spec CA-14..CA-17 | Claro. Fuente de hashtags y de GIFs decididas; costes de API (D4–D6) |

**Ambigüedades / información que falta:**

1. **D1. Autenticación de Bluesky.** Se decide **app password guardada cifrada**. Motivos:
   - es un único usuario;
   - no hace falta publicar metadatos de cliente ni un JWKS;
   - no hay DPoP en cada llamada.

   Coste de la alternativa OAuth (cliente confidencial con PAR, DPoP y `private_key_jwt`): **+20h base (+24h con margen, +1.200 €)**. Riesgo de la elegida: que Bluesky retire las app passwords ⚠️ (la spec de OAuth ya dice que quiere retirar los permisos de transición equivalentes). El contrato se diseña para poder cambiarla.
2. **D2. Threads.** El usuario debe:
   - añadir en developers.facebook.com el caso de uso de la Threads API, en la app actual o en una nueva ⚠️ (no se ha podido verificar si se puede combinar con los casos de uso que ya usa);
   - anotar el ID y el secreto de la app de Threads;
   - registrar la URI de redirección;
   - invitar a su cuenta de Threads como *tester* y aceptar la invitación.

   Supuesto: **sin App Review** mientras sea uso propio ⚠️. Si Meta lo exigiera, C-02 se bloquea hasta la revisión (semanas) y se mantiene el código detrás de la configuración.
3. **D3. Bluesky y el vídeo.** La cuenta debe tener el **email verificado** (verificado en la documentación oficial). Hay un límite diario de vídeos por cuenta, de cifra no publicada ⚠️. ¿Acepta el usuario esa limitación?
4. **D4. Giphy.** El usuario crea la clave en developers.giphy.com: *beta*, gratuita, 100 llamadas por hora (verificado). Se suponen dos cosas que el usuario debe confirmar leyendo los ToS de Giphy ⚠️:
   - que la clave *beta* basta, sin pedir la de producción;
   - que los términos permiten **descargar el MP4 y republicarlo** en las redes.

   La atribución «Powered by GIPHY» es obligatoria (verificado).
5. **D5. Hashtags con IA.** La fuente decidida es el **histórico propio** (gratis y privada). ¿Se añade un botón «Sugerir con IA» como complemento? Supone **+5h base (+6h, 300 €)**, gasta el cupo diario del asistente y tokens de Anthropic, y envía a un tercero el texto del post. Hoy solo se envía el *brief*.
6. **D6. Lenguaje natural.** Cada propuesta es una llamada a la Messages API de Anthropic con la clave del usuario. Coste por llamada ⚠️, aunque es pequeño con `max_tokens` bajo. Comparte el límite diario. Una sola fecha por publicación; **varias fechas** (crear copias) quedan fuera (+6h base aprox.).
7. ⚠️ **No verificado en la documentación oficial.** Se comprueba antes del plan; si el *architect* entra, en su paso:
   - **Bluesky:** 300 grafemas, vídeo de 100 MB y 3 min, y que el PDS acepte un `rkey` TID propuesto por el cliente;
   - **Threads:** 500 caracteres, carrusel de 2 a 20, 250 publicaciones cada 24h, tamaños de imagen y vídeo, token de 60 días con refresco, métricas disponibles y si basta el modo desarrollo.

---

## Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades: la petición, el bundle, las fuentes públicas y el recon. Quedan D1–D6.
- [ ] **Alcance** de cada característica acotado: lo está en la spec (§Alcance), pero falta que el usuario elija qué entregas entran.
- [x] **Criterios de aceptación / éxito** por característica: CA-01..CA-17.
- [x] **Restricciones**:
  - Laravel 13 con Inertia/Vue 3, MySQL, Pest y Playwright;
  - `MEDIA_DISK=local` en HestiaCP, con workers de 120 s;
  - UI en español y paridad con el MCP.

  No hay deadline.
- [ ] **Dependencias externas** identificadas: atproto y Bluesky (verificadas en lo esencial), Threads API (casi todo ⚠️), Giphy (verificada) y Anthropic (ya integrada).
- [x] **Contexto técnico** del proyecto disponible: conectores, contratos, `ContentType`, `media_specs`, `Metric`, `AnalyticsService`, `CalendarService`, `ReportService`, `AiAssistant`, `InboxService`, el compositor y el MCP.
- [ ] **Tarifa/hora y supuestos de coste** confirmados: no existe `.claude/rates.json`. Se usan los defaults y los tokens quedan ⚠️ verificar.

---

## Supuestos económicos (ajustables)

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**.

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default compartido (`estimation-defaults.md`). No hay `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 | Base de la previsión de tokens |
| Precio input | ⚠️ verificar € / 1M tokens | Sin `rates.json`: ejecuta `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre las horas base (humanas e IA) |
| Ratio tokens→hora-IA | 300.000 tok/h | Default **no calibrado**: no existe `docs/roadmap/CALIBRATION.md` |
| Densidad tokens/hora humana | ≈ 40.500 tok por hora base, con un reparto 90/10 de entrada y salida | La misma de `metricool-parity-2` y las iniciativas anteriores. Es un indicio, no un histórico calibrado |
| Supervisión humana | 25 % de las horas IA | Default. En UI la revisión visual del usuario pesa más (LES-009) |
| Revisión de robustez | Partida propia en cada entrega | LES-001 y LES-009: la revisión es la partida grande |
| Referencia de una red completa | TikTok en `social-scheduler`: OAuth 10h, publicación 16h y métricas 10h base, sin la UI | `2026-10-19-social-scheduler/tasks.md` (T-14, T-27 y T-34). Hoy una red toca además el compositor por destino, presets, primer comentario, cuadrícula, campañas, mejores horas, informes y MCP |
| Costes de APIs de terceros | Bluesky gratis · Threads gratis · Giphy *beta* gratis · Anthropic según uso (D5, D6) ⚠️ | No entran en el presupuesto de desarrollo |

---

## Evaluación por característica

### C-01: Bluesky (red nueva)

- **Requisito origen**: petición (1); bundle §Redes; fuentes §1.3 y §1.6; spec CA-01..CA-06 y CA-10.
- **Descripción**: el proveedor `bluesky` con:
  - conexión por handle y app password (`createSession` y `refreshSession`), resolviendo el handle a su DID y su PDS;
  - tipos `bluesky_post` (texto y de 0 a 4 imágenes) y `bluesky_video`;
  - publicación con `uploadBlob` (imágenes recomprimidas con GD si pasan de 1.000.000 bytes), *facets* en bytes UTF-8, `langs` y `rkey` determinista;
  - vídeo por `video.bsky.app` con token de servicio y sondeo de `getJobStatus`;
  - métricas: seguidores y posts (*stocks*, sin flujos: `EXPECTED_FLOWS` vacío, como TikTok), y por post me gusta, compartidos (*reposts* más citas) y comentarios, con `views` = `null`.

  Incluye la **generalización de redes**, de la que se beneficia C-02.
- **Complejidad**: **Alta** (contrato nuevo, vídeo asíncrono, *facets* y la generalización).
- **Esfuerzo**: **78h** (65h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Contrato de credenciales, `BlueskyConnector` y `BlueskySession` (resolución del handle, sesión, refresco y recreación con la app password), ruta y formulario en Conexiones | 9h |
  | Tipos en `ContentType`, reglas en `media_specs` (300 grafemas ⚠️, 4 imágenes de 1 MB, vídeo ⚠️) y conteo de grafemas en `MediaValidator` y `NetworkCounters` | 5h |
  | Recompresión de imágenes > 1.000.000 bytes (GD, copia derivada) | 3h |
  | `BlueskyPublisher`: texto, `Facets` (URL, #tag y @mención resuelta a DID) e imágenes con `aspectRatio` | 6h |
  | Vídeo: token de servicio, `uploadVideo`, `getJobStatus` (`StatusPoller`), email no verificado y límite diario | 6h |
  | Idempotencia por `rkey` TID y `findPublished` con `getRecord`; errores traducidos | 3h |
  | `BlueskyInsights` (`getProfile` y `getPosts`) y mapa de métricas | 5h |
  | Generalización de redes: listas fijas en rutas, IA, MCP, `Metric`, `AnalyticsService`, `NetworkIcon`, `composerOptions`, pestañas del dashboard, informe PDF y tests de conteo | 10h |
  | Pest con `Http::fake` (CA-01..CA-06) | 8h |
  | E2E con axe (CA-01, CA-10) | 3h |
  | Documentación y revisión de robustez | 5h |
  | Smoke real con el usuario (conectar y publicar los tipos) | 2h |
  | **Total** | **65h** |

- **Previsión IA**: 2,37M in / 0,26M out tok (2,63M) · ⚠️ verificar €.
- **Coste**: (78h × 50 €/h) + tokens = **3.900 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Connectors/{Network,ConnectorRegistry}.php`, `app/Connectors/Contracts/*` y `app/Connectors/Bluesky/*` (nuevo);
  - `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/{ConnectionController,OAuth/*}.php` y `routes/web.php`;
  - `app/Enums/ContentType.php`, `config/{media_specs,social}.php`, `app/Services/{MediaValidator,PostService,AnalyticsService,AiAssistant}.php` y `app/Analytics/Metric.php`;
  - `app/Mcp/Tools/{GetStats,GetCalendar,ListAccounts}.php`;
  - `resources/js/Components/{NetworkIcon.vue,Composer/*}`, `resources/js/Composables/composerOptions.js`, `resources/js/Pages/{Brands/Connections,Dashboard/*}.vue`;
  - `tests/Unit/MediaValidator/MediaValidatorTest.php:35`.
- **Dependencias y prerequisitos**: ninguna funcional; D1 y D3. Debe ir **antes que C-02**, porque paga la generalización.
- **Riesgos**:
  - **Arquitectónico:** el contrato de conector deja de ser solo OAuth. Hay que separar las capacidades comunes (refresco y desconexión) sin romper Meta, Google ni TikTok. Se recomienda pasar por `architect`.
  - Que Bluesky retire las app passwords ⚠️: el contrato se aísla en `BlueskySession` para poder cambiar a OAuth (+24h).
  - **Subida de vídeo en un worker de 120 s:** `uploadVideo` es una sola petición ⚠️ y un vídeo de 100 MB necesita unos 10 Mbps de subida. Si no cabe, hay que ir por pasadas o bajar el tope.
  - La recompresión puede bajar la calidad: se limita a una copia derivada y se avisa.
  - Los *facets* en bytes con emojis compuestos son una fuente típica de errores: se cubren con tests de tabla.
- **Incógnitas / preguntas abiertas**: D1 y D3. Los ⚠️ de los límites (300 grafemas, vídeo y `rkey`). ¿Texto alternativo de las imágenes? Hoy no existe en ninguna red del proyecto: fuera (+5h aprox.).

### C-02: Threads (red nueva)

- **Requisito origen**: petición (2); bundle §Redes; fuentes §1.3 y §1.6; spec CA-07..CA-10.
- **Descripción**: el proveedor `threads`, aparte de `meta`, con:
  - OAuth en `threads.net`, canje a un token de larga duración y refresco ⚠️;
  - permisos `threads_basic`, `threads_content_publish` y `threads_manage_insights`;
  - tipos `threads_text` (500 caracteres ⚠️, enlace), `threads_image`, `threads_video` y `threads_carousel` (de 2 a 20 ⚠️);
  - publicación por contenedor, igual que Instagram: creación, estado (`FINISHED` o `ERROR`) y `threads_publish`, con la media por URL pública;
  - límite de 250 publicaciones cada 24h ⚠️ con `QuotaPolicy`;
  - métricas por post (visualizaciones, me gusta, respuestas, *reposts*, citas y compartidos) y por cuenta (visualizaciones diarias y seguidores) ⚠️.
- **Complejidad**: **Alta**, más por la dependencia externa que por el código, que es Media gracias al patrón de Instagram.
- **Esfuerzo**: **64,8h** (54h base) · confianza Media-Baja (casi todo lo externo es ⚠️). Desglose base:

  | Partida | Horas base |
  |---|---|
  | Verificar los ⚠️ de la Threads API (límites, tokens y métricas) y guía de configuración de la app y del *tester* | 2h |
  | `ThreadsConnector` (OAuth, token largo y refresco), `ThreadsClient` (host `graph.threads.net`) y registro | 7h |
  | Tipos en `ContentType` y reglas en `media_specs` (texto, imagen, vídeo y carrusel) | 3h |
  | `ThreadsPublisher`: contenedores, carrusel con hijos, sondeo de estado, `threads_publish` y errores traducidos | 9h |
  | Idempotencia (`findPublished` sobre `/me/threads` recientes) y límite de publicación con `QuotaPolicy` | 5h |
  | `ThreadsInsights`: post, cuenta y rango si lo admite ⚠️ | 7h |
  | Superficies (icono, pestañas, MCP, IA, informe); la generalización ya la hizo C-01 | 5h |
  | Pest con `Http::fake` (CA-07..CA-09) | 7h |
  | E2E con axe (CA-10) | 2,5h |
  | Documentación y revisión de robustez | 4,5h |
  | Smoke real con el usuario (OAuth, *tester* y publicar los 4 tipos) | 2h |
  | **Total** | **54h** |

- **Previsión IA**: 1,97M in / 0,22M out tok (2,19M) · ⚠️ verificar €.
- **Coste**: (64,8h × 50 €/h) + tokens = **3.240 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Connectors/Threads/*` (nuevo), `app/Connectors/Network.php`, `app/Providers/AppServiceProvider.php` y `config/{social,services,media_specs}.php`;
  - `app/Enums/ContentType.php` y `app/Services/QuotaPolicy.php`;
  - las mismas superficies que C-01.
- **Dependencias y prerequisitos**: **C-01** (generalización) y **D2** (app y *tester* de Meta). Sin D2 se puede programar todo con `Http::fake`, pero no se puede hacer el smoke.
- **Riesgos**:
  - **Revisión de Meta:** si el modo desarrollo no bastara, C-02 queda bloqueada semanas ⚠️.
  - Los límites y las métricas no se han podido verificar, porque la documentación se renderiza en el navegador ⚠️. Se presupuesta una partida para verificarlos antes del plan.
  - Los contenedores de vídeo pueden tardar o caducar: se reutiliza el sondeo de IG.
  - La cuenta de Threads depende de la de Instagram: si se desconecta en Meta, el token cae. `RefreshTokens` la marca `requiere_reconexion`.
- **Incógnitas / preguntas abiertas**: D2. ¿Responder a las respuestas de Threads desde el inbox? Hoy queda fuera (+12h aprox., más permisos).

### C-03: Calendario y analítica

- **Requisito origen**: petición (3); bundle §Planificación y analítica; spec CA-11..CA-13.
- **Descripción**: tres entregas.
  - **C-03a. Vistas guardadas:**
    - tabla `calendar_views` (`user_id`, `name` y `filters` en JSON con marca, red, estado y vista);
    - CRUD, y en `Calendar/Index.vue` un selector de vistas con «Guardar vista», renombrar y borrar;
    - MCP: `list_calendar_views` y el parámetro `view` en `get_calendar`.
  - **C-03b. Analítica entre marcas:**
    - `AggregateAnalytics` llama a `AnalyticsService::summary()` por cada marca y suma con las reglas de `null`;
    - da los totales por métrica, una tabla por marca (seguidores, visualizaciones, interacciones y tasa) y el desglose por red;
    - entrada «Todas las marcas» en el dashboard; `get_stats` admite no recibir marca.
  - **C-03c. CSV:**
    - `Support/CsvWriter`, extraído de `ReportService::csv()` (BOM, separador por *locale* y neutralización CWE-1236) sin cambiar su salida;
    - descargas síncronas de la analítica (marca o todas) y de la lista de publicaciones del filtro activo (fecha, marca, redes, estado, texto, enlace y métricas del último *snapshot*), con un tope de filas.
- **Complejidad**: C-03a **Baja** · C-03b **Media** · C-03c **Media-Baja**.
- **Esfuerzo**: **67,2h** (56h base) · confianza Media-Alta. Desglose base:

  | Entrega | Partida | Horas base |
  |---|---|---|
  | C-03a | Migración, modelo y `UserScope` | 1h |
  | C-03a | `CalendarViewService`, controlador y validación (marca y red propias) | 2,5h |
  | C-03a | UI en el calendario: selector, guardar, renombrar, borrar y vista por defecto | 4h |
  | C-03a | MCP: `list_calendar_views` y `view` en `get_calendar` | 2h |
  | C-03a | Pest (CA-11), E2E con axe, documentación y revisión | 6,5h |
  | | **Subtotal C-03a** | **16h** |
  | C-03b | `AggregateAnalytics`: suma por métrica con `null`, *stocks* frente a flujos, tabla por marca, por red y serie | 6h |
  | C-03b | Página «Todas las marcas» (tarjetas, tabla y gráfica con `LineChart`) y selector en el dashboard | 6h |
  | C-03b | MCP `get_stats` sin marca | 2h |
  | C-03b | Pest (CA-12), con regresión del dashboard por marca | 3h |
  | C-03b | E2E con axe, documentación y revisión | 5h |
  | | **Subtotal C-03b** | **22h** |
  | C-03c | Extraer `CsvWriter` con la regresión del CSV de informes | 3h |
  | C-03c | CSV de la analítica (marca y todas) | 3h |
  | C-03c | CSV de publicaciones desde la vista Lista (filtros, vista guardada, métricas y tope) | 5h |
  | C-03c | Pest (CA-13), E2E de la descarga, documentación y revisión | 7h |
  | | **Subtotal C-03c** | **18h** |
  | | **Total C-03** | **56h** |

- **Previsión IA**: 2,04M in / 0,23M out tok (2,27M; C-03a 0,65M · C-03b 0,89M · C-03c 0,73M) · ⚠️ verificar €.
- **Coste**: (67,2h × 50 €/h) + tokens = **3.360 € + tokens**:

  | Entrega | Horas | Coste |
  |---|---|---|
  | C-03a | 19,2h | 960 € |
  | C-03b | 26,4h | 1.320 € |
  | C-03c | 21,6h | 1.080 € |

- **Impacto / áreas afectadas**:
  - `database/migrations`, `app/Models/CalendarView.php` y `app/Services/{CalendarViewService,AggregateAnalytics,ReportService}.php`;
  - `app/Support/CsvWriter.php` y `app/Http/Controllers/{CalendarController,DashboardController,ExportController}.php`;
  - `app/Mcp/Tools/{GetCalendar,GetStats,ListCalendarViews}.php`;
  - `resources/js/Pages/{Calendar/Index,Dashboard/*}.vue`.
- **Dependencias y prerequisitos**: ninguna. C-03c reutiliza los filtros de C-03a si va después. Las tres valen para cualquier red, así que incluyen solas a Bluesky y Threads.
- **Riesgos**:
  - **Zona horaria entre marcas:** los *snapshots* son días UTC y cada marca tiene la suya. El agregado usa días UTC, como los *snapshots*, y lo rotula.
  - Rendimiento con muchas marcas: `summary()` por marca es N consultas. Basta para uso personal y se cachea por periodo.
  - Extraer `CsvWriter` puede cambiar el CSV de informes: se mitiga con un test de igualdad byte a byte antes y después.
  - El alcance sumado entre marcas no es de usuarios únicos: se rotula «acumulado».
- **Incógnitas / preguntas abiertas**: ¿filtros de varios valores en las vistas? Supuesto de un solo valor (+4h si no). ¿CSV en el MCP? No: `generate_report` ya da CSV y `get_calendar` da JSON.

### C-04: Compositor extra

- **Requisito origen**: petición (4); bundle §Compositor y §Inbox; spec CA-14..CA-17.
- **Descripción**: cuatro entregas.
  - **C-04a. Hashtags** desde el histórico propio:
    - `HashtagStats` extrae los `#tags` del texto de los destinos publicados de la marca (por red) y los cruza con el último *snapshot*;
    - ordena por la mejora de interacciones sobre la media de la marca, con un mínimo de 2 usos y caché de 1h;
    - en el compositor hay un menú con chips que se insertan en el cursor (reutiliza la inserción de los textos guardados) y respeta el máximo por red (IG 30 y Threads 1 ⚠️);
    - MCP `suggest_hashtags`.
  - **C-04b. GIFs de Giphy:**
    - un *proxy* en el servidor (la clave no va al navegador; caché y límite) con búsqueda, tendencias y clasificación `pg-13`;
    - el modal `GifPicker.vue` con «Powered by GIPHY»;
    - al elegir, se importa la versión MP4 con `ImportRemoteMedia` (hosts de Giphy en una lista permitida y `UrlGuard`) y `source = giphy`;
    - avisos de compatibilidad por red (duración mínima).
  - **C-04c. Lenguaje natural:**
    - `AiAssistant::proposeSchedule()` envía solo la frase redactada, la fecha actual, la zona de la marca y los nombres de las redes conectadas, y recibe un JSON (fecha y hora local, y redes);
    - el servidor valida: fecha futura, dentro de 1 año, cambio de hora y redes conectadas;
    - el campo «Escribe cuándo» de `ScheduleSection` enseña la propuesta y la aplica al confirmar;
    - comparte el límite diario.
  - **C-04d. Notas del inbox:**
    - tabla `inbox_comment_notes`, con alta y borrado;
    - en el inbox: hilo de notas bajo el comentario, insignia «con nota» y filtro;
    - MCP: notas en `list_comments` y `add_comment_note` (habilidad `inbox:write`).
- **Complejidad**: C-04a **Media** · C-04b **Media** · C-04c **Media** · C-04d **Baja**.
- **Esfuerzo**: **85,2h** (71h base) · confianza Media. Desglose base:

  | Entrega | Partida | Horas base |
  |---|---|---|
  | C-04a | `HashtagStats`: extracción (Unicode), cruce con *snapshots*, ranking y caché | 5h |
  | C-04a | Endpoint y UI de chips con inserción en el cursor y máximo por red | 5,5h |
  | C-04a | MCP `suggest_hashtags` | 1,5h |
  | C-04a | Pest (CA-14), E2E con axe, documentación y revisión | 6,5h |
  | | **Subtotal C-04a** | **18,5h** |
  | C-04b | `GiphyClient` y *proxy* (búsqueda, tendencias, caché, límite y errores) | 3h |
  | C-04b | `GifPicker.vue`: búsqueda, cuadrícula, teclado, atribución y estados vacío y de error | 5h |
  | C-04b | Importación del MP4 por `ImportRemoteMedia` y lista permitida de hosts de Giphy | 3h |
  | C-04b | Compatibilidad por red (duración mínima y avisos) | 2h |
  | C-04b | Pest (CA-15), E2E con axe, documentación y revisión | 7h |
  | | **Subtotal C-04b** | **20h** |
  | C-04c | `proposeSchedule()`: *prompt*, salida JSON, redacción y validación (zona, cambio de hora, fechas relativas y pasadas) | 5h |
  | C-04c | Endpoint y UI en `ScheduleSection` (propuesta, aplicar y descartar) | 5h |
  | C-04c | Pest con respuestas simuladas (CA-16): tabla de frases y zonas | 4h |
  | C-04c | E2E con axe, documentación y revisión | 4,5h |
  | | **Subtotal C-04c** | **18,5h** |
  | C-04d | Migración, modelo, servicio y controlador | 3h |
  | C-04d | UI en el inbox: hilo de notas, alta, borrado, insignia y filtro | 4h |
  | C-04d | MCP: notas en `list_comments` y `add_comment_note` | 2h |
  | C-04d | Pest (CA-17), E2E con axe, documentación y revisión | 5h |
  | | **Subtotal C-04d** | **14h** |
  | | **Total C-04** | **71h** |

- **Previsión IA**: 2,59M in / 0,29M out tok (2,88M; C-04a 0,75M · C-04b 0,81M · C-04c 0,75M · C-04d 0,57M) · ⚠️ verificar €.
- **Coste**: (85,2h × 50 €/h) + tokens = **4.260 € + tokens**:

  | Entrega | Horas | Coste |
  |---|---|---|
  | C-04a | 22,2h | 1.110 € |
  | C-04b | 24h | 1.200 € |
  | C-04c | 22,2h | 1.110 € |
  | C-04d | 16,8h | 840 € |

- **Impacto / áreas afectadas**:
  - `app/Services/{HashtagStats,AiAssistant,InboxService,InboxNoteService}.php`, `app/Services/Media/{GiphyClient,UrlGuard}.php` y `app/Jobs/ImportRemoteMedia.php`;
  - `app/Http/Controllers/{AiAssistantController,InboxController,GifController,HashtagController}.php`, `database/migrations` y `app/Models/InboxCommentNote.php`;
  - `app/Mcp/Tools/{SuggestHashtags,ListComments,AddCommentNote}.php` y `app/Mcp/Abilities.php`;
  - `resources/js/Components/Composer/{EditorToolbar,TextSection,MediaSection,ScheduleSection,GifPicker}.vue`, `useComposer.js` y `resources/js/Pages/Inbox/Index.vue`.
- **Dependencias y prerequisitos**:
  - C-04b depende de la importación de parity-2 (ya en el repo) y de D4;
  - C-04c depende de que el asistente IA esté activo (`AI_ASSISTANT_ENABLED`) y de D6;
  - C-04a mejora con C-02, que aporta el máximo de 1 en Threads.
- **Riesgos**:
  - **Hashtags sin histórico.** Una marca nueva no tiene datos y se dice «aún no hay datos suficientes». El histórico se congela a los 30 días de publicar (`recent_post_days`), lo que basta para el ranking.
  - **El LLM interpreta mal la fecha.** Se mitiga con la validación en el servidor, la propuesta visible y una tabla de tests de frases.
  - **GIFs cortos.** Los de menos de 3 s no valen en Reels ni TikTok, y no hay ffmpeg para alargarlos: solo se avisa.
  - **Términos de Giphy** sobre republicar ⚠️ (D4).
  - **Privacidad.** El lenguaje natural envía la frase a Anthropic: se redacta igual que el *brief*.
- **Incógnitas / preguntas abiertas**: D4, D5 y D6.

---

## Comparativa

| # | Característica | Complejidad | Horas | Coste € | Tokens | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-01 | Bluesky | Alta | 78h | 3.900 € | 2,63M | Alta | Media |
| C-02 | Threads | Alta | 64,8h | 3.240 € | 2,19M | Media | Media-Baja |
| C-03a | Vistas de calendario guardadas | Baja | 19,2h | 960 € | 0,65M | Media | Alta |
| C-03b | Analítica entre marcas | Media | 26,4h | 1.320 € | 0,89M | Alta | Media |
| C-03c | Exportación CSV | Media-Baja | 21,6h | 1.080 € | 0,73M | Media | Media-Alta |
| C-04a | Sugerencias de hashtags | Media | 22,2h | 1.110 € | 0,75M | Media | Media |
| C-04b | Buscador de GIFs (Giphy) | Media | 24h | 1.200 € | 0,81M | Baja | Media |
| C-04c | Programar en lenguaje natural | Media | 22,2h | 1.110 € | 0,75M | Media | Media |
| C-04d | Notas internas del inbox | Baja | 16,8h | 840 € | 0,57M | Media | Alta |
| | **Total** | | **295,2h** | **14.760 €** | **9,96M** | | |

Por característica de la petición:

| Característica | Horas | Coste | Complejidad |
|---|---|---|---|
| C-01 | 78h | 3.900 € | Alta |
| C-02 | 64,8h | 3.240 € | Alta |
| C-03 | 67,2h | 3.360 € | Media |
| C-04 | 85,2h | 4.260 € | Media |

---

## Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 246h × 50 €/h | 12.300 € |
| Margen de contingencia | +20 % sobre desarrollo base (49,2h) | 2.460 € |
| Tokens IA (input) | 8,97M tok × precio | ⚠️ verificar |
| Tokens IA (output) | 1,00M tok × precio | ⚠️ verificar |
| Coste de proceso (LES-008) | Spec y evaluación (medidas en `generacion:`), más el diseño de C-01 y C-02 y el plan: ≈ 2h IA. Referencia: 0,4–1,2h por artefacto en las iniciativas anteriores | tokens ⚠️ verificar |
| **Total estimado (con margen)** | | **14.760 € + tokens** |

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 295,2 h |
| Horas IA (ejecución) | 39,9 h (33,2h base) |
| Supervisión humana | 10,0 h |
| **Horas totales (IA + supervisión)** | **49,8 h** |
| Horas ahorradas | 245,4 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 1,53 |

> Horas mostradas **con el margen de contingencia (+20 %)** ya aplicado. Horas IA = tokens × 1,2 / 300.000. Por característica: C-01 10,5h · C-02 8,8h · C-03 9,1h · C-04 11,5h.
>
> El ratio no está calibrado. En `metricool-parity-2` el real registrado fue un 40 % de lo estimado, y en `social-scheduler` entre un 8 % y un 13 %. El plazo real probablemente lo marcarán la revisión, el smoke del usuario y la configuración de Meta (D2), no la ejecución (LES-009).

---

## Recomendación

- **Veredicto**: **go**, condicionado a que el usuario resuelva D1–D6. C-02 se puede dejar para cuando D2 esté hecho sin bloquear el resto.
- **Quick wins** (bajo coste, alto valor):
  - **C-04d**, notas del inbox (16,8h);
  - **C-03a**, vistas guardadas (19,2h);
  - **C-03b**, analítica entre marcas (26,4h), muy útil con varias marcas.
- **Costosas / a valorar**:
  - C-01 y C-02, por la complejidad Alta y el riesgo externo;
  - C-04b, que aporta poco frente a su coste y depende de los términos de Giphy;
  - C-04c, cuyo valor depende de que el usuario programe escribiendo.
- **Orden sugerido**: **C-04d → C-03a → C-01 → C-03b → C-03c → C-04a → C-04c → C-02 → C-04b**. Motivos:
  - los dos quick wins primero, porque no tocan conectores;
  - C-01 pronto, porque no depende de nadie más que del usuario (app password) y paga la generalización que abarata C-02;
  - C-03b y C-03c después de C-01, para que el agregado y el CSV ya incluyan Bluesky en sus tests;
  - C-04a antes que C-04c, porque comparten el compositor y C-04a no necesita la IA;
  - C-02 cuando D2 esté hecho;
  - C-04b al final, porque es la de menos valor y depende de D4.

  | Ola | Entregas | Horas | Coste |
  |---|---|---|---|
  | A (sin dependencias externas salvo la app password) | C-04d, C-03a, C-01, C-03b y C-03c | 162h | 8.100 € |
  | B | C-04a, C-04c y C-02 (D2) | 109,2h | 5.460 € |
  | Opcional | C-04b (D4) | 24h | 1.200 € |
  | | **Total** | **295,2h** | **14.760 €** (cuadra con el total) |

- **Diseño**: pasar por `architect` antes del plan para dos cosas:
  - **C-01**: contrato de conector con credenciales, generalización de redes, `rkey` idempotente y subida de vídeo dentro del worker de 120 s;
  - **C-02**: proveedor aparte de `meta` y reutilización del patrón de contenedor de IG.

  El resto no lo necesita.
- **Fuera de alcance recomendado**:
  - el OAuth de atproto (salvo D1);
  - texto alternativo, hilos, encuestas y control de respuestas;
  - primer comentario e inbox en Bluesky y Threads;
  - hashtags con IA (salvo D5);
  - varias fechas en el lenguaje natural;
  - `image/gif` y *stickers*;
  - Tenor.

---

## Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| **Riesgo arquitectónico:** cambiar el contrato de conector (solo OAuth) para admitir credenciales rompe Meta, Google o TikTok | Media | Alto | Paso por `architect` en C-01. Contrato nuevo aditivo. Regresión completa de los tests de conectores |
| Alguna lista fija de redes queda sin actualizar y una red nueva sale a medias (filtro, icono, MCP o informe) | Alta | Medio | Inventario en la spec (§Arquitectura). Un test de humo que recorre `Network::cases()` en cada superficie |
| Meta exige App Review para Threads o cambia los límites ⚠️ | Media | Alto | D2 antes de empezar C-02. Código detrás de la configuración. Partida de verificación de 2h |
| Bluesky retira las app passwords | Baja | Medio | `BlueskySession` aislada. OAuth presupuestado como alternativa (+24h) |
| La subida de vídeo grande no cabe en el worker de 120 s (Bluesky) | Media | Medio | Tope de tamaño configurable y prueba en el smoke. Por pasadas si hiciera falta |
| Efectos externos en redes reales durante el desarrollo | Baja | Alto | Solo `Http::fake` en Pest y E2E. El smoke real lo hace el usuario |
| Romper la paridad web y MCP en los tipos y campos nuevos | Media | Medio | `PostPayload` reutiliza `PostRequest::rules()`. Pest de paridad en cada entrega |
| Costes variables de terceros (Anthropic por uso; Giphy si se pidiera la clave de producción) | Baja | Bajo | Límites diarios existentes. Clave *beta* de Giphy |
| El plazo lo marcan la revisión y el smoke del usuario, no la ejecución | Alta | Bajo | Revisión de robustez presupuestada por entrega (LES-001 y LES-009). Smoke por olas |

---

## Siguiente paso

Para **ejecutar** lo aprobado, genera el plan detallado con el agente **`planner`**, que crea `improvement-plan.md` y `tasks.md` en `docs/roadmap/2026-10-26-metricool-parity-3/`. Antes, pasa por `architect` para C-01 y C-02 si entran.

Indica qué entregas se aprueban (todas, la ola A o un subconjunto) y las respuestas a D1–D6. La secuencia es:

- C-01 antes que C-02;
- C-03a antes que C-03c, si van las dos;
- C-04a y C-04c en serie, porque comparten el compositor.

El `planner` heredará las horas y los costes de esta evaluación por entrega, sin re-estimar desde cero. Al crear el plan actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec.

---

## Changelog

- 2026-10-26: creación (estado `en-revision`), en la fase 1 de `/pm-cycle`. Spec en `borrador`. Puerta go/no-go pendiente del usuario.
