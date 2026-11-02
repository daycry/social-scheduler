---
spec: metricool-parity-3
descripcion: Tercera ola de paridad con Metricool para uso personal. Comprende dos redes nuevas (Bluesky y Threads), vistas de calendario guardadas, analítica agregada entre marcas, exportación CSV, sugerencias de hashtags, buscador de GIFs, programación en lenguaje natural y notas internas en el inbox.
estado: aprobada          # borrador | aprobada | implementada | obsoleta
creado: 2026-10-26
actualizado: 2026-10-27    # cambios derivados de design.md (ver §Decisiones confirmadas, punto 3)
evaluacion: evaluation.md
design: design.md         # aprobado 2026-10-27: C-01 = O1 (contrato segregado y catálogo de redes), C-02 = T1 (conector threads propio)
plan: improvement-plan.md
generacion:
  inicio: 2026-10-26T20:22:50Z
  fin: 2026-10-26T20:40:56Z
  fuente: estimado        # la redacción de esta spec está dentro de la ventana medida de evaluation.md (un solo marcador, sin solapes); ver su bloque generacion
  tokens_reales: { entrada: 0, salida: 0, cache_creacion: 0, cache_lectura: 0 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.00          # contabilizadas en evaluation.md
  duracion: 0m            # contabilizada en evaluation.md
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Paridad con Metricool, tercera ola

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Diseño:** [`design.md`](design.md) (aprobado el 2026-10-27: O1 para C-01 y T1 para C-02; corrige los topes de Bluesky y los datos de Threads de esta spec, ver §Decisiones confirmadas)
> **Plan de implementación:** [`improvement-plan.md`](improvement-plan.md)

> **Terminología:**
>
> - **Destino** (`PostTarget`): una cuenta más un tipo de contenido. Es la unidad que se publica, se reintenta y se mide.
> - **AT Protocol (atproto)**: el protocolo de Bluesky. Cada cuenta vive en un **PDS** (servidor de datos personal) y se identifica por un **DID**; el *handle* (`@usuario.bsky.social`) es un alias.
> - **App password**: contraseña específica de aplicación que el usuario crea en los ajustes de Bluesky. Se puede revocar y no da acceso a los mensajes directos.
> - **Contenedor** (Threads): objeto intermedio que se crea con la media y se publica después, igual que en Instagram.
> - **Vista guardada**: combinación con nombre de los filtros del calendario (marca, red y estado).
> - **Nota interna**: texto privado del usuario asociado a un comentario del inbox. Nunca se publica.

## Contexto y objetivo

Uso personal: un solo usuario con varias marcas y cuatro redes (Instagram, Facebook, YouTube y TikTok; `app/Connectors/Network.php:8-14`). Las olas anteriores ya están en `main` o cerradas:

- `2026-10-19-social-scheduler`: conectores, publicación, analítica, informes y MCP;
- `2026-10-23-post-composer`: compositor por destino;
- `2026-10-24-metricool-parity-2`: textos guardados, primer comentario, cuadrícula de Instagram, campañas e importación de media desde URL o Drive.

Esta spec recoge la fila 5 de la «Prioridad propuesta» de [`metricool-analisis-bundle.md`](../../referencia/metricool-analisis-bundle.md#prioridad-propuesta) (red nueva) y funciones del inventario que aún faltan:

- vistas personalizadas (`scheduler/calendars`);
- agregación entre marcas (`analytics/aggregation`);
- CSV (`api/csv/export/file`);
- hashtags (`actions/instagram/suggestions/hashtags`);
- GIF (`api/gifs/search`);
- lenguaje natural (`integrations/ai/natural-language-scheduling`);
- notas del inbox (`inbox/notes`).

Cada característica se evalúa y se aprueba por separado:

| ID | Característica | Entregas separables |
|---|---|---|
| C-01 | Bluesky (red nueva) | Una |
| C-02 | Threads (red nueva) | Una |
| C-03 | Calendario y analítica | C-03a vistas guardadas · C-03b analítica entre marcas · C-03c exportación CSV |
| C-04 | Compositor extra | C-04a hashtags · C-04b GIFs · C-04c lenguaje natural · C-04d notas del inbox |

## Decisiones de diseño

| Decisión | Elección | Motivo |
|---|---|---|
| Autenticación de Bluesky | **App password** (`com.atproto.server.createSession` y `refreshSession`), detrás de una clase de sesión que se pueda sustituir por OAuth | La documentación oficial reserva las app passwords a «aplicaciones de un solo propósito, como bots o herramientas de línea de comandos» y pide OAuth a las apps con login propio. Pero el OAuth de atproto exige PKCE, PAR, DPoP con *nonces* del servidor, un documento de metadatos del cliente en una URL HTTPS pública y, como cliente confidencial, firmar con una clave propia y publicar su JWKS. Para un único usuario es unas 24h más (con margen) sin ganancia funcional. Queda como D1 |
| Persistencia de la credencial de Bluesky | Se guardan la sesión (`accessJwt` y `refreshJwt`) y la app password, **cifradas** (cast `encrypted`, como los tokens actuales) | Si la sesión de refresco caduca, se vuelve a crear sin pedir nada al usuario. La app password se revoca desde Bluesky en cualquier momento |
| Contrato del conector | Contrato nuevo de credenciales junto al de OAuth, de modo que el registro, el refresco y la desconexión sigan siendo comunes | `Contracts/Connector.php:20-54` asume OAuth (`authorizationUrl` y `exchangeCode`). Bluesky necesita un formulario (handle y app password), no una redirección |
| Idempotencia de Bluesky | **`rkey` determinista** (un TID derivado del destino) en `com.atproto.repo.createRecord`; `findPublished` consulta ese registro con `getRecord` | Un reintento tras un *timeout* no duplica el post: el PDS rechaza la segunda escritura con la misma clave ⚠️ verificar que el PDS acepta un TID propuesto por el cliente |
| Vídeo de Bluesky | Flujo recomendado: token de servicio, `app.bsky.video.uploadVideo` en `video.bsky.app` y sondeo con `getJobStatus` (`StatusPoller`) | Es el recomendado por la documentación oficial. Con `uploadBlob` el vídeo se procesa después de aparecer el post |
| Imágenes de Bluesky > 2.000.000 bytes (`social.bluesky.image_max_bytes`, configurable) | **Recompresión automática** con GD a una copia derivada; el original no se toca | El lexicon vigente (`app.bsky.embed.images`) admite 2.000.000 bytes por imagen («antes 1 MB»), y una foto de móvil suele pesar entre 2 y 5 MB. Rechazarlas haría la red casi inutilizable. Corregido según `design.md` (2026-10-27) |
| Vídeo de Bluesky de más de unos 50 MB (`social.bluesky.video_max_bytes`, configurable) | **Se rechaza al guardar** con un mensaje claro | `uploadVideo` es una sola petición y el worker corta a los 120 s. El usuario prefiere un tope a cambiar el despliegue (`design.md`, 2026-10-27) |
| Texto de Bluesky | Máximo de 300 **grafemas** ⚠️, con *facets* (enlaces, menciones y hashtags) calculados en bytes UTF-8, e idioma (`langs`) por defecto `es` | Sin *facets* los enlaces y los hashtags no son clicables. El contador del compositor y el validador deben contar grafemas (extensión `intl`, ya instalada; `Intl.Segmenter` en el navegador) |
| App de Threads | **Proveedor `threads` aparte de `meta`**, con su ID y su secreto de app de Threads, y OAuth en `threads.com` | La Threads API usa otros hosts (`threads.com` y `graph.threads.com`, verificados en `design.md`), otros permisos (`threads_basic`, `threads_content_publish`, `threads_manage_insights`, `threads_read_replies` y `threads_manage_replies`) y otro canje de tokens (`th_exchange_token`, verificado). Separarlo evita que pedir un permiso que falta rompa el login de Meta, que ya falla con «Invalid Scopes» (`config/social.php:50-55`) |
| Revisión de Meta para Threads | **Sin App Review**: app en modo desarrollo y cuenta propia invitada como *tester* de Threads ⚠️ | El *spike* de 2026-10-19 demostró que el modo desarrollo basta en Instagram y Facebook para la cuenta propia (`social-scheduler/spike-report.md:15-20`). En Threads es un supuesto hasta probarlo (D2) |
| Publicación en Threads | Patrón de contenedor de Instagram: contenedor, espera o sondeo, y `threads_publish` | La media va por URL pública (`MediaAsset::publicUrl()`, como en IG). Se reutiliza la experiencia de `InstagramPublisher` |
| Fuente de los hashtags | **Histórico propio de rendimiento** (hashtags de lo publicado por la marca, cruzados con el último *snapshot* de cada destino) | Gratis, privado (no sale nada a terceros) y explicable con datos («usado 6 veces, +40 % de interacciones sobre la media»). Un complemento con IA queda como D5 |
| Proveedor de GIFs | **Giphy** | Google cierra la API de Tenor: no admite claves nuevas desde el 2026-01-13 y la apaga el 2026-06-30 (verificado en el aviso oficial). Giphy da claves *beta* de 100 llamadas por hora y exige la atribución «Powered by GIPHY» (verificado) |
| Formato del GIF publicado | Se importa la **versión MP4** de Giphy como vídeo, por el camino de importación de parity-2 (`ImportRemoteMedia` y `UrlGuard`) | Ninguna red del proyecto acepta `image/gif` en `config/media_specs.php`. El MP4 entra como cualquier vídeo y se valida por red |
| Programación en lenguaje natural | `AiAssistant` gana una operación que devuelve una **propuesta estructurada** (fecha y hora local, y redes) que el usuario confirma en el compositor; nunca programa por sí sola | Reutiliza la clave, el modelo, el límite diario y la redacción de datos de `app/Services/AiAssistant.php`. El LLM puede equivocarse con fechas relativas: la propuesta se valida en el servidor y se enseña antes de aplicarla |
| Notas del inbox | Tabla propia `inbox_comment_notes` (varias notas con fecha por comentario) | La sincronización hace *upsert* por `social_account_id` y `external_id` (`InboxService.php:117`). Una tabla aparte sobrevive a la resincronización |
| Paridad MCP | Herramientas o campos nuevos donde aportan datos (redes, tipos, vistas, analítica entre marcas, hashtags y notas). **No** en el lenguaje natural ni en el buscador de GIFs | El cliente MCP ya es un LLM que entiende fechas, y `import_media_from_url` ya importa un MP4 de Giphy |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| Servicio de identidad de Bluesky | `social.bluesky.entryway` | — | **`https://bsky.social`** (resolución del handle; luego se usa el PDS del DID) |
| Servicio de vídeo de Bluesky | `social.bluesky.video_service` | — | **`https://video.bsky.app`** |
| Idioma por defecto de los posts de Bluesky | `social.bluesky.default_langs` | — | **`['es']`** |
| App de Threads | `THREADS_APP_ID`, `THREADS_APP_SECRET` | vacío = la red no se ofrece | Las del usuario (D2) |
| Permisos de Threads | `social.threads.scopes` | — | **`threads_basic`, `threads_content_publish`, `threads_manage_insights`** (los de respuestas, fuera) |
| Margen de refresco de Threads | `social.refresh_margin_hours.threads` | — | **168h** (token de larga duración de 60 días ⚠️, como Meta) |
| Clave de Giphy | `GIPHY_API_KEY` | vacío = el botón GIF no aparece | La del usuario (D4) |
| Clasificación de los GIFs | `social.giphy.rating` | — | **`pg-13`** |
| Hashtags sugeridos | `social.hashtags.min_uses`, `social.hashtags.limit` | — | **2 usos**, **15 sugerencias** |
| Formato CSV | `reports.csv_locale` (existente) | `es` | **`;` y coma decimal**, UTF-8 con BOM, como los informes |

## Arquitectura y componentes

**Reutilizado:**

- `ConnectorRegistry`, `Publisher`, `StatusPoller`, `InsightsReader`, `PublishOutcomes`, `PublishPostTarget` y `PollPublishStatus`;
- `MediaValidator`, `config/media_specs.php`, `MediaAsset::publicUrl()`, `ImportRemoteMedia` y `UrlGuard`;
- `CalendarService`, `AnalyticsService`, `Calculator` y `ReportService::csv()` (se extrae el escritor);
- `AiAssistant`, `InboxService`, el compositor (`useComposer.js` y `Components/Composer/*`) y las herramientas MCP (`app/Mcp/Tools`).

**Nuevo:**

- **C-01:**
  - `app/Connectors/Bluesky/{BlueskyConnector,BlueskySession,BlueskyPublisher,BlueskyInsights,Facets}.php`;
  - el contrato de credenciales en `Contracts/`;
  - la ruta y el formulario de conexión;
  - los tipos `bluesky_post` (texto y de 0 a 4 imágenes) y `bluesky_video`.
- **C-02:**
  - `app/Connectors/Threads/{ThreadsConnector,ThreadsClient,ThreadsPublisher,ThreadsInsights}.php`;
  - los tipos `threads_text`, `threads_image`, `threads_video` y `threads_carousel`.
- **Común a C-01 y C-02**, la **generalización de redes**: hoy hay listas fijas de redes en `routes/web.php:56-58`, `AiAssistant.php:86`, `AiAssistantController.php:30`, `Mcp/Tools/{GetStats,GetCalendar}.php`, `Analytics/Metric.php:63-68`, `AnalyticsService.php:58-63`, `NetworkIcon.vue` y `composerOptions.js`, más los tests que esperan 13 reglas de media (`tests/Unit/MediaValidator/MediaValidatorTest.php:35`).
- **C-03:**
  - `calendar_views` y `CalendarViewService`;
  - `AggregateAnalytics`;
  - `Support/CsvWriter`, extraído de `ReportService::csv()`;
  - las descargas `exports/analytics.csv` y `exports/posts.csv`.
- **C-04:**
  - `HashtagStats`;
  - `GiphyClient` y el modal `GifPicker.vue`;
  - `AiAssistant::proposeSchedule()` y el campo «Escribe cuándo» en `ScheduleSection.vue`;
  - `inbox_comment_notes` y `InboxNoteService`.

## Flujo (paso a paso)

1. **Conectar Bluesky.** En Conexiones, «Conectar Bluesky» abre un formulario con el handle y la app password. El servidor resuelve el handle a su DID y su PDS, crea la sesión y guarda la cuenta cifrada. Si la cuenta no tiene el email verificado, avisa de que no podrá subir vídeo.
2. **Conectar Threads.** «Conectar Threads» redirige al OAuth de Threads. El servidor canjea el código por un token corto y después por uno de larga duración ⚠️, que el job `RefreshTokens` renueva.
3. **Componer.** Bluesky y Threads aparecen en el selector de redes con sus tipos, contadores (grafemas en Bluesky; en Threads, 500 caracteres con cada emoji contado por sus bytes UTF-8, según `design.md`) y reglas de media. Desde el compositor se puede:
   - insertar hashtags sugeridos por el histórico;
   - buscar un GIF en Giphy, que se importa como MP4;
   - escribir «el martes a las 9» para que la IA proponga la fecha.
4. **Publicar.**
   - **Bluesky:** sube los *blobs* (imágenes recomprimidas si pasan de 2.000.000 bytes, según `design.md`, o el vídeo por `video.bsky.app` con sondeo) y crea el registro con su `rkey` determinista.
   - **Threads:** crea el contenedor (o sus hijos, en un carrusel), espera a que esté listo y lo publica.
5. **Medir.**
   - **Bluesky:** seguidores y número de posts (*stocks*) por cuenta, y me gusta, *reposts*, respuestas y citas por post. No hay visualizaciones.
   - **Threads:** visualizaciones, me gusta, respuestas, *reposts*, citas y compartidos por post, y, por cuenta, visitas al perfil (`PROFILE_VIEWS`: el `views` de cuenta de Threads cuenta las visitas al perfil, según `design.md`) y seguidores.
   - Todo va en los *snapshots* actuales.
6. **Ver.** Las dos redes salen en el calendario, el dashboard, la página de red, los informes PDF y CSV, las mejores horas y el MCP.
7. **Calendario.** «Guardar vista» guarda los filtros actuales con un nombre. Al elegir una vista se aplican sus filtros.
8. **Analítica entre marcas.** «Todas las marcas» en el dashboard da los totales por métrica, una tabla por marca y otra por red. Se exporta a CSV.
9. **CSV de publicaciones.** En la vista Lista del calendario, «Exportar CSV» descarga las publicaciones del filtro activo con su estado, su enlace y sus métricas.
10. **Notas del inbox.** Debajo de cada comentario se pueden añadir y borrar notas internas, y filtrar por «con notas».

## Alcance

- **Dentro (esta iteración):**
  - **C-01 Bluesky:**
    - conexión con app password;
    - texto, de 1 a 4 imágenes con recompresión, y vídeo;
    - *facets*, `langs` e idempotencia por `rkey`;
    - métricas de cuenta y post;
    - calendario, dashboard, informes y MCP.
  - **C-02 Threads:**
    - OAuth y refresco;
    - texto, imagen, vídeo y carrusel;
    - límite de 250 publicaciones cada 24h ⚠️ con `QuotaPolicy`;
    - métricas de cuenta y post;
    - calendario, dashboard, informes y MCP.
  - **C-03:**
    - vistas guardadas, con MCP `list_calendar_views` y `view` en `get_calendar`;
    - analítica agregada entre marcas, con `get_stats` sin marca;
    - CSV de analítica (marca o todas) y de publicaciones.
  - **C-04:**
    - hashtags desde el histórico propio, con MCP `suggest_hashtags`;
    - GIFs de Giphy como MP4;
    - propuesta de fecha y redes en lenguaje natural (una sola fecha por publicación);
    - notas internas del inbox, con MCP `add_comment_note` y las notas en `list_comments`.
- **Fuera (siguientes specs):**
  - OAuth de atproto (D1);
  - texto alternativo de las imágenes, hilos, encuestas y control de respuestas en Bluesky y Threads;
  - primer comentario en Bluesky y Threads (se haría con una respuesta propia);
  - inbox de respuestas de Bluesky y Threads (permisos `threads_read_replies` y `threads_manage_replies`);
  - *ghost posts* y etiqueta de tema de Threads;
  - complemento IA de hashtags (D5);
  - lenguaje natural con varias fechas, que crearía varias publicaciones;
  - GIF como imagen animada (`image/gif`) y *stickers*;
  - menciones en las notas (un solo usuario);
  - Tenor (se cierra).

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| Handle o app password incorrectos | «No se pudo iniciar sesión en Bluesky: revisa el usuario y la contraseña de app.» No se guarda nada |
| Se usa la contraseña principal de Bluesky en vez de una app password | Aviso en el formulario. No se puede detectar con certeza ⚠️, así que se recomienda crear una app password |
| `refreshJwt` caducado o revocado | Se recrea la sesión con la app password guardada. Si también falla (revocada), la cuenta pasa a `requiere_reconexion` y se avisa in-app |
| Cuenta de Bluesky sin el email verificado, o límite diario de vídeo agotado (`getUploadLimits`) | El tipo `bluesky_video` falla con un mensaje claro, y antes de programar se avisa si la API lo indica ⚠️ |
| Imagen de Bluesky que no baja de 2.000.000 bytes ni recomprimida (tope configurable, `design.md`) | Error de validación al guardar, con el tamaño alcanzado |
| Vídeo de Bluesky de más de unos 50 MB (tope configurable, `design.md`) | Error de validación al guardar en el compositor y en `create_post`: «Bluesky solo admite vídeos de hasta 50 MB en esta instalación; este pesa X MB.» |
| Límite de escritura de Bluesky (5.000 puntos por hora y 35.000 al día; crear = 3) | `TransientPublishException` con reintento. Para uso personal es inalcanzable |
| Reintento tras un *timeout* en Bluesky | `findPublished` encuentra el registro por su `rkey`: no hay duplicado |
| Threads sin configurar (`THREADS_APP_ID` vacío) | La red no se ofrece en Conexiones ni en el compositor |
| La cuenta de Threads no es *tester* de la app en modo desarrollo | El OAuth falla. El mensaje explica cómo aceptar la invitación en los ajustes de Threads ⚠️ |
| Contenedor de Threads en `ERROR` o caducado | `PermanentPublishException` con el motivo traducido. El usuario reprograma |
| Límite de 250 publicaciones cada 24h agotado en Threads ⚠️ | Se aplica `PUBLISH_QUOTA_POLICY` (reprogramar o fallar), como la cuota de YouTube |
| Giphy sin clave, o cupo *beta* (100 por hora) agotado | Sin clave no se muestra el botón. Con el cupo agotado: «Has hecho muchas búsquedas de GIF; prueba en unos minutos.» |
| El GIF dura menos que el mínimo de la red (3 s en Reel y TikTok) | El validador lo rechaza en esa red con el mínimo exigido. Las demás redes lo aceptan |
| La IA devuelve una fecha pasada, ambigua o imposible (cambio de hora) | No se aplica nada: «No he entendido la fecha; prueba con "martes 3 a las 18:00".» |
| IA desactivada o límite diario agotado | El campo «Escribe cuándo» no aparece o explica el límite, igual que la sugerencia de texto |
| Exportación CSV demasiado grande | Tope de filas (`CalendarService::MAX_ITEMS`, 500, o configurable) con un aviso en la última fila |

## Criterios de aceptación

- [ ] Todas las entregas mantienen la paridad web y MCP. Ninguna publica en redes reales en Pest ni en Playwright (`Http::fake` y cuentas `e2e-`).
- [ ] La UI está en español y las páginas nuevas pasan axe sin violaciones serias ni críticas.
- [ ] [GWT] CA-01 — Dado un usuario con una marca, Cuando conecta Bluesky con un handle y una app password válidos (API simulada), Entonces la cuenta aparece en Conexiones como conectada y sus credenciales se guardan cifradas.
- [ ] [GWT] CA-02 — Dado un post de Bluesky de 301 grafemas, Cuando se guarda, Entonces el validador lo rechaza en el compositor y en `create_post` con el mismo mensaje. Con 300 grafemas que incluyen emojis compuestos, se acepta.
- [ ] [GWT] CA-03 — Dado un post de Bluesky con 4 imágenes, una de ellas de 3 MB, Cuando se publica (API simulada), Entonces se suben 4 *blobs* de ≤ 2.000.000 bytes (tope configurable; ajustado según `design.md` el 2026-10-27) y el registro lleva los *facets* de sus enlaces y hashtags.
- [ ] [GWT] CA-04 — Dado un destino de Bluesky cuya primera llamada a `createRecord` agota el tiempo, Cuando el job reintenta, Entonces `findPublished` encuentra el registro por su `rkey` y no se crea un segundo post.
- [ ] [GWT] CA-05 — Dado un vídeo de Bluesky, Cuando se publica (API simulada), Entonces el destino pasa a `procesando` y, cuando `getJobStatus` devuelve el *blob*, a `publicada` con su enlace `bsky.app`.
- [ ] [GWT] CA-06 — Dada una cuenta de Bluesky con posts publicados, Cuando corre el colector, Entonces se guardan los seguidores y, por post, los me gusta, compartidos y comentarios, con `views` = `null` («no disponible»), nunca 0.
- [ ] [GWT] CA-07 — Dada la app de Threads configurada, Cuando el usuario completa el OAuth (simulado), Entonces la cuenta se guarda con un token de larga duración y su fecha de caducidad, y `RefreshTokens` la renueva antes del margen.
- [ ] [GWT] CA-08 — Dado un carrusel de Threads de 3 imágenes, Cuando se publica (API simulada), Entonces se crean 3 contenedores hijo y uno padre, se publica el padre y el destino queda `publicada`.
- [ ] [GWT] CA-09 — Dado el límite de publicaciones de Threads agotado (simulado), Cuando llega la hora de un destino, Entonces se aplica la política de cuota sin llamar a la API de publicación.
- [ ] [GWT] CA-10 — Dados posts de Bluesky y Threads programados, Cuando el usuario abre el calendario y el dashboard, Entonces ve sus iconos, sus filtros de red y sus métricas; el informe PDF y el CSV incluyen las dos redes.
- [ ] [GWT] CA-11 — Dado el calendario filtrado por marca, red y estado, Cuando el usuario guarda la vista «Pendientes IG» y luego la elige desde otro filtro, Entonces se aplican los tres filtros. `get_calendar` con `view` devuelve lo mismo.
- [ ] [GWT] CA-12 — Dadas dos marcas con *snapshots*, Cuando el usuario abre «Todas las marcas», Entonces cada métrica es la suma de las marcas con dato, con `null` si ninguna lo tiene, y hay una fila por marca. `get_stats` sin marca devuelve los mismos totales.
- [ ] [GWT] CA-13 — Dada la lista del calendario con un filtro activo, Cuando el usuario pulsa «Exportar CSV», Entonces descarga un UTF-8 con BOM y `;` con exactamente esas publicaciones. Un texto que empieza por `=` sale neutralizado.
- [ ] [GWT] CA-14 — Dados posts publicados de una marca con hashtags y métricas, Cuando el usuario abre las sugerencias en el compositor, Entonces ve los hashtags con al menos 2 usos ordenados por rendimiento, y al pulsar uno se inserta en el cursor. En Threads, con más de un hashtag, el compositor avisa de que solo se usa 1 etiqueta de tema (la primera) ⚠️. Decisión (revisión P3 F8, A1): es un aviso, no un error de validación, porque Threads no rechaza el texto; se configura con `advise_hashtags` en `media_specs`.
- [ ] [GWT] CA-15 — Dada la clave de Giphy configurada, Cuando el usuario busca «gato» y elige un GIF (API simulada), Entonces se importa su MP4 como media de la publicación y el modal muestra «Powered by GIPHY».
- [ ] [GWT] CA-16 — Dada la IA activada, Cuando el usuario escribe «el viernes a las 18 en Instagram y Bluesky» (respuesta simulada), Entonces el compositor propone el viernes siguiente a las 18:00 en la zona de la marca y esas dos redes, y solo lo aplica al confirmar.
- [ ] [GWT] CA-17 — Dado un comentario del inbox, Cuando el usuario añade una nota y se resincroniza el inbox, Entonces la nota sigue ahí; `list_comments` la devuelve y `add_comment_note` añade otra.

## Pruebas

- **Pest:**
  - conectores con `Http::fake`: `createSession` y `refreshSession`, resolución del handle, `uploadBlob`, `uploadVideo` y `getJobStatus`, `createRecord` y `getRecord`, y la Threads API (contenedor, estado, publicación, límite e insights);
  - *facets* en bytes con emojis y acentos, y conteo de grafemas;
  - recompresión de imágenes;
  - paridad del MCP;
  - agregación con `null`;
  - CSV y su neutralización;
  - ranking de hashtags;
  - validación de la propuesta de la IA (zona horaria, cambio de hora, fechas pasadas);
  - notas tras la resincronización.
- **Regresión:** el conteo de tipos de contenido (13 → 19 reglas) y las listas de redes de los tests actuales.
- **Playwright:** un bloque por cada CA [GWT] de comportamiento de UI (CA-01, CA-10, CA-11, CA-12, CA-13, CA-14, CA-15, CA-16 y CA-17), con axe.
- **Smoke manual del usuario** (no delegable):
  - conectar y publicar de verdad los tipos de Bluesky y de Threads;
  - comprobar sus métricas al día siguiente.

## Referencias

- Petición de `/pm-cycle`, fase 1 (2026-10-26).
- [`metricool-analisis-bundle.md`](../../referencia/metricool-analisis-bundle.md): §Redes (Bluesky y Threads), §Compositor (hashtags, GIF y lenguaje natural), §Planificación y analítica, §Inbox.
- [`metricool-analisis-fuentes-publicas.md`](../../referencia/metricool-analisis-fuentes-publicas.md): §1.3 (líneas 87-88: Threads con 20 elementos y un hashtag; Bluesky con 4 imágenes, 1 vídeo o 1 GIF) y §1.6 (líneas 103-105: Threads 500 y Bluesky 300 caracteres).
- Código: `app/Connectors/{Network,ConnectorRegistry}.php`, `app/Connectors/Contracts/*`, `app/Enums/ContentType.php`, `config/{media_specs,social}.php`, `app/Analytics/Metric.php`, `app/Services/{CalendarService,AnalyticsService,ReportService,AiAssistant,InboxService}.php`, `app/Mcp/Tools/*`, `resources/js/Composables/useComposer.js` y `resources/js/Components/Composer/*`.
- Documentación oficial consultada el 2026-10-26:
  - [Bluesky OAuth](https://docs.bsky.app/docs/oauth-client) (app password para herramientas de un solo propósito);
  - [atproto OAuth spec](https://atproto.com/specs/oauth) (PKCE, PAR, DPoP con *nonces*, metadatos del cliente; refresco de 180 días en clientes confidenciales y 2 semanas en públicos);
  - [Bluesky posts](https://docs.bsky.app/docs/about-bluesky-content/posts) (4 imágenes; el lexicon vigente `app.bsky.embed.images` sube el tope a 2.000.000 bytes, ver `design.md`);
  - [Bluesky video](https://docs.bsky.app/docs/about-bluesky-content/video) (email verificado, límite diario, `video.bsky.app`);
  - [Bluesky rate limits](https://docs.bsky.app/docs/rate-limits);
  - [Tenor: cierre de la API](https://support.google.com/tenor/answer/10455265);
  - [Giphy API](https://developers.giphy.com/docs/api/) (clave *beta* de 100 llamadas por hora y atribución obligatoria);
  - Threads API (`developers.facebook.com/docs/threads`): se renderiza en el navegador y solo se han podido confirmar los nombres de los permisos y la existencia de los tokens de larga duración. El resto queda ⚠️.

## Decisiones confirmadas (revisión del usuario · pendiente)

1. Alcance de las cuatro características aprobado por el usuario en la petición (2026-10-26). **Confirmado.**
2. D1–D6 de la evaluación: resueltas en la puerta go (ver `evaluation.md`).
3. **Diseño** ([`design.md`](design.md), aprobado el 2026-10-27): C-01 = O1 y C-02 = T1. Cambios en esta spec respecto a la versión aprobada, cada uno con su motivo:
   - **Tope de imagen de Bluesky**: de 1.000.000 a **2.000.000 bytes**, configurable. CA-03 se ajusta. El lexicon vigente lo admite y el usuario lo eligió.
   - **Tope de vídeo de Bluesky**: unos **50 MB**, configurable. Por encima se rechaza al guardar con un mensaje claro. Así se respeta el worker de 120 s sin cambiar el despliegue.
   - **Hosts de Threads**: `threads.com` y `graph.threads.com`, no `.net`. Verificado en la documentación oficial.
   - **Vistas de cuenta de Threads**: son `PROFILE_VIEWS`, no visualizaciones. Verificado.
   - **Texto de Threads**: 500 caracteres, con cada emoji contado por sus bytes UTF-8. Verificado.

## Supuestos

- **Hecho técnico:** el contrato de conector solo contempla OAuth — `app/Connectors/Contracts/Connector.php:20-54`; rutas `routes/web.php:55-58` con `whereIn('provider', ['meta', 'google', 'tiktok'])`.
- **Hecho técnico:** las columnas `network` son `string(20)`, así que añadir redes no exige cambiar el esquema — `database/migrations/2026_10_19_210000_create_social_accounts_table.php:15` y siguientes.
- **Hecho técnico:** el validador cuenta caracteres con `mb_strlen` — `app/Services/MediaValidator.php:120`. Bluesky necesita grafemas (`intl` disponible, `docs/modulos/despliegue.md:30`).
- **Hecho técnico:** ninguna regla de media admite `image/gif` — `config/media_specs.php`.
- **Hecho técnico:** el informe CSV ya existe por marca, con BOM, `;` y neutralización de fórmulas — `app/Services/ReportService.php:292-330`.
- **Hecho técnico:** la IA solo envía el *brief* redactado, la marca y la red — `app/Services/AiAssistant.php:13-16, 84-99`.
- **Hecho técnico:** `scheduled_at` es por publicación, no por destino — `database/migrations/2026_10_21_100000_create_posts_tables.php:18`. Por eso el lenguaje natural da una sola fecha.
- **Restricción de negocio (supuesto hasta confirmar):** basta el modo desarrollo de Meta con la cuenta propia como *tester* de Threads — lo confirmaría el usuario al conectar (D2).
- **Restricción de negocio (supuesto hasta confirmar):** el usuario acepta que se guarde su app password de Bluesky cifrada — lo confirmaría el usuario (D1).
- **Restricción de negocio (supuesto hasta confirmar):** la clave *beta* de Giphy (100 llamadas por hora) basta para uso personal, y sus términos permiten descargar el MP4 para publicarlo ⚠️ — lo confirmaría la lectura de los ToS de Giphy por el usuario (D4).
- **Restricción de negocio (supuesto hasta confirmar):** los filtros de una vista guardada son de un solo valor (una marca, una red y un estado), como los actuales — lo confirmaría el usuario. Con varios valores, +4h aprox.
