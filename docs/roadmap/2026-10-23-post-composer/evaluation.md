---
generacion:
  inicio: 2026-10-23T10:43:57Z
  fin: 2026-10-23T10:56:46Z
  fuente: medido          # incluye también la redacción de spec.md (misma ventana, un solo marcador)
  tokens_reales: { entrada: 74, salida: 84455, cache_creacion: 260068, cache_lectura: 3721178 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 1.15
  duracion: 1h 9m           # reloj: 13m
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# 2026-10-23-post-composer

> Evaluación y presupuesto del rediseño del compositor de publicaciones: 11 características candidatas, con su coste, sus riesgos (sobre todo lo que admiten las APIs de Meta, YouTube y TikTok) y un MVP recomendado. Sirve para que el usuario elija el alcance y decida go/no-go.

| | |
|---|---|
| **Fecha** | 2026-10-23 |
| **Estado** | completado |
| **Prioridad global** | Media |
| **Solicitante** | daycry (usuario del proyecto) |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | pendiente (handoff a planner) |
| **Características evaluadas** | 11 |

---

## Cuadro de mando

| Métrica | Total estimado (las 11) | MVP recomendado (C-01, C-03, C-04, C-08 y C-11) | Confianza |
|--------|----------------|----------------|-----------|
| Esfuerzo humano | **286,8h** (239h base +20 %) | **151,2h** (126h base) | Media |
| Tiempo IA (ejecución) | **38,7h** (+ 9,7h supervisión) | **20,4h** (+ 5,1h) | Baja |
| Coste | **14.340 € + tokens** (tokens: ⚠️ verificar) | **7.560 € + tokens** | Media |
| Tokens IA | **9,68M** (in 8,71M / out 0,97M) | **5,10M** (in 4,59M / out 0,51M) | Baja |
| Multiplicador productividad | **×5,9** | ×5,9 | — |
| Características | **11** | 5 | — |

> Las horas humanas equivalentes (base del valor) y las horas IA (base del plazo) son magnitudes distintas y no se suman (LES-007). Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).

---

## Resumen ejecutivo

El usuario pide rediseñar el compositor para acercarlo al de Metricool, con identidad propia, para uso personal: un usuario y varias marcas. La spec lo divide en **11 características elegibles** (C-01…C-11). Las 11 suman **286,8h con margen, ≈ 14.340 € + tokens**.

Se recomienda un **MVP de 151,2h, ≈ 7.560 €**:

- editor a pantalla completa con contadores (C-01);
- panel de errores y barra de acciones con Publicar ahora (C-08);
- texto por red (C-03);
- presets por red persistentes (C-04);
- cierre (C-11).

El recon da cuatro hallazgos que condicionan el coste:

1. **El texto por red toca el corazón de la publicación.** `PublishRequestFactory` usa hoy `$post->text` para todos los destinos, y la idempotencia de Instagram y Facebook **compara la descripción publicada** (`InstagramPublisher.php:395-408`, `FacebookPublisher.php:127`). C-03 es la característica de más riesgo técnico, aunque el modelo `Post` → N `PostTarget` lo absorbe bien.
2. **El primer comentario es viable en Instagram y Facebook con los scopes que ya pedimos** (`instagram_manage_comments` y `pages_manage_engagement`; ya los usa `Meta/Comments.php`). **No es viable en TikTok** (la Content Posting API no tiene comentarios). **En YouTube probablemente exige `youtube.force-ssl`**, que no pedimos: habría que reconectar ⚠️.
3. **La ubicación y los colaboradores de Instagram son la parte más incierta.** La ubicación necesita un `location_id`, y buscarlo requiere probablemente una *feature* de Meta con app review ⚠️. Por eso se propone un spike de 4h antes de comprometer C-07.
4. **Los presets por red son baratos y de mucho valor**: hoy YouTube va con la categoría fija, `selfDeclaredMadeForKids=false` y sin `notifySubscribers` (`YouTubePublisher.php:263-277`), y TikTok solo manda `privacy_level` y `disable_comment` (`TikTokPublisher.php:476-480`).

**Veredicto: go para el MVP**, condicionado a que el usuario elija el alcance y responda a las preguntas P1–P6.

---

## Requerimientos recibidos

Mapa de la petición (fase 1 de `/dev-cycle`) y de la spec a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | Editor a pantalla completa, redes en iconos, barra de iconos y contadores por red | Petición (a); análisis UI §Cabecera y §Editor; spec CA-01..CA-05 | Claro. Modal o página (P2) |
| C-02 | Previsualización por red, móvil y escritorio | Petición (a); análisis UI §Previsualización; spec CA-06, CA-07 | Claro. La fidelidad es subjetiva |
| C-03 | Texto por red (Edit by network) | Petición (b); fuentes §1.3 e idea 3; spec CA-08..CA-12 | Claro en la UI. Modelo de datos (P5) |
| C-04 | Presets por red e interruptores persistentes | Petición (c); fuentes §1.4, §1.5 e ideas 4, 5 y 7; spec CA-13..CA-16 | Claro. La etiqueta IA es ⚠️ incógnita por red |
| C-05 | UTM (C-05a) y acortador (C-05b) | Petición (c) y (d); fuentes §1.4, §1.10 e idea 11; spec CA-17, CA-18 | Claro. Utilidad del acortador sin SmartLinks de terceros (P4) |
| C-06 | Primer comentario | Petición (d); fuentes §1.5, §1.6 e idea 6; spec CA-19..CA-21 | Claro. Scope de YouTube ⚠️ (P3) |
| C-07 | Ubicación y colaboradores de Instagram | Petición (d) y «colaboradores de IG»; fuentes §1.5; spec CA-22, CA-23 | **Ambiguo**: depende de la API (spike) |
| C-08 | Panel único de errores y barra inferior con Schedule | Petición (e); análisis UI §Barra inferior; fuentes §1.7 e idea 2; spec CA-24..CA-27 | Claro. Revisión y recurrente fuera |
| C-09 | Notas internas | Petición (f); análisis UI §Cabecera (Notes); spec CA-28 | Claro |
| C-10 | Mejores horas en el calendario y el selector | Petición (g); fuentes §1.8 e idea 9; spec CA-29..CA-31 | Claro |
| C-11 | Cierre: E2E, axe, MCP, documentación y smoke | Implícito en el flujo completo; spec CA-32, CA-33 | Claro |

**Ambigüedades / información que falta:**

1. **Alcance (P1).** El usuario pide una lista para elegir. Esta evaluación propone el MVP y dos olas.
2. **Modal o página (P2).** Metricool usa un diálogo sobre el calendario. Se propone una página a pantalla completa (ver spec, Decisiones).
3. **Scope de YouTube para comentar (P3)** ⚠️: no verificable desde el repo. Ver C-06.
4. **Acortador propio (P4).** Sin SmartLinks de terceros, su valor está en contar clics. ¿Compensa 18h?
5. **Modelo de «Edit by network» (P5).** Metricool crea un post por red. Aquí se propone un post con N destinos.
6. **C-07 (P6).** La viabilidad de la API no se puede verificar sin un spike con la app real de Meta.
7. Nombres exactos de los campos de etiqueta IA en YouTube y TikTok, y su existencia en Instagram ⚠️.

---

## Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades: la petición, los dos análisis de Metricool y el recon del compositor. Quedan P1–P6.
- [ ] **Alcance** de cada característica acotado: lo está en la spec (§Alcance), pero falta que el usuario elija qué C-XX entran.
- [x] **Criterios de aceptación / éxito** por característica: CA-01..CA-33.
- [x] **Restricciones**: uso personal, un usuario y varias marcas, 4 redes, sin integraciones de pago, identidad propia y paridad con el MCP. No hay deadline.
- [ ] **Dependencias externas** identificadas: Graph API v23.0, YouTube Data API v3 y TikTok Content Posting API. Identificadas, pero varios campos están sin verificar (⚠️, ver Riesgos).
- [x] **Contexto técnico** del proyecto disponible: recon del compositor, `PostService`, los publicadores, `Comments`, `BestTimesService`, `LibraryService`, el MCP y los tests.
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
| Densidad tokens/hora humana | ≈ 40.500 tok por hora base, con un reparto 90/10 de entrada y salida | La misma que en `social-login-mcp-oauth` (sale de `account-2fa-passkeys`). Es un indicio, no un histórico calibrado. Aquí hay más Vue que en aquellas, lo que tiende a subir la salida |
| Supervisión humana | 25 % de las horas IA | Default. En UI la revisión visual del usuario pesa más (LES-009); ver Productividad |

---

## Evaluación por característica

### C-01: Editor a pantalla completa y contadores por red

- **Requisito origen**: petición (a); análisis UI §Cabecera, §Editor; spec CA-01..CA-05.
- **Descripción**: comprende estas piezas.
  - Un `ComposerLayout` sin la navegación de la app.
  - Una cabecera con la marca y pastillas de red con el tipo en un desplegable.
  - Un editor grande con barra de iconos: media, emoji propio, IA (`AiSuggest`) y, con Instagram, un contador de hashtags.
  - Contadores por destino (caracteres y hashtags) con los máximos de `media_specs`, contados igual que el servidor.
  - El tope de 30 hashtags de Instagram, también en `MediaValidator`.
  - Confirmación al salir con cambios.
  - Dividir las 321 líneas de `Compose.vue` en `Components/Composer/*` sin perder comportamiento.
- **Complejidad**: Media.
- **Esfuerzo**: 33,6h (28h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Layout, cabecera y selector de redes y tipo | 6h |
  | Barra de iconos y selector de emoji propio | 5h |
  | Contadores por red: exponer `media_specs`, puntos de código y hashtags en cliente y servidor | 5h |
  | Dividir `Compose.vue` en componentes conservando la validación en vivo, la media y la IA | 5h |
  | Confirmación al salir (`beforeunload` y navegación de Inertia) | 2h |
  | Pest (hashtags) y E2E (CA-01..CA-05) | 5h |
  | **Total** | **28h** |

- **Previsión IA**: 1,02M in / 0,11M out tok · ⚠️ verificar €.
- **Coste**: (33,6h × 50 €/h) + tokens = **1.680 € + tokens**.
- **Impacto / áreas afectadas**:
  - `resources/js/Pages/Posts/Compose.vue` y `resources/js/Layouts/ComposerLayout.vue` (nuevo);
  - `resources/js/Components/Composer/*` (nuevo);
  - `app/Http/Controllers/PostController.php::compose()` (exponer los límites);
  - `app/Services/MediaValidator.php` y `config/media_specs.php` (`max_hashtags`).
- **Dependencias y prerequisitos**: ninguna. Es la base de C-02, C-03, C-04, C-06 y C-08.
- **Riesgos**:
  - **Regresión del compositor actual**: validación en vivo, media, reordenar y estado por red. Lo mitigan `ComposeTest.php` (13 tests) y los E2E existentes, que hay que mantener.
  - El contador de cliente y el del servidor pueden divergir con los emojis compuestos (ZWJ): se cuenta por puntos de código en los dos lados, como `mb_strlen`.
- **Incógnitas / preguntas abiertas**: P2 (modal o página). Un modal sobre el calendario sumaría ≈ +6h base.

### C-02: Previsualización por red, móvil y escritorio

- **Requisito origen**: petición (a); análisis UI §Previsualización; fuentes idea 16; spec CA-06, CA-07.
- **Descripción**: maquetas propias (sin copiar los estilos de las redes) de Instagram (post, carrusel, Reel y Story), Facebook (texto, foto, vídeo y Reel), YouTube (vídeo y Short) y TikTok. Muestran el texto efectivo de cada destino y la primera media. Incluye un conmutador de dispositivo y un aviso de «aproximada».
- **Complejidad**: Media.
- **Esfuerzo**: 24h (20h base) · confianza Media. Desglose: estructura y conmutador 3h, Instagram 5h, Facebook 3h, YouTube 3h, TikTok 3h, E2E y axe 3h.
- **Previsión IA**: 0,73M in / 0,08M out tok · ⚠️ verificar €.
- **Coste**: (24h × 50 €/h) + tokens = **1.200 € + tokens**.
- **Impacto / áreas afectadas**: `resources/js/Components/Composer/Preview/*`. Previsualizar un vídeo exige una URL de la media, que hoy solo existe para las imágenes subidas en la sesión (`URL.createObjectURL`). Para la media ya guardada hay que exponer una URL firmada de lectura (`MediaAsset::publicUrl`), ≈ 1h incluida.
- **Dependencias y prerequisitos**: C-01. Con C-03, usa el texto efectivo.
- **Riesgos**:
  - **Coste de mantenimiento**: las redes cambian su interfaz. Se mitiga con maquetas sencillas y el aviso fijo de «aproximada».
  - Que el usuario espere fidelidad píxel a píxel.
- **Incógnitas / preguntas abiertas**: ¿basta una sola maqueta por red (no por tipo)? Ahorraría ≈ 5h base.

### C-03: Texto por red («Edit by network»)

- **Requisito origen**: petición (b); fuentes §1.3 e idea 3; spec CA-08..CA-12.
- **Descripción**: comprende estas piezas.
  - `post_targets.text` nullable (vinculado = `null`).
  - Pestañas «Plantilla» más una por destino, con desvincular y volver a vincular.
  - La validación por texto efectivo en `PostService::plan()`.
  - `fill()` reinicia solo el intento del destino cuyo texto efectivo cambió.
  - `PublishRequestFactory` publica el texto efectivo, y la idempotencia de Instagram y Facebook compara con él.
  - La biblioteca (`PostTemplate.targets` guarda el texto propio) y el MCP (`PostPayload`, `GetPost`, `UpdatePost`).
- **Complejidad**: Alta.
- **Esfuerzo**: 38,4h (32h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Migración, modelo y `PostRequest` (`targets.*.text`) | 3h |
  | `plan()` y `fill()` por texto efectivo, con el reinicio por destino y `freshAttempt` con el texto viejo correcto | 7h |
  | `PublishRequestFactory` y tests de idempotencia y reconciliación con el texto propio | 4h |
  | UI de pestañas, vincular y desvincular, y confirmación | 7h |
  | Biblioteca (guardar y usar plantilla con los textos propios) | 3h |
  | MCP (`PostPayload`, `GetPost`) | 3h |
  | Pest y E2E (CA-08..CA-12) | 5h |
  | **Total** | **32h** |

- **Previsión IA**: 1,17M in / 0,13M out tok · ⚠️ verificar €.
- **Coste**: (38,4h × 50 €/h) + tokens = **1.920 € + tokens**.
- **Impacto / áreas afectadas**:
  - `database/migrations` y `app/Models/PostTarget.php`;
  - `app/Services/PostService.php` (`plan`, `fill`, `freshAttempt`, `schedule`) y `app/Services/PublishRequestFactory.php` (`make` y `makeReconcile`, donde `progress.reconcile.text` debe ser el texto efectivo viejo);
  - `app/Services/LibraryService.php`, `app/Mcp/Tools/{PostPayload,GetPost,UpdatePost,CreatePost}.php` y `resources/js/Components/Composer/NetworkTabs.vue`.
- **Dependencias y prerequisitos**: C-01. Respuesta a P5.
- **Riesgos**:
  - **Duplicados por idempotencia mal adaptada (el principal).** Si `findPublished` o `reconcile` comparan con el texto de la plantilla en vez del efectivo, un reintento tras un timeout no reconoce lo ya publicado y **publica dos veces**. Se mitiga con tests de reintento por red, con un destino desvinculado y con edición entre intentos.
  - `schedule()` reconstruye los `targets` desde la BD y debe copiar también `text`, o al programar un borrador se perdería el texto propio.
  - El calendario y la lista muestran `post.text`, que para un post con todo desvinculado puede estar vacío: hace falta mostrar el primer texto efectivo.
- **Incógnitas / preguntas abiertas**:
  - P5. Si se eligiera «un post por red», como Metricool: ≈ +10h base (separar al guardar, agrupar en la UI y gestionar cada post por separado en la papelera y el MCP). No se recomienda.

### C-04: Presets por red e interruptores persistentes

- **Requisito origen**: petición (c); fuentes §1.4, §1.5, ideas 4, 5 y 7; spec CA-13..CA-16.
- **Descripción**: un panel de presets global y por red con las opciones que **sí** acepta cada API.

  | Red | Opciones | Campo de la API | Estado |
  |---|---|---|---|
  | YouTube | Categoría | `snippet.categoryId` | Sí |
  | YouTube | Tags del usuario, que se suman al marcador interno de idempotencia | `snippet.tags` | Sí |
  | YouTube | Hecho para niños | `status.selfDeclaredMadeForKids` | Sí |
  | YouTube | Notificar a suscriptores | parámetro `notifySubscribers` de `videos.insert` | ⚠️ por verificar |
  | YouTube | Etiqueta IA | `status.containsSyntheticMedia` | ⚠️ por verificar |
  | TikTok, publicación directa | Duet, Stitch, divulgación comercial y etiqueta IA | `disable_duet`, `disable_stitch`, `brand_organic_toggle`, `brand_content_toggle` y `is_aigc` | ⚠️ por verificar |
  | Instagram | Compartir en el feed | — | Ya existe |
  | Instagram | Etiqueta IA | — | **Incógnita**: no se conoce un campo público en la Graph API |

  - «Auto publish» se reduce al `direct_post` de TikTok, que ya existe por cuenta.
  - Los interruptores persisten por (marca, red) en `composer_presets`. Los textos no.
- **Complejidad**: Media.
- **Esfuerzo**: 32,4h (27h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Verificar en la documentación vigente los campos ⚠️ (YouTube y TikTok) y el de la etiqueta IA de Instagram | 2h |
  | Opciones de YouTube en `openSession` y `PostRequest` | 4h |
  | Opciones de TikTok en `postInfo` y `PostRequest` (solo en publicación directa) | 4h |
  | Persistencia: tabla y servicio de presets, valor inicial al activar una red | 5h |
  | `PresetsPanel` (global y por red; ocultar lo que no aplica al tipo o la cuenta) | 6h |
  | MCP (esquema `options`) | 1h |
  | Pest con `Http::fake` y E2E (CA-13..CA-16) | 5h |
  | **Total** | **27h** |

- **Previsión IA**: 0,98M in / 0,11M out tok · ⚠️ verificar €.
- **Coste**: (32,4h × 50 €/h) + tokens = **1.620 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Connectors/Google/YouTubePublisher.php` (`openSession`, `CATEGORY_PEOPLE_BLOGS` fijo) y `app/Connectors/TikTok/TikTokPublisher.php` (`postInfo`);
  - `app/Http/Requests/PostRequest.php`, `app/Mcp/Tools/PostPayload.php` y la migración `composer_presets`;
  - `resources/js/Components/Composer/PresetsPanel.vue`.
- **Dependencias y prerequisitos**: C-01.
- **Riesgos**:
  - **Los tags de YouTube son la base de la idempotencia** (`targetTag`, `YouTubePublisher.php:271` y `:179`). Mezclar los tags del usuario sin conservar el marcador rompería `findPublished`. Además, el límite de YouTube para los tags es de ≈ 500 caracteres en total ⚠️: hay que reservar el hueco del marcador.
  - **Divulgación comercial de TikTok**: la auditoría de publicación directa exige una UX concreta (avisos y consentimiento de la Music Usage Confirmation) ⚠️. Hacerla bien ayuda a pasar la auditoría; hacerla a medias, no.
  - Cambiar «hecho para niños» a `true` desactiva los comentarios en YouTube, lo que choca con C-06. Hay que avisarlo en la UI.
- **Incógnitas / preguntas abiertas**:
  - La etiqueta IA de Instagram ⚠️. Si no hay campo, CA-16 la oculta.
  - Si `notifySubscribers` sigue vigente como parámetro de `videos.insert` ⚠️.

### C-05: Generador UTM (C-05a) y acortador propio (C-05b)

- **Requisito origen**: petición (c) y (d); fuentes §1.4, §1.10 e idea 11; spec CA-17, CA-18.
- **Descripción**:
  - **C-05a**: modal UTM en el cliente que inserta la URL con `utm_source`, `utm_medium`, `utm_campaign`, `utm_term` y `utm_content`.
  - **C-05b** (opcional): tabla `short_links` y la ruta pública `/s/{código}` con un 302, límite de peticiones y conteo de clics. Se acorta al publicar, de forma determinista por (destino, URL), y se activa con un interruptor persistente.
- **Complejidad**: Baja (a) / Media (b).
- **Esfuerzo**: **22,8h** (19h base) · confianza Media.
  - **C-05a**: 4,8h (4h base: modal 3h y test 1h).
  - **C-05b**: 18h (15h base: modelo y ruta 4h, acortado determinista al publicar 4h, interruptor 1h, clics 2h y tests 4h).
- **Previsión IA**: 0,69M in / 0,08M out tok · ⚠️ verificar € (C-05a: ≈ 0,16M).
- **Coste**: (22,8h × 50 €/h) + tokens = **1.140 € + tokens** (C-05a: 240 €; C-05b: 900 €).
- **Impacto / áreas afectadas**:
  - `resources/js/Components/Composer/UtmModal.vue`;
  - C-05b: `routes/web.php` (ruta pública) y `PublishRequestFactory` (texto acortado);
  - C-05b: un modelo nuevo y el dominio público de la app (Hestia).
- **Dependencias y prerequisitos**: C-01. C-05b necesita C-03 (texto efectivo).
- **Riesgos**:
  - (b) **La idempotencia de Instagram y Facebook compara el texto publicado**: un código distinto en cada reintento rompería la detección de duplicados. Por eso el acortado es determinista.
  - (b) Un redirector público es una superficie de abuso: solo redirige a URLs creadas por el usuario, con límite de peticiones.
  - (b) Los enlaces en la descripción de Instagram no son clicables, lo que reduce el valor.
- **Incógnitas / preguntas abiertas**: P4 (¿hace falta el acortador?). **Recomendación: solo C-05a.**

### C-06: Primer comentario

- **Requisito origen**: petición (d); fuentes §1.5, §1.6 e idea 6; spec CA-19..CA-21.
- **Descripción**: comprende estas piezas.
  - Un `first_comment` por destino, con su estado e intentos.
  - Un job `PostFirstComment`, que se encola al pasar el destino a `Published` (`PublishOutcomes::published`).
  - Un método `comment()` por publicador:
    - Instagram: `POST /{ig-media-id}/comments` (`message`);
    - Facebook: `POST /{post-id}/comments`;
    - YouTube: `commentThreads.insert`.
  - Idempotencia: antes de comentar, buscar un comentario propio con ese texto (`Comments::fetch` ya lee los comentarios de Instagram y Facebook).
  - Un fallo no degrada el destino; se avisa y se ofrece reintentar.
- **Complejidad**: Alta (efectos externos, idempotencia y estados).
- **Esfuerzo**: 33,6h (28h base) · confianza Media-Baja. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Verificar el scope de YouTube y el camino de reconexión; avisar si falta | 2h |
  | Migración y estado del comentario | 2h |
  | Job `PostFirstComment` con idempotencia, reintentos y espera a que el vídeo esté listo | 7h |
  | `comment()` en Instagram, Facebook y YouTube, y traducción de errores | 6h |
  | UI: modal, contador, estado en «Estado por red» y Reintentar | 5h |
  | Aviso in-app y MCP | 2h |
  | Pest con `Http::fake` (CA-19..CA-21) | 4h |
  | **Total** | **28h** |

- **Previsión IA**: 1,02M in / 0,11M out tok · ⚠️ verificar €.
- **Coste**: (33,6h × 50 €/h) + tokens = **1.680 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Services/PublishOutcomes.php`, `app/Jobs/PostFirstComment.php` (nuevo) y `app/Connectors/Meta/{GraphClient,Comments}.php`;
  - `app/Connectors/Google/YouTubePublisher.php` y `config/social.php` (scope de Google, si hace falta);
  - `app/Http/Requests/PostRequest.php`, `config/media_specs.php` (`first_comment.max`), `resources/js/Components/Composer/FirstCommentModal.vue` y los avisos.
- **Dependencias y prerequisitos**: C-01. Si C-03 está hecha, el comentario va por destino. Respuesta a P3.
- **Qué admite cada API** (⚠️ = no verificable desde el repo; se comprueba en la documentación oficial antes de planificar):

  | Red | ¿Se puede? | Permiso | Notas |
  |---|---|---|---|
  | Instagram (post, carrusel, Reel) | **Sí** | `instagram_manage_comments`, ya pedido (`config/social.php:45`) | No en Stories. El post debe tener los comentarios abiertos ⚠️ |
  | Facebook (Página) | **Sí** | `pages_manage_engagement`, ya pedido (`config/social.php:46`) | En Reels se comenta sobre el id del vídeo ⚠️ |
  | YouTube | **Sí, con reconexión probable** | `commentThreads.insert` exige `youtube.force-ssl` ⚠️. Hoy se pide `youtube`, `youtube.readonly` y `yt-analytics.readonly` (`config/social.php:59-63`) | Cuesta ≈ 50 unidades de cuota ⚠️. No se puede comentar en vídeos privados ni «hechos para niños», ni probablemente mientras el vídeo se procesa |
  | TikTok | **No** | La Content Posting API no expone comentarios | Fuera de alcance |

- **Riesgos**:
  - **Comentario duplicado** si el job se repite tras un timeout: se busca antes un comentario propio con el mismo texto.
  - **Reconexión de YouTube** si se añade el scope: todas las cuentas de YouTube quedan «requiere reconexión» hasta que el usuario vuelve a autorizar.
  - Ventana de procesamiento de vídeos (YouTube y Reels): comentar demasiado pronto falla. Se reintenta con espera.
- **Incógnitas / preguntas abiertas**:
  - P3. **Recomendación: Instagram y Facebook primero; YouTube solo si se acepta reconectar.**
  - Sin YouTube: −3h base.

### C-07: Ubicación y colaboradores de Instagram (condicionado a un spike)

- **Requisito origen**: petición (d) y «colaboradores de IG»; fuentes §1.5; spec CA-22, CA-23.
- **Descripción**: primero un **spike de 4h** con la app real de Meta (Graph `v23.0`). Si sale bien:
  - **colaboradores**: parámetro `collaborators` (lista de usernames) en el contenedor de imagen, carrusel y Reel;
  - **ubicación**: `location_id` (un id de Página con ubicación), con un buscador.
- **Complejidad**: Alta (por la incertidumbre de la API).
- **Esfuerzo**: 24h (20h base) · confianza **Baja**. Desglose: spike 4h, colaboradores 5h, ubicación con buscador 7h y tests 4h. **Si el spike sale negativo, el coste se queda en 4,8h.**
- **Previsión IA**: 0,73M in / 0,08M out tok · ⚠️ verificar €.
- **Coste**: (24h × 50 €/h) + tokens = **1.200 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Connectors/Meta/Instagram/InstagramPublisher.php::containerParams()` y `PostRequest`;
  - un endpoint de búsqueda de lugares y `resources/js/Components/Composer/*`.
- **Dependencias y prerequisitos**: C-01 y C-04 (el panel de Instagram). La app de Meta del usuario.
- **Qué admite la API** (⚠️ todo por verificar en el spike):

  | Dato | Lo que se cree | Riesgo |
  |---|---|---|
  | `collaborators` | Existe en el contenedor para imagen, carrusel y Reel, no en Stories. Hasta **3** colaboradores (Metricool muestra 5), con cuentas públicas. El colaborador debe aceptar la invitación en la app | Medio |
  | `location_id` | Se envía en el contenedor. El id se obtiene con la búsqueda de Páginas (`/pages/search`), que exige la *feature* «Page Public Metadata Access» con **app review** | **Alto**: puede no ser viable para una app personal en modo desarrollo |
  | Ubicación en Facebook | El parámetro `place` de las publicaciones de Página está en desuso o restringido | Alto. No se propone |

- **Riesgos**: app review de Meta (GOT-001: los permisos y *features* van por caso de uso) y cambios de versión de la Graph API.
- **Incógnitas / preguntas abiertas**:
  - P6. **Recomendación: aplazar C-07**, o hacer solo el spike y los colaboradores (≈ 10,8h) si el usuario los usa de verdad.

### C-08: Panel único de errores y barra de acciones

- **Requisito origen**: petición (e); análisis UI §Paneles y §Barra inferior; fuentes §1.7 e idea 2; spec CA-24..CA-27.
- **Descripción**: comprende estas piezas.
  - `ErrorsPanel`, que agrega los errores en vivo (hoy por cuenta, `errorsFor`) y los del servidor, con el icono de la red y la cuenta.
  - `DateTimePicker` propio con la zona de la marca y sin días pasados; guarda `Y-m-d\TH:i` como hoy.
  - Un botón dividido: **Programar** (por defecto), **Guardar borrador**, **Guardar en biblioteca** (también sin post previo, vía `LibraryService::store`) y **Publicar ahora** (fecha igual a ahora y despacho inmediato del job de cada destino).
  - Revisión y recurrente, fuera.
- **Complejidad**: Media.
- **Esfuerzo**: 32,4h (27h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Panel de errores (clave a icono y cuenta, en vivo y del servidor) | 4h |
  | Selector de fecha y hora propio y accesible | 6h |
  | Botón dividido accesible (menú con teclado) | 3h |
  | Publicar ahora: `PostService` (acción `publish_now`, sin exigir fecha futura) y despacho inmediato idempotente con el `dispatch-due-posts` | 5h |
  | Guardar en biblioteca sin post | 3h |
  | Pest y E2E (CA-24..CA-27) | 6h |
  | **Total** | **27h** |

- **Previsión IA**: 0,98M in / 0,11M out tok · ⚠️ verificar €.
- **Coste**: (32,4h × 50 €/h) + tokens = **1.620 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Services/PostService.php` y `app/Http/Controllers/{PostController,LibraryController}.php`;
  - `app/Jobs/{DispatchDuePosts,PublishPostTarget}.php`, solo para entender el despacho, sin cambiarlo;
  - `resources/js/Components/Composer/{ErrorsPanel,ComposerFooter,DateTimePicker}.vue`;
  - MCP: `create_post` con `publish_now` (opcional).
- **Dependencias y prerequisitos**: C-01.
- **Riesgos**:
  - **Doble despacho**: si el despacho inmediato y el `dispatch-due-posts` de ese minuto encolan el mismo destino, se publica dos veces. Se mitiga con el claim atómico que ya usa `PublishPostTarget` (transición a `Publishing`) y un test con los dos despachos.
  - El selector propio de fecha es la pieza de UI con más riesgo de accesibilidad. Se puede usar un `input` nativo de respaldo.
- **Incógnitas / preguntas abiertas**: ninguna bloqueante.

### C-09: Notas internas del post

- **Requisito origen**: petición (f); análisis UI §Cabecera (Notes); spec CA-28.
- **Descripción**: `posts.notes` en texto plano (5.000 caracteres), panel lateral, `PATCH posts/{post}/notes` (editable aunque el post no lo sea), indicador en la tarjeta del calendario y en `get_post`.
- **Complejidad**: Baja.
- **Esfuerzo**: 12h (10h base) · confianza Alta. Desglose: migración y endpoint 2h, panel 2h, indicador en el calendario 2h, MCP 1h y tests 3h.
- **Previsión IA**: 0,36M in / 0,04M out tok · ⚠️ verificar €.
- **Coste**: (12h × 50 €/h) + tokens = **600 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Models/Post.php`, `app/Services/CalendarService.php` y `resources/js/Pages/Calendar/Index.vue`;
  - `app/Mcp/Tools/GetPost.php` y `resources/js/Components/Composer/NotesPanel.vue`.
- **Dependencias y prerequisitos**: C-01 (dónde se coloca el panel). Se puede hacer sin C-01, en el compositor actual.
- **Riesgos**: bajos. Las notas no deben entrar en la comparación de «contenido cambiado» de `fill()`: si lo hicieran, editar una nota reiniciaría los intentos.
- **Incógnitas / preguntas abiertas**: ¿texto enriquecido? Se recomienda que no.

### C-10: Mejores horas en el calendario y el selector de fecha

- **Requisito origen**: petición (g); fuentes §1.8 e idea 9; spec CA-29..CA-31.
- **Descripción**:
  - **Calendario:** una capa en la vista semanal con un selector de red, el sombreado según `BestTimesService::heatmap` (7×24 en la zona de la marca), una leyenda y un valor accesible.
  - **Selector de fecha:** hasta 3 próximas franjas futuras sugeridas para las redes activas.
- **Complejidad**: Media-Baja (el cálculo ya existe).
- **Esfuerzo**: 19,2h (16h base) · confianza Media-Alta. Desglose: capa del calendario y accesibilidad 6h, endpoint de próximas franjas 4h, sugerencias en el selector 2h y tests 4h.
- **Previsión IA**: 0,58M in / 0,06M out tok · ⚠️ verificar €.
- **Coste**: (19,2h × 50 €/h) + tokens = **960 € + tokens**.
- **Impacto / áreas afectadas**:
  - `app/Http/Controllers/CalendarController.php`, `resources/js/Pages/Calendar/Index.vue` y `app/Services/BestTimesService.php` (reutilizado, sin cambios);
  - un endpoint JSON nuevo y `DateTimePicker` (C-08).
- **Dependencias y prerequisitos**: el selector de fecha de C-08 para la parte del compositor. La capa del calendario no depende de nada.
- **Riesgos**:
  - **Pocos datos**: hacen falta 10 publicaciones con métricas por red y marca (`min_posts`), así que en marcas nuevas no aparecerá nada. Es honesto, pero el usuario puede percibirlo como «no funciona».
  - Con varias redes activas, ¿qué franja se sugiere? Se propone una por red, no una combinada.
- **Incógnitas / preguntas abiertas**: ninguna bloqueante.

### C-11: Cierre (E2E, axe, MCP, documentación y smoke)

- **Requisito origen**: flujo completo de `/dev-cycle`; spec CA-32, CA-33.
- **Descripción**: comprende estas piezas.
  - Adaptar `tests/e2e/flows.spec.ts` y `pages.spec.ts` (axe) al nuevo compositor.
  - Regresión de las herramientas MCP de posts.
  - Documentación: guía de usuario, `publicacion.md` y `mcp.md`.
  - Smoke real: una publicación por red con las opciones aprobadas, registrada en `testing/`.
- **Complejidad**: Media.
- **Esfuerzo**: 14,4h (12h base) · confianza Media. Desglose: E2E 3h, axe 1h, documentación 4h y smoke 4h. Con solo el MVP baja a ≈ 10h base.
- **Previsión IA**: 0,44M in / 0,05M out tok · ⚠️ verificar €.
- **Coste**: (14,4h × 50 €/h) + tokens = **720 € + tokens**.
- **Impacto / áreas afectadas**: `tests/e2e/*`, `docs/usuario/guia-de-usuario.md`, `docs/modulos/{publicacion,mcp}.md` y `docs/roadmap/2026-10-23-post-composer/testing/`.
- **Dependencias y prerequisitos**: todas las características aprobadas.
- **Riesgos**: el smoke depende de las cuentas reales, de la cuota de YouTube (≈ 6 subidas al día, `config/social.php`) y de que TikTok siga en modo borrador.
- **Incógnitas / preguntas abiertas**: ninguna.

---

## Comparativa

Ordenada por la recomendación de valor/esfuerzo (MVP primero).

| # | Característica | Complejidad | Horas | Coste € | Tokens | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-01 | Editor a pantalla completa y contadores | Media | 33,6h | 1.680 € | 1,13M | Alta (MVP) | Media |
| C-08 | Panel de errores y barra de acciones | Media | 32,4h | 1.620 € | 1,09M | Alta (MVP) | Media |
| C-03 | Texto por red | Alta | 38,4h | 1.920 € | 1,30M | Alta (MVP) | Media |
| C-04 | Presets por red persistentes | Media | 32,4h | 1.620 € | 1,09M | Alta (MVP) | Media |
| C-11 | Cierre | Media | 14,4h | 720 € | 0,49M | Alta (MVP) | Media |
| C-09 | Notas internas | Baja | 12h | 600 € | 0,41M | Media (quick win) | Alta |
| C-05a | Generador UTM | Baja | 4,8h | 240 € | 0,16M | Media (quick win) | Alta |
| C-10 | Mejores horas | Media-Baja | 19,2h | 960 € | 0,65M | Media | Media-Alta |
| C-02 | Previsualización por red | Media | 24h | 1.200 € | 0,81M | Media | Media |
| C-06 | Primer comentario | Alta | 33,6h | 1.680 € | 1,13M | Media | Media-Baja |
| C-05b | Acortador propio | Media | 18h | 900 € | 0,61M | Baja | Media |
| C-07 | Ubicación y colaboradores de IG | Alta | 24h | 1.200 € | 0,81M | Baja | Baja |
| | **Total (las 11)** | | **286,8h** | **14.340 €** | **9,68M** | | |
| | **MVP (C-01, C-08, C-03, C-04, C-11)** | | **151,2h** | **7.560 €** | **5,10M** | | |

> Los tokens por característica están redondeados; el total sale de 239h base × 40.500 tok/h.

---

## Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 239h × 50 €/h (incluye 6h de verificación de APIs, 2 + 2 + 2, el spike de C-07 de 4h y 4h de smoke) | 11.950 € |
| Margen de contingencia | +20 % sobre el desarrollo base (47,8h) | 2.390 € |
| Tokens IA (input) | 8,71M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,97M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño + plan) | ≈ 400k tok facturables estimados (≈ 1,3h-IA). Esta evaluación queda medida en `generacion:` (LES-008) | ⚠️ verificar |
| **Total estimado (con margen)** | | **14.340 € + tokens (⚠️ verificar)** |

**Escenarios recomendados:**

| Escenario | Contenido | Horas con margen | Coste |
|---|---|---|---|
| **MVP** | C-01, C-08, C-03, C-04, C-11 | **151,2h** | **7.560 €** |
| MVP + quick wins | MVP + C-09 + C-05a | 168h | 8.400 € |
| Ola 2 | C-10, C-02, C-06 (IG y FB; YouTube según P3) | 76,8h | 3.840 € |
| Aplazado o descartado | C-05b, C-07 | 42h | 2.100 € |

**Variantes que mueven la cifra:**

| Variante | Delta base | Delta con margen |
|---|---|---|
| Compositor como modal sobre el calendario en vez de página (P2) | +6h | +7,2h ≈ +360 € |
| Un post por red, como Metricool, en vez de un post con N destinos (P5) | +10h | +12h ≈ +600 € |
| C-06 sin YouTube (P3) | −3h | −3,6h ≈ −180 € |
| C-02 con una maqueta por red en vez de por tipo | −5h | −6h ≈ −300 € |
| C-07 con el spike negativo (solo se gasta el spike) | −16h | −19,2h ≈ −960 € |

---

## Productividad IA (humano vs. IA)

| KPI | Valor (las 11) |
|-----|-------|
| Horas humanas estimadas | 286,8 h (239h base) |
| Horas IA (ejecución) | 38,7 h (32,3h base = 9,68M tok ÷ 300.000 tok/h) |
| Supervisión humana | 9,7 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **48,4 h** |
| Horas ahorradas | 238,4 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 1,49 |

> Las horas se muestran **con el margen de contingencia (+20 %)** ya aplicado sobre las horas base, humanas e IA. Las horas IA son una **estimación no calibrada**: en el histórico de los kits se desviaron entre −77 % y −96 %, y las humanas nunca se han validado (LES-007). Úsalas para el plazo, no para el valor. En un rediseño de UI, la supervisión del 25 % se queda corta: el usuario tiene que mirar el compositor en el navegador en cada ola. Con esa revisión visual, sube a ≈ 15–20h (LES-009: la revisión es la partida grande). El smoke real y el spike de C-07 son tiempo del usuario que la IA no reduce.

---

## Recomendación

- **Veredicto**: **go para el MVP** (C-01, C-08, C-03, C-04, C-11; 151,2h, ≈ 7.560 € + tokens), condicionado a que el usuario confirme el alcance (P1) y el modelo de texto por red (P5). Las olas 2 y 3 se deciden al cerrar el MVP. **No-go por ahora para C-07** (ubicación) y **C-05b** (acortador): mucho riesgo o poco valor para un uso personal.
- **Quick wins** (bajo coste, alto valor): C-09 notas (12h), C-05a UTM (4,8h) y, dentro de C-04, las opciones de YouTube (categoría, hecho para niños, notificar), que hoy están fijas.
- **Costosas / a valorar**: C-03 (por el riesgo en la idempotencia), C-06 (efectos externos y scope de YouTube) y C-07 (API incierta).
- **Orden sugerido**: **C-01 → C-08 → C-03 → C-04 → (C-09, C-05a) → C-11 del MVP**; después **C-10 → C-02 → C-06**. Motivos:
  - C-01 es la base de la que cuelgan todas.
  - C-08 da enseguida la experiencia de Metricool (errores y Publicar ahora) con poco riesgo.
  - C-03 va antes que C-04, C-02 y C-06 porque las tres dependen del texto efectivo por destino.
  - C-11 cierra cada ola con E2E y smoke.
- **Diseño**: pasar por `architect` antes del plan solo para C-03 (modelo y reconciliación del texto por destino) y, si entra, C-06 (estado del comentario). El resto no lo necesita.
- **Fuera de alcance recomendado**:
  - Canva, Drive, stock y GIFs;
  - el buscador de hashtags;
  - los textos guardados (spec propia pequeña);
  - la revisión y la aprobación;
  - la recurrencia;
  - la media por red;
  - el alt text;
  - el etiquetado de personas y productos;
  - el primer comentario en TikTok;
  - la vista de *feed* de Instagram;
  - las redes nuevas.

---

## Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| **Publicación duplicada** por la idempotencia que compara la descripción (IG y FB) al introducir el texto por destino (C-03) o el acortador (C-05b) | Media | Crítico | La idempotencia y `progress.reconcile` usan el texto efectivo. Acortado determinista. Tests de reintento tras un timeout con un destino desvinculado y con edición entre intentos |
| **Doble despacho** con Publicar ahora y `dispatch-due-posts` en el mismo minuto (C-08) | Media | Alto | Claim atómico existente (transición a `Publishing`). Test con los dos despachos |
| **APIs de terceros distintas de lo supuesto** (scope de YouTube para comentar, campos de la etiqueta IA, `notifySubscribers`, divulgación de TikTok, `collaborators`, `location_id`) | Alta | Medio | Verificación en la documentación oficial al inicio de cada característica (2h reservadas en C-04 y C-06). Spike en C-07. Todo lo que no se confirme se oculta en la UI, no se finge |
| **Reconexión de YouTube** si se añade `youtube.force-ssl` (C-06) | Media | Medio | Decisión P3. Aviso en el compositor y en Conexiones. Hacer YouTube en una entrega aparte |
| **Regresión del compositor** al dividir `Compose.vue` (C-01) | Media | Alto | Mantener `ComposeTest.php` y los E2E en verde desde la primera tarea. Dividir sin cambiar el comportamiento antes de añadir nada |
| **Romper la idempotencia de YouTube** al dejar tags al usuario (C-04) | Baja | Alto | El marcador `targetTag` siempre va primero y se reserva su longitud. Test que lo fija |
| **Paridad con el MCP olvidada** (campos nuevos solo en la web) | Media | Medio | Regla de la spec: todo campo nuevo entra en `PostPayload` y `GetPost`. CA-12 y la regresión en C-11 |
| **Ampliación de alcance** (once características atractivas) | Alta | Medio | Se aprueba por olas, y el `planner` solo planifica lo aprobado |
| **Expectativa de fidelidad** de la previsualización (C-02) | Media | Bajo | Aviso fijo y maquetas sencillas |
| **App review de Meta** para la ubicación (C-07) | Alta | Medio | Spike antes de comprometer horas. Aplazado por defecto |

---

## Siguiente paso

Para **ejecutar** lo aprobado, genera el plan detallado con el agente **`planner`** (`improvement-plan.md` + `tasks.md` en `docs/roadmap/2026-10-23-post-composer/`). Antes:

1. Que el usuario elija el alcance (P1) y responda a P2–P6. Las preguntas y sus recomendaciones están en el resumen de la fase 1.
2. Que pase por `architect` el modelo de C-03 (y C-06 si entra).

Propuesta para planificar: el **MVP, C-01 → C-08 → C-03 → C-04 → C-11**, con C-09 y C-05a si el usuario acepta los quick wins. El `planner` heredará las horas y costes de esta evaluación por característica, sin re-estimar desde cero, y actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec al crear el plan.
