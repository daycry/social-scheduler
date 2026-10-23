---
design: "post-composer"
titulo: "Nuevo compositor de publicaciones"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-23"
actualizado: "2026-10-23"
spec: spec.md                 # enlace hacia atrás (misma carpeta)
evaluacion: "evaluation.md"    # evaluation.md | n/a
plan: improvement-plan.md
adr: "docs/knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md"
opcion_elegida: "O1"    # pendiente | O1 | O2 | O3 — se fija SOLO tras la validación del usuario (pasada 2 / diálogo manual)
generacion:
  fuente: "estimado"          # medido | estimado (usage-meter.py)
---

# Diseño — Nuevo compositor de publicaciones

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) (go, MVP + quick wins) · **Plan:** pendiente · **ADR:** [`ADR-005`](../../knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md) (propuesta)

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | O1 — Texto efectivo resuelto al leer y despacho inmediato tras el commit sobre las defensas actuales |
| **Validada por el usuario** | sí, vía orquestador (`elegida: O1`, sin variante) (2026-10-23) |

## 1. Contexto y restricciones

**Alcance fijado por el usuario (no se reabre):** C-01 editor y contadores, C-08 errores y barra de acciones con Publicar ahora, C-03 texto por red, C-04 presets por red persistentes, C-09 notas, C-05a UTM y C-11 cierre. El compositor es una página a pantalla completa. El texto por red es un post con N destinos: `post_targets.text` nullable, y `null` significa vinculado a la plantilla. Quedan aplazados ubicación, colaboradores, acortador, primer comentario, mejores horas y previsualización por red.

**Qué hay hoy en el repo:**

- **Texto publicado.** `app/Services/PublishRequestFactory.php:34-40` pone `text: $post->text` en la petición de todos los destinos. Los publicadores solo leen `$request->text`, nunca el post:
  - Instagram: `caption` en `InstagramPublisher.php:216` y `:310`. `findPublished` (`:395-408`) compara `trim(caption)` con `trim($request->text)` en los últimos medios desde `since`. Las Stories y los textos vacíos no se pueden reconocer.
  - Facebook: `message`, `caption` o `description` (`FacebookPublisher.php:39-60`). `findPublished` (`:105-128`) compara `trim(message|description)` con `trim($request->text)`. Un vídeo o un Reel con referencia se resuelve por su ID.
  - YouTube: el título y la descripción salen del texto (`YouTubePublisher.php:587-603`). La idempotencia **no** usa el texto: busca el marcador `targetTag(targetId)` en `tags` (`:171-200`).
  - TikTok: el título y la descripción salen del texto (`TikTokPublisher.php:421-429` y `:476`). `findPublished` solo consulta por `publish_id` (`:135-139`), así que no usa el texto.
- **Reconciliación tras una edición.** `PostService::freshAttempt()` (`:460-485`) guarda en `progress.reconcile.text` el texto **del post** anterior. `PublishRequestFactory::makeReconcile()` lo usa para buscar lo viejo antes de publicar lo nuevo. `fill()` (`:396-458`) decide «contenido cambiado» a nivel de post (texto y media), no por destino.
- **Despacho.** `DispatchDuePosts` corre cada minuto con `withoutOverlapping(5)` (`routes/console.php:36`). Encola `PublishPostTarget` para los destinos `programada` que ya vencen. `PublishPostTarget` ya trae dos defensas contra el doble despacho:
  - `ShouldBeUnique` por `targetId`, con `uniqueFor=600` y el bloqueo en la caché `database` (tabla `cache_locks`, `CACHE_STORE=database`).
  - Un `claim()` con `lockForUpdate`: solo publica si pasa de `programada` a `publicando`, o si estaba `publicando` y colgado.

  La cola es `database` con `after_commit => false` (`config/queue.php:50`). Un job encolado dentro de una transacción puede correr antes del commit.
- **Validación.** `PostService::plan()` exige una fecha futura si `schedule=true` (`:310-312`) y valida el texto con `MediaValidator::validate($type, $media, $text)`. `PostRequest::rules()` es la única fuente de reglas: `PostPayload::validatedPayload()` del MCP la reutiliza.
- **MCP.** `SchedulerTool::presentPost()` (`app/Mcp/Tools/SchedulerTool.php:108-134`) devuelve el post sin texto por destino, opciones ni notas.
- **Frontend.** `resources/js/Pages/Posts/Compose.vue` (321 líneas, un solo fichero) contiene:
  - `useForm` y la validación en vivo (`validateNow` con `fetch` a `brands.posts.validate` y un debounce sobre `[form.text, form.media, form.targets]`);
  - los errores por cuenta (`errorsFor`, claves `targets.{i}`);
  - la media y la biblioteca.

  No hay Pinia. `resources/js/Composables/` ya existe.
- **Biblioteca.** `LibraryService::store()` guarda `targets` como `[{account_id, content_type}]` sin texto. `use()` crea un borrador con `PostService::create`.
- **Calendario.** `CalendarService.php:124` muestra `mb_substr($post->text, 0, 140)`.

**Restricciones que fijan el espacio de soluciones**

- **ADR-001 (stack):** monolito Laravel, cola y caché en MariaDB/MySQL, sin Redis. Cualquier bloqueo usa la BD o la caché `database`.
- **Contrato `Publisher`** (`app/Connectors/Contracts/Publisher.php`): `findPublished` se consulta antes de cualquier reintento y debe recibir **lo mismo que se envió**. Es la garantía de no duplicar (CA-11).
- **Presupuesto de tiempo del job:** `$timeout` 110 s < `--timeout` del worker 120 s < `retry_after` 180 s. Publicar ahora no puede publicar dentro de la petición HTTP.
- **Paridad web y MCP:** las reglas viven en `PostRequest::rules()` y en `PostService`. Ninguna regla nueva puede quedar solo en el cliente o solo en la web (spec, «Paridad con el MCP»).
- **Presupuesto:** la evaluación es para el alcance elegido (C-01, C-03, C-04, C-08, C-09, C-05a y C-11). Una opción de coste L lo desborda (ver §6).
- No hay `docs/CONSTITUTION.md`. Ningún ADR `aceptada` (ADR-003 y ADR-004) toca la publicación.

## 2. Opciones (2-3)

**Común a las tres opciones** (solo hay una salida razonable o ya está decidido; no se presentan alternativas de relleno):

- **Texto efectivo.** `PostTarget::effectiveText(): ?string` devuelve `$this->text ?? $post->text`. Es la única función que lo resuelve y la usan:
  - `PostService::plan()`: valida con `MediaValidator` el texto efectivo de cada destino y el nuevo `max_hashtags` de `config/media_specs.php` (IG 30);
  - el contador del cliente, que replica la misma regla;
  - `PublishRequestFactory`;
  - `presentPost`;
  - `CalendarService`: si la plantilla está vacía, muestra el primer texto efectivo.

  `fill()` pasa de «contenido cambiado del post» a **«contenido cambiado del destino»**: cambia su texto efectivo, cambia la media o cambian sus opciones. `schedule()` copia `text` al reconstruir los `targets`. Así, editar el texto propio de Facebook solo reinicia el intento de Facebook (CA-10).
- **Publicar ahora: misma ruta de validación.** `PostRequest` añade `publish_now` (booleano, `prohibited` junto con `scheduled_at`). `schedule` se mantiene por compatibilidad con el MCP. `plan()` con `publish_now` valida el contenido y las cuentas activas como al programar, pero no exige una fecha futura. La fecha la pone el servidor con `now()` y no se parsea de la entrada.
- **Presets por (marca, red) en una tabla `composer_presets`** con `user_id`, `brand_id`, `network`, `options` (JSON) y un índice único `(brand_id, network)`. Los gestiona un `ComposerPresetService` nuevo:
  - `forBrand()` alimenta las props del compositor;
  - `remember()` hace un upsert de **solo los interruptores booleanos** de las opciones del destino. Se llama al guardar desde la web. El MCP no los escribe, porque son una comodidad de la interfaz.

  Se descartan dos alternativas:
  - **JSON en `brands`.** `Brand` viaja serializado como prop a muchas páginas de Inertia, así que los presets irían de paseo o habría que ocultarlos. Además, el upsert sería de lectura, modificación y escritura sobre la fila de la marca.
  - **JSON en `social_accounts.meta`.** La clave sería la cuenta y no (marca, red). Además, `SocialAccountService:72` fusiona `meta` en cada reconexión.
- **Frontend en componentes con un estado compartido.** `Compose.vue` queda como orquestador fino con `ComposerLayout`. El estado vive en un composable `useComposer(props)`, que reúne:
  - el `useForm`;
  - el texto efectivo;
  - los contadores por destino, contando **puntos de código** con `[...text].length`, como `mb_strlen`;
  - vincular y desvincular;
  - los presets iniciales al activar una red.

  Se pasa con `provide` e `inject`, sin Pinia. La validación en vivo actual se traslada **tal cual** a `useLiveValidation(form)`: el mismo endpoint `brands.posts.validate` y el mismo debounce. Ahora observa también `targets[].text` y `targets[].options`. Devuelve los errores normalizados a `{targetIndex, accountId, network, message}`, que consumen `ErrorsPanel` y el estado deshabilitado de `ActionBar`. Los componentes son `ComposerHeader`, `NetworkPicker`, `NetworkTabs`, `TextEditor`, `EditorToolbar`, `EmojiPicker`, `UtmModal`, `NetworkCounters`, `PresetsPanel`, `ErrorsPanel`, `ActionBar` (botón dividido y `DateTimePicker`), `NotesPanel` y `UnsavedGuard`. Un único `Compose.vue` grande se descarta: crecería varias veces sobre sus 321 líneas y mezclaría la lógica que hay que probar (texto efectivo, contadores) con el marcado.
- **Paridad del MCP.** `PostRequest::rules()` añade:
  - `targets.*.text` (nullable, string; el máximo real lo pone `MediaValidator` por tipo);
  - las opciones nuevas de YouTube y TikTok;
  - `notes` (nullable, máximo 5.000);
  - `publish_now`.

  El MCP las hereda por `validatedPayload()`. `PostPayload::payloadSchema()` las documenta. `presentPost()` devuelve `notes` y, por destino, `text` (`null` = vinculado), `effective_text` y `options`. Las notas de un post no editable se editan con `PATCH posts/{post}/notes` en la web y con una herramienta `update_post_notes` en el MCP: `update_post` pasa por `PostService::update`, que rechaza los posts en vuelo o publicados. Las dos llaman a `PostService::updateNotes()`, que no toca `fill()`, así que una nota no reinicia los intentos. La biblioteca guarda `text` por destino en `post_templates.targets` y `use()` lo reaplica.

Las opciones difieren en **cómo se garantiza que un reintento compara con lo que realmente se envió** (eje 1) y en **cómo se evita el doble despacho de Publicar ahora** (eje 2).

### O1 — Texto efectivo resuelto al leer y despacho inmediato tras el commit sobre las defensas actuales

**Eje 1.** `PublishRequestFactory::make()` pasa `text: $target->effectiveText()`. Como los cuatro publicadores solo leen `$request->text`, Instagram y Facebook publican y comparan automáticamente el texto efectivo, y YouTube y TikTok no cambian su idempotencia. El único punto delicado es la edición tras un intento ambiguo: `freshAttempt()` recibe el **texto efectivo viejo de ese destino** (`$current->text ?? $before['text']`, calculado antes de escribir) y lo guarda en `progress.reconcile.text`. `makeReconcile()` ya lo usa sin cambios. Entre dos intentos, el texto efectivo solo puede cambiar pasando por `PostService::update()`. Ese método bloquea los destinos y rechaza los que están en vuelo, y por tanto pasa por `freshAttempt()`. No hay más vías de escritura.

**Eje 2.** `PostService::publishNow()` guarda el post con los destinos en `programada` y `scheduled_at = now()`. Después del commit (`DB::afterCommit` o `->afterCommit()` en el dispatch, porque la cola tiene `after_commit => false`) encola los destinos vencidos de **ese** post. Para ello reutiliza la consulta de `DispatchDuePosts`, extraída a `dueTargets(?Post $post = null)`, con los mismos filtros de cuenta activa y `next_attempt_at`. Si el despachador del minuto coincide:

- con el job aún en la cola, `ShouldBeUnique` descarta el segundo dispatch;
- con el job corriendo, el `claim()` ve `publicando` reciente y sale;
- con el job ya terminado, el `claim()` ve un estado final y sale.

No hay estado nuevo ni cambios en `PublishPostTarget`.

| Criterio | Valoración |
|---|---|
| Complejidad | **Baja-Media.** Se cambia una línea en la factoría y la decisión de `fill()` por destino. El despacho reutiliza todo lo existente |
| Riesgo | **Medio en un punto concreto:** que `fill()` calcule mal el texto efectivo viejo, por ejemplo al leer `$current->text` después de sobrescribirlo, o que `schedule()` olvide copiar `text`. Se cubre con tests de reintento por red con un destino desvinculado y una edición entre intentos (CA-11). En el eje 2, el riesgo es olvidar `afterCommit`: el job correría antes del commit y Publicar ahora esperaría al minuto siguiente. Retrasa, pero no duplica |
| Coste relativo | **S** |
| Reversibilidad | **Alta.** Solo añade la columna `post_targets.text` (C-03 la exige en cualquier opción) y un método de servicio. Deshacerla es volver a `$post->text` |

### O2 — Texto enviado congelado en el destino y el mismo despacho que O1

**Eje 1.** Además de `post_targets.text`, se añade `post_targets.sent_text`. `PublishPostTarget::claim()`, dentro del `lockForUpdate`, lo fija con `effectiveText()` al pasar a `publicando` si es `null`. `PublishRequestFactory` publica y compara **siempre** `sent_text`. `freshAttempt()` mueve `sent_text` a `progress.reconcile.text` y lo pone a `null`, así que ya no tiene que calcular el texto viejo. Ventaja: lo que compara `findPublished` es por construcción lo enviado, aunque una vía futura escriba el post sin pasar por `fill()`.

**Eje 2.** Igual que O1.

| Criterio | Valoración |
|---|---|
| Complejidad | **Media.** Hay una columna y una escritura más en el claim. Los tres sitios que tocan el intento (`claim`, `freshAttempt` y la factoría) tienen que ponerse de acuerdo en el ciclo de vida de `sent_text`, y `PublishRecovery` lo hereda |
| Riesgo | **Medio.** Se toca `PublishPostTarget::claim()`, el núcleo con más revisiones (`RevisionFase3*Test.php`). Si `sent_text` no se limpia en algún camino (por ejemplo, fallida y luego reprogramada), un reintento publicaría el texto viejo tras una edición |
| Coste relativo | **M** |
| Reversibilidad | **Media.** La columna es inocua, pero el claim queda acoplado a ella. Deshacerlo obliga a revisar de nuevo las pruebas del claim |

### O3 — Reserva explícita en la petición con un estado nuevo `en_cola` y el texto congelado de O2

**Eje 1.** Igual que O2.

**Eje 2.** Publicar ahora no deja los destinos en `programada`. Dentro de la transacción y con `lockForUpdate`, los pasa a un estado nuevo `PostStatus::Queued` (`en_cola`) y encola el job tras el commit. `DispatchDuePosts` solo selecciona `programada`, así que **estructuralmente** no puede volver a despacharlos, sin depender de `ShouldBeUnique` ni de la caché. `claim()` acepta `en_cola` y pasa a `publicando`. `PublishRecovery` gana un caso: un `en_cola` olvidado más de N minutos vuelve a `programada`. La interfaz muestra «En cola» al instante.

| Criterio | Valoración |
|---|---|
| Complejidad | **Alta.** Un estado nuevo recorre `PostStatus` (`isInFlight` e `isFinal`), `syncStatus`, `PublishRecovery`, `StatusBadge`, el calendario, los filtros de la lista, `presentPost` y la documentación del MCP |
| Riesgo | **Medio-Alto.** Hay más superficie de regresión en la máquina de estados que en la funcionalidad. Un `en_cola` sin recuperación deja posts atascados |
| Coste relativo | **L** |
| Reversibilidad | **Baja.** Un valor de enum persistido en los datos exige migrar las filas para retirarlo |

## 3. Criterios de decisión

1. **No duplicar publicaciones (CA-11 y CA-25).** Pesa más que nada: un duplicado es visible en una red real y no se deshace desde la app. Se valora qué garantías da cada opción y cuántas partes del núcleo de publicación ya probado toca.
2. **Tocar lo mínimo del núcleo de publicación.** `PublishPostTarget::claim()`, `freshAttempt()` y `PublishRecovery` llevan tres rondas de revisión. Cada cambio allí reabre esas pruebas. Prima la opción que consigue la garantía con menos cambios en el núcleo.
3. **Ajustarse al presupuesto evaluado (MVP + quick wins).** La evaluación no contempla un estado de publicación nuevo.
4. **Reversibilidad.** Es un uso personal, con un solo usuario. Las decisiones que dejan rastro en los datos (enums persistidos) cuestan más de deshacer.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto:** **O1**. Las dos garantías ya existen y están probadas:

- `findPublished` compara `$request->text`, que pasa a ser el texto efectivo con un solo cambio en la factoría;
- el doble despacho ya lo frenan `ShouldBeUnique` y el `claim()` con `lockForUpdate`.

O1 no toca el claim (criterio 2) y su coste es S (criterio 3). Su riesgo está acotado al cálculo del texto viejo en `fill()` y se cubre con tests (criterio 1). O2 y O3 compran una robustez adicional que hoy no cubre ningún hueco real, porque toda escritura pasa por `update()` y `fill()`, y a cambio tocan el núcleo y desbordan el presupuesto.

**O1 — Texto efectivo resuelto al leer y despacho inmediato tras el commit sobre las defensas actuales.** El usuario la validó el 2026-10-23, sin variante.

Cumple el criterio 1 (no duplicar) con las dos garantías que ya están probadas: la comparación de `$request->text` en `findPublished` y el par `ShouldBeUnique` + `claim()` con `lockForUpdate`. Cumple el criterio 2 porque `PublishPostTarget` no cambia. Su coste es S (criterio 3) y su reversibilidad, alta (criterio 4).

**Publicar ahora fuera del compositor** (decisión del usuario, 2026-10-23):

- Es una acción del post en el calendario y en la lista, con confirmación, para borradores y programadas.
- Usa el endpoint `POST posts/{post}/publish-now` y `PostService::publishNow()`. Ese método reconstruye los datos del post como hace hoy `schedule()`, conservando el `text` por destino, valida con `publish_now` y despacha con la misma `dueTargets($post)` tras el commit. Por eso tiene las mismas defensas contra el doble despacho.
- En el MCP es la herramienta `publish_post_now` (`post_id`).
- Un post que no es editable (en vuelo, publicado o con algún destino final) rechaza la acción con el mismo error que `update`.
- Coste aproximado: +2h sobre la evaluación.

Descartadas:

- **O2 (texto congelado en `sent_text`).** Toca `PublishPostTarget::claim()`, el núcleo con más revisiones (criterio 2). Además, su robustez extra no cubre ningún hueco real, porque toda escritura del texto pasa por `update()` y `fill()`.
- **O3 (estado `en_cola`).** Es un estado persistido que recorre la máquina de estados, la recuperación, la interfaz y el MCP. Tiene coste L, desborda la evaluación (criterio 3) y tiene reversibilidad baja (criterio 4).

## 5. Impacto en módulos y ficheros

Impacto de la opción elegida (O1). Las filas marcadas como O2 u O3 quedan solo como referencia de lo descartado: **no se implementan**.

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `database/migrations/2026_10_2x_*_composer.php` | `post_targets.text` (nullable), `posts.notes` (text, nullable) y la tabla `composer_presets` (único `brand_id, network`). **O2/O3:** `post_targets.sent_text` | Nuevo |
| `app/Models/PostTarget.php`, `app/Models/Post.php` | `text` y `notes` en `fillable`; `PostTarget::effectiveText()` | Modificado |
| `app/Models/ComposerPreset.php`, `app/Services/ComposerPresetService.php` | Modelo con el ámbito de usuario y `forBrand()` / `remember()` | Nuevo |
| `app/Services/PostService.php` | En `plan()`: texto efectivo por destino, hashtags y `publish_now`. En `fill()`: cambio por destino y texto efectivo viejo para `freshAttempt()`. `schedule()` copia `text`. Métodos nuevos `publishNow()` y `updateNotes()` | Modificado |
| `app/Services/PublishRequestFactory.php` | `make()` usa `$target->effectiveText()`. **O2/O3:** `sent_text` | Modificado |
| `app/Jobs/DispatchDuePosts.php` | Extrae `dueTargets(?Post)` para reutilizarla en Publicar ahora | Modificado |
| `app/Jobs/PublishPostTarget.php` | **O1: sin cambios.** O2: fija `sent_text` en `claim()`. O3: además acepta `en_cola` | Modificado (O2/O3) |
| `app/Enums/PostStatus.php`, `app/Services/PublishRecovery.php` | **Solo O3:** estado `Queued` y recuperación de `en_cola` olvidados | Modificado (O3) |
| `app/Services/MediaValidator.php`, `config/media_specs.php` | `max_hashtags` (IG 30) validado con el texto efectivo | Modificado |
| `app/Connectors/Google/YouTubePublisher.php` (`openSession` `:263-277`) | Categoría, tags del usuario **más** `targetTag`, `selfDeclaredMadeForKids` y `notifySubscribers` (⚠️ según la verificación de C-04) | Modificado |
| `app/Connectors/TikTok/TikTokPublisher.php` (`postInfo` `:464-480`) | `disable_duet`, `disable_stitch`, `brand_*_toggle` y `is_aigc` (⚠️), solo en publicación directa | Modificado |
| `app/Http/Requests/PostRequest.php` | Reglas para `targets.*.text`, las opciones nuevas, `notes` y `publish_now` | Modificado |
| `app/Http/Controllers/PostController.php`, `routes/web.php` | Props del compositor (límites de `media_specs` y presets). `store` y `update` con `publish_now` y `remember()`. `PATCH posts/{post}/notes` | Modificado |
| `app/Services/LibraryService.php`, `app/Http/Controllers/LibraryController.php` | `text` por destino en `targets`. Guardar desde un post sin guardar con el payload completo (CA-26) | Modificado |
| `app/Services/CalendarService.php` (`:124`) | Primer texto efectivo si la plantilla está vacía e indicador de nota | Modificado |
| `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/SchedulerTool.php` (`presentPost`), `app/Mcp/Tools/CreatePost.php`, `app/Mcp/Tools/UpdatePost.php` | Esquema y salida con `text` y `effective_text` por destino, `options`, `notes` y `publish_now` | Modificado |
| `routes/web.php`, `app/Http/Controllers/PostController.php` (`publishNow`) | `POST posts/{post}/publish-now` para la acción fuera del compositor (borradores y programadas). Lo usan el calendario y la lista | Nuevo / modificado |
| `resources/js/Pages/Calendar/Index.vue` y la vista de lista de posts | Acción «Publicar ahora» en el post, con confirmación | Modificado |
| `app/Mcp/Tools/PublishPostNow.php` | Herramienta MCP `publish_post_now` (`post_id`), que llama a `PostService::publishNow()` | Nuevo |
| `app/Mcp/Tools/UpdatePostNotes.php`, `app/Mcp/Servers/SchedulerServer.php` | Herramienta de notas, editable aunque el post no lo sea, y el registro de `update_post_notes` y `publish_post_now` | Nuevo / modificado |
| `resources/js/Layouts/ComposerLayout.vue` | Layout a pantalla completa, sin la navegación de la app | Nuevo |
| `resources/js/Pages/Posts/Compose.vue` | Pasa a orquestador fino (`provide` de `useComposer`) | Modificado |
| `resources/js/Composables/useComposer.js`, `resources/js/Composables/useLiveValidation.js` | Estado, texto efectivo, contadores por puntos de código y presets. La validación en vivo actual se traslada sin cambiar el endpoint | Nuevo |
| `resources/js/Components/Composer/*.vue` | `ComposerHeader`, `NetworkPicker`, `NetworkTabs`, `TextEditor`, `EditorToolbar`, `EmojiPicker`, `UtmModal`, `NetworkCounters`, `PresetsPanel`, `ErrorsPanel`, `ActionBar`, `DateTimePicker`, `NotesPanel` y `UnsavedGuard` | Nuevo |
| `tests/Feature/Posts/Compose/ComposeTest.php`, `tests/Feature/Publishing/*`, `tests/Feature/Mcp/Tools/*`, `tests/e2e/{flows,pages}.spec.ts` | Cobertura de CA-01 a CA-05, CA-08 a CA-15, CA-17 y CA-24 a CA-28. Test de reintento con un destino desvinculado y una edición entre intentos. Test de los dos despachos a la vez | Modificado / nuevo |

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| `fill()` guarda en `reconcile.text` el texto efectivo **nuevo** en vez del viejo (O1), o `sent_text` no se limpia en algún camino (O2/O3): un reintento no reconoce lo publicado y duplica | Media | Alto | Se calcula el texto efectivo viejo antes de cualquier escritura. Hay tests de reintento en Instagram y Facebook con un destino desvinculado, con la plantilla editada entre intentos y con el texto propio editado entre intentos. Se reutilizan los patrones de `RevisionFase3Intento3Test.php` |
| Publicar ahora encola el job antes del commit (`after_commit => false`): el claim ve el estado viejo, sale y el post espera al minuto siguiente | Media | Medio | Se usa `->afterCommit()` o `DB::afterCommit`. Un test con `Queue::fake` comprueba que el job se encola y que el destino está `programada` y vencido al ejecutarlo |
| Coinciden el despacho inmediato y el del minuto | Media | Alto si duplicara | Ya lo cubren `ShouldBeUnique` y el `claim()` con `lockForUpdate`. Un test ejecuta los dos despachos y los dos jobs y comprueba que se publica una vez (riesgo de C-08 en la evaluación) |
| Mezclar los tags del usuario en YouTube expulsa el marcador `targetTag` (límite de ≈ 500 caracteres) y rompe `findPublished` | Media | Alto | El marcador va primero y se reserva su hueco. Los tags del usuario se recortan para no superar el límite. Lo comprueba un test con `Http::fake` (CA-13) |
| Dos destinos de la misma cuenta de Instagram o Facebook con el mismo texto efectivo: `findPublished` de uno puede reconocer el post del otro (problema previo, no nuevo) | Baja | Medio | Fuera del alcance. Desvincular el texto lo evita. Se anota en la documentación del módulo |
| La UI muestra «programada» unos segundos tras Publicar ahora (O1 y O2), hasta que el worker reclama el destino | Alta | Bajo | `ActionBar` muestra «Enviando…» y el calendario refresca. O3 lo resolvería con `en_cola`, a coste L |
| Publicar ahora fuera del compositor (calendario, lista y MCP) no estaba en la evaluación: ≈ +2h | Alta | Bajo | Lo absorbe el plan como una tarea de C-08. No obliga a re-evaluar, porque reutiliza `publishNow()` y `dueTargets()`. Un test lanza Publicar ahora desde el endpoint del post y el despacho del minuto a la vez, y comprueba que se publica una sola vez |
| O3 desborda el presupuesto evaluado, porque el estado nuevo no está en la evaluación | Alta (si se elige O3) | Medio | Si se elige O3, `/dev-cycle` debe re-evaluar antes del plan |
| Los campos ⚠️ de YouTube y TikTok (etiqueta IA, `notifySubscribers` y `brand_*_toggle`) no existen o cambian | Media | Bajo | Se verifican en la documentación vigente antes de implementarlos (partida de C-04). Lo no admitido no se ofrece (CA-16) |

## 7. Preguntas abiertas

Ninguna. Las dos preguntas de la pasada 1 las resolvió el usuario el 2026-10-23:

- **Presets y MCP:** el MCP **no** actualiza los presets. Solo los escribe el compositor web (`ComposerPresetService::remember()`). Si algún día se necesita, será una herramienta MCP explícita aparte, fuera de este alcance.
- **Publicar ahora fuera del compositor:** sí, como acción del post en el calendario y en la lista, con confirmación, para borradores y programadas. Usa el mismo endpoint y las mismas defensas, y existe también en el MCP (`publish_post_now`). Ver §4 y §5; coste aproximado de +2h.

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-23 | Diseño creado (`borrador`). Se presentan al usuario tres opciones (O1, O2 y O3) con O1 recomendada |
| 2026-10-23 | El usuario elige O1 sin variante. Se resuelven las preguntas de §7: el MCP no escribe presets, y Publicar ahora existe también en el calendario, la lista y el MCP (+2h). Se escribe ADR-005 (`propuesta`) y el diseño pasa a `aprobado` |
