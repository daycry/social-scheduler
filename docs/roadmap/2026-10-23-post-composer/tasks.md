---
generacion:
  inicio: 2026-10-23T11:11:08Z
  fin: 2026-10-23T11:18:56Z
  fuente: medido
  tokens_reales: { entrada: 16, salida: 79261, cache_creacion: 85755, cache_lectura: 958619 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.55
  duracion: 33m
  ratio_usado: 300000   # ventana compartida con improvement-plan.md
estado: en-progreso
changelog: Added
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Nuevo compositor de publicaciones

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-23 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (texto efectivo resuelto al leer y despacho inmediato tras el commit, [ADR-005](../../knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md)) |
| **Plan de pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Las horas de este fichero son **base** (sin el +20 % de contingencia); el plan muestra las cifras con margen. Orden fijo (el de la evaluación): Fase 1 (C-01) → Fase 2 (C-08) → Fase 3 (C-03) → Fase 4 (C-04) → Fase 5 (C-09 y C-05a) → Fase 6 (C-11). Tokens repartidos en proporción a las horas (≈ 40,5k por hora base, como la evaluación).

> **Restricciones que valen para todas las tareas:**
>
> - **Riesgo principal: la doble publicación.** Lo fijan T-15 (Publicar ahora frente al despachador de cada minuto) y T-20 (reintento con texto propio en Instagram y Facebook). `PublishPostTarget` y los publicadores de Meta **no se tocan** (ADR-005, O1).
> - **Paridad del MCP en cada cambio de modelo:** texto por destino (T-23), opciones (T-31), notas (T-35) y Publicar ahora (T-13).
> - **Migraciones solo aditivas** (T-18, T-29, T-34): ninguna borra ni altera filas existentes.
> - **No tocar los datos del usuario id 28:** los E2E usan solo `e2e-sec-*` y marcas `e2e-`; los Pest, la factoría y bases de prueba.
> - **Alcance cerrado:** C-01, C-08 (con Publicar ahora fuera del compositor), C-03, C-04, C-09 (con `update_post_notes`), C-05a y C-11. Fuera: C-02, C-05b, C-06, C-07 y C-10.
> - **E2E:** `npm run test:e2e` en `http://localhost:8080`, con axe y sin desbordamiento a 375 y 360 px; sin publicar nunca en redes reales (eso es el smoke T-41).
> - ADR-005 ya está `aceptada`: el usuario dio el visto bueno el 2026-10-23 al aprobar el plan (T-42 solo lo comprueba).

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — C-01 | 8 | 8 | 100% | 0 / 28,0h | 0 / 3,8h | 0 / 0,9h | 0 / 1,13M |
| Fase 2 — C-08 | 9 | 9 | 100% | 0 / 29,0h | 0 / 3,9h | 0 / 1,0h | 0 / 1,17M |
| Fase 3 — C-03 | 0 | 8 | 0% | 0 / 32,0h | 0 / 4,3h | 0 / 1,1h | 0 / 1,29M |
| Fase 4 — C-04 | 0 | 8 | 0% | 0 / 27,0h | 0 / 3,6h | 0 / 0,9h | 0 / 1,09M |
| Fase 5 — C-09 notas y C-05a generador UTM | 0 | 5 | 0% | 0 / 14,0h | 0 / 1,9h | 0 / 0,5h | 0 / 0,57M |
| Fase 6 — C-11 | 0 | 4 | 0% | 0 / 12,0h | 0 / 1,6h | 0 / 0,4h | 0 / 0,48M |
| **TOTAL** | **17** | **42** | **40%** | **0 / 142,0h** | **0 / 19,2h** | **0 / 4,8h** | **0 / 5,74M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real), topado a la jornada configurada. No hay `.claude/jira.json`: no aplica.

> **Diferencias con la evaluación (declaradas).** Las horas por característica son las de la evaluación (C-01 28h, C-08 27h, C-03 32h, C-04 27h, C-09 10h, C-05a 4h, C-11 12h = 140h base) **más 2h** de «Publicar ahora» fuera del compositor (T-13, diseño §4). Las 5 revisiones de robustez al final de cada fase (T-08, T-17, T-25, T-33, T-38: 9h) y la revisión final (T-42) **no se suman**: salen de las partidas de «Pest y E2E» y de «documentación» de cada característica. Total: **142h base**.

---

## Fase 1 — C-01 · Editor a pantalla completa y contadores por red

**Estado**: en-progreso · **Estimado**: 28,0h · **Real**: — · **Coste est.**: 1.400 € + tokens (⚠️ verificar) · **Tokens est.**: 1,13M

### T-01 — Línea base y división de `Compose.vue` en composables y componentes sin cambiar el comportamiento

- **Descripción**: Primera tarea y red de seguridad de todo el plan: `Compose.vue` (321 líneas) pasa a orquestador fino con `provide` de `useComposer` y la validación en vivo se traslada a `useLiveValidation` sin cambiar el endpoint `brands.posts.validate`. No se añade ninguna característica.
- **Changelog**: El editor de publicaciones se reorganiza por dentro en componentes más pequeños; se ve y funciona igual que antes.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 0.7h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.3h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Posts/Compose.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Composables/useLiveValidation.js`, `resources/js/Components/Composer/*.vue`, `tests/Feature/Posts/Compose/ComposeTest.php`
- **Cubre (tests)**: E2E-01, E2E-12
- **Verificación**:
  - `php artisan test --compact tests/Feature/Posts` → en verde, con `ComposeTest.php` (13 tests) y `PostsTest.php` sin modificar
  - `git status --porcelain tests/Feature/Posts` → vacío (ningún test existente modificado)
  - `npm run build` → sin errores
  - `npm run test:e2e -- tests/e2e/flows.spec.ts` → los flujos de publicación existentes siguen en verde

**Criterios de aceptación**
- [x] La validación en vivo, la media, la biblioteca, la IA y el estado por red funcionan igual que antes
- [x] `ComposeTest.php` y los E2E existentes pasan sin tocar una línea
- [x] `Compose.vue` queda por debajo de 120 líneas y delega en composables

**Subtareas**
- [x] Ejecutar `tests/Feature/Posts` y `flows.spec.ts` antes de tocar nada y anotar la línea base
- [x] Extraer `useLiveValidation` (debounce de 500 ms sobre `[text, media, targets]`) y `useComposer`
- [x] Extraer los componentes sin cambiar su marcado ni sus textos
- [x] Repetir la suite y comparar con la línea base

**Notas**: Si un test existente falla, se revierte la extracción: no se retoca el test. Los componentes finales se crean sin lógica nueva; cada tarea siguiente rellena el suyo. **Evidencia**: línea base y final iguales: `php artisan test --compact tests/Feature/Posts` → 39 passed (202 assertions), sin tocar tests; `npm run build` OK; `playwright flows.spec.ts --project=desktop` → 4 passed. `Compose.vue` queda en 66 líneas. **Corrección de revisión (intento 1, A5):** la Verificación decía «ComposeTest.php (13 tests)» y la línea base real es `tests/Feature/Posts` → 39 passed (41 tras T-05); la verificación de `flows.spec.ts` se reejecutó con la Fase 1 completa (ver T-08).

---

### T-02 — `ComposerLayout`, cabecera con la marca y selector de redes y tipo

- **Descripción**: Layout mínimo a pantalla completa para `brands.posts.create` y `posts.edit` (sin navegación de la app; conserva Atrás, `afterSave` y las URL actuales), cabecera con la marca y una pastilla con `NetworkIcon` por cuenta con el tipo en un desplegable. Identidad propia: no se copian maquetación ni textos de Metricool (decisión de la spec).
- **Changelog**: El compositor ocupa toda la pantalla, sin la navegación de la app: la marca va en la cabecera y cada cuenta es una pastilla que se activa con clic o teclado, con su tipo de contenido en un desplegable.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 5.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.7h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0.2h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: frontend
- **Archivos**: `resources/js/Layouts/ComposerLayout.vue`, `resources/js/Components/Composer/ComposerHeader.vue`, `resources/js/Components/Composer/NetworkPicker.vue`, `resources/js/Pages/Posts/Compose.vue`, `app/Http/Controllers/PostController.php`, `resources/js/Components/Composer/TargetOptions.vue`, `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-01, E2E-12, M-02, M-03
- **Verificación**:
  - `php artisan test --compact tests/Feature/Posts/Compose` → en verde
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-01"` → pasa en desktop, mobile y mobile-360

**Criterios de aceptación**
- [x] CA-01: sin navegación de la app, marca en la cabecera, pastilla por cuenta operable con ratón y teclado (`aria-pressed`)
- [x] El tipo de contenido por cuenta se elige en un desplegable accesible
- [x] Sin desbordamiento horizontal a 375 y 360 px

**Subtareas**
- [x] `ComposerLayout` y su registro en `Compose.vue`
- [x] `ComposerHeader` con marca, Cerrar y espacio para Notas y acciones
- [x] `NetworkPicker` con pastillas y desplegable de tipo
- [x] Conservar `afterSave` y los enlaces directos

**Notas**: Un modal sobre el calendario queda descartado por el usuario (spec). **Evidencia**: `tests/Feature/Posts` → 39 passed; `npm run build` OK; `playwright composer.spec.ts -g E2E-01` → pasa en desktop, mobile y mobile-360. `PostController` no se ha tocado (no hacía falta). Archivos extra declarados: `TargetOptions.vue` (opciones por cuenta que ya existían) y el arranque de `composer.spec.ts`/`helpers.ts` (siembra de marca `e2e-composer-*`).

---

### T-03 — Máximo de hashtags en `media_specs` y `MediaValidator`, y límites expuestos a la vista

- **Descripción**: Añade `text.max_hashtags` (Instagram 30; el resto sin tope) a `config/media_specs.php`, lo valida `MediaValidator` con puntos de código (`mb_*`) y `PostController::compose()` expone los máximos de cada tipo a la vista. Se valida igual en web y MCP porque ambos pasan por `PostService::plan()`.
- **Changelog**: Instagram rechaza ahora los textos con más de 30 hashtags, con el mismo aviso desde la web y desde el MCP.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 3.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.4h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `config/media_specs.php`, `app/Services/MediaValidator.php`, `app/Http/Controllers/PostController.php`, `tests/Unit/MediaValidatorHashtagsTest.php`, `tests/Feature/Posts/Compose/ComposeTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=MediaValidatorHashtags` → 30 hashtags pasa, 31 falla con el mensaje esperado, hashtag con emoji contado por puntos de código
  - `php artisan test --compact tests/Feature/Posts tests/Feature/Mcp/Tools` → en verde

**Criterios de aceptación**
- [x] CA-03: 31 hashtags en Instagram dan error de validación con el mismo mensaje en web y MCP
- [x] Las props del compositor incluyen los máximos de texto y hashtags por tipo
- [x] Sin cambios para Facebook, YouTube ni TikTok

**Subtareas**
- [x] Definir la regla de hashtag en un solo sitio
- [x] Test unitario y de feature (web y MCP)
- [x] Exponer `limits` en `compose()` y `edit()`

**Notas**: ⚠️ YouTube ignora los hashtags si hay más de 60 (spec): se anota en la documentación, sin validar. **Evidencia**: `--filter=MediaValidatorHashtags` → 4 passed (30 pasa, 31 falla, unicode por puntos de código, resto de redes sin tope); `tests/Feature/Posts tests/Feature/Mcp/Tools` → 60 passed; pint OK. Los límites se exponen como prop `limits` (por tipo: `max_chars`, `max_hashtags`); `MediaValidator::countHashtags()` define la regla y `textCount.js` (T-04) la replica. Hashtag = `#` + letras/números/guion bajo (Unicode).

---

### T-04 — Contadores por red y por tipo con puntos de código (`NetworkCounters`)

- **Descripción**: Un contador por destino (`n / máximo` y `h / 30 #` en Instagram) con los límites de las props, contados como el servidor (`[...text].length`). Aviso visual al superar el máximo.
- **Changelog**: Al escribir ves un contador por red («n / 2.200» y «3 / 30 #» en Instagram, «n / 63.206» en Facebook), contado como el servidor, que avisa con texto cuando te pasas.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 3.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.4h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-02, T-03
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/NetworkCounters.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Composables/textCount.js`, `tests/e2e/composer.spec.ts`, `resources/js/Components/Composer/TextSection.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Components/Composer/TargetOptions.vue`
- **Cubre (tests)**: E2E-02
- **Verificación**:
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-02"` → pasa en los tres proyectos
  - `php artisan test --compact tests/Feature/Posts/Compose` → en verde

**Criterios de aceptación**
- [x] CA-02: «n / 2.200» y «3 / 30 #» en Instagram, «n / 63.206» en Facebook, y el recuento coincide con el del servidor con emojis (ZWJ incluidos)
- [x] El contador cambia de estado (color y texto, no solo color) al superar el máximo

**Subtareas**
- [x] Funciones `countChars` y `countHashtags` en un módulo compartido
- [x] `NetworkCounters` por destino activo
- [x] Test E2E con un emoji compuesto y 3 hashtags

**Notas**: — **Evidencia**: `playwright composer.spec.ts -g E2E-02` → pasa en desktop, mobile y mobile-360 (texto con familia ZWJ = 26 puntos de código, igual que `mb_strlen` en PHP, comprobado con php -r; 3 hashtags; 31 hashtags → «31 / 30 # · supera el máximo» y error en vivo); `tests/Feature/Posts` → 41 passed; build OK. El «Programar deshabilitado» de E2E-02 es del panel de errores (T-09, CA-24) y no se aserta aquí. El recuento «n caracteres» de la plantilla pasa también a puntos de código.

---

### T-05 — `EditorToolbar`, `EmojiPicker` propio y `AiSuggest` integrado

- **Descripción**: Editor grande con barra de iconos: media, emoji (lista curada por categorías con buscador, sin dependencia, más aviso de Win + .), IA (`AiSuggest`) y contador de hashtags. El emoji se inserta en la posición del cursor.
- **Changelog**: El editor es más grande y trae una barra con subir imagen o vídeo, un selector de emoji (con buscador, se inserta donde está el cursor) y el asistente de texto, todo usable con teclado.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 5.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.7h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0.2h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/EditorToolbar.vue`, `resources/js/Components/Composer/EmojiPicker.vue`, `resources/js/Components/Composer/TextEditor.vue`, `resources/js/Components/Composer/emojiData.js`, `resources/js/Components/MediaUploader.vue`, `resources/js/Components/AiSuggest.vue`, `resources/js/Components/Composer/TextSection.vue`, `resources/js/Components/Composer/MediaSection.vue`, `tests/e2e/composer.spec.ts`
- **Cubre (tests)**: E2E-03, M-03
- **Verificación**:
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-03"` → pasa en los tres proyectos

**Criterios de aceptación**
- [x] CA-05: emoji en la posición del cursor, IA y media operables con teclado y con nombre accesible
- [x] El selector se cierra con Escape y devuelve el foco al botón
- [x] Sin dependencias nuevas en `package.json`

**Subtareas**
- [x] `TextEditor` con selección y cursor expuestos
- [x] `EmojiPicker` con categorías y buscador
- [x] Conectar `AiSuggest` y el selector de media
- [x] Revisar el foco y los roles

**Notas**: — **Evidencia**: `playwright composer.spec.ts -g E2E-03` → pasa en desktop, mobile y mobile-360 (emoji en el cursor, Escape devuelve el foco al botón, IA con Escape, subida de media enfocable con nombre accesible, axe sin violaciones graves); `tests/Feature/Posts` → 41 passed; build OK; sin dependencias nuevas (`package.json` sin cambios). La subida de media pasa de la sección «Imágenes y vídeos» a la barra del editor, y su control deja de ser `display:none` (ahora `sr-only`) para ser alcanzable con teclado. El diálogo de salir es T-06; E2E-03 se completa allí. **Desviación declarada (revisión intento 1, A2):** la barra usa botones con texto («Insertar emoji», «Sugerir texto», «Añadir imagen o vídeo») con el icono como adorno `aria-hidden`, en lugar de «solo iconos», por accesibilidad (nombre visible y objetivo táctil); la barra «de iconos» del diseño queda así.

---

### T-06 — `UnsavedGuard`: confirmar al salir con cambios sin guardar

- **Descripción**: Confirmación propia (diálogo accesible) al pulsar Cerrar o navegar con Inertia, más `beforeunload` para cerrar la pestaña. No aparece si no hay cambios ni tras guardar.
- **Changelog**: Si cierras el compositor con cambios sin guardar, te pide confirmación (y avisa también al cerrar la pestaña); si cancelas, sigues con tu texto.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.3h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/UnsavedGuard.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Composables/backGuard.js`, `resources/js/app.js` (revisión intento 1, B1)
- **Cubre (tests)**: E2E-03
- **Verificación**:
  - `npm run test:e2e -- --grep "E2E-03"` → pasa (cancelar mantiene los cambios; confirmar sale)
  - `npm run build` → sin errores

**Criterios de aceptación**
- [x] CA-04: con cambios se pide confirmación; si cancelo sigo con los cambios
- [x] Sin cambios o tras guardar no se pide nada

**Subtareas**
- [x] Detectar «sucio» con `form.isDirty`
- [x] Diálogo propio con foco atrapado y Escape
- [x] Hook `router.on('before')` y `beforeunload`

**Notas**: — **Evidencia**: `playwright composer.spec.ts -g E2E-03` → pasa en desktop (cancelar con Escape mantiene el texto y devuelve el foco a Cerrar; confirmar sale; sin cambios y tras guardar no pregunta); `flows.spec.ts` (borrador → calendario) y `pages.spec.ts` (compositor) siguen en verde; build OK. Detecta lo «sucio» con `form.isDirty`; diálogo propio con foco atrapado.

---

### T-07 — E2E, axe y desbordamiento del compositor (CA-01..CA-05)

- **Descripción**: Fichero `tests/e2e/composer.spec.ts` con un bloque por criterio, con los usuarios `e2e-sec-*` y los helpers existentes (`loginAs`, `e2eUser`, `expectNoOverflow`), y axe en el compositor.
- **Changelog**: Hay pruebas automáticas del compositor nuevo (pantalla completa, contadores, barra del editor, salida con cambios y accesibilidad) en escritorio y móvil.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 3.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.4h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-02, T-04, T-05, T-06
- **Tipo**: test
- **Archivos**: `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/pages.spec.ts`, `resources/js/Pages/Posts/Compose.vue`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-12, A11Y-01
- **Verificación**:
  - `npm run test:e2e -- tests/e2e/composer.spec.ts` → verde en desktop, mobile (375) y mobile-360
  - `npm run test:e2e -- tests/e2e/composer.spec.ts` → el barrido de axe del compositor vive aquí (`pages.spec.ts` no se modifica; ver Notas)

**Criterios de aceptación**
- [x] Un test por CA-01..CA-05 con el título «E2E-xx» del `test-plan.md`
- [x] `expectNoOverflow` pasa a 375 y 360 px
- [x] Ningún test usa al usuario id 28: solo `e2e-sec-*` y marcas `e2e-`

**Subtareas**
- [x] Crear `composer.spec.ts`
- [x] Añadir el compositor al barrido de axe de `helpers.ts`
- [x] Limpiar marcas con `borrarMarcasE2e`

**Notas**: — **Evidencia (revisión intento 1, A5/A6: cifras corregidas)**: tras las correcciones de la revisión, `playwright composer.spec.ts` → 10 passed en desktop y 10 passed en mobile-360 (10 tests: los 6 originales más contador recortado/máximo de caracteres, emoji con varias líneas, botón Atrás y biblioteca con cambios sin guardar). Evidencia original de T-07: `playwright composer.spec.ts` → 18 passed (6 tests × desktop, mobile 375 y mobile-360: E2E-01, E2E-02, E2E-03 ×2, E2E-12, A11Y-01); `pages.spec.ts -g 'conexiones de la marca'` (compositor en el barrido) en los tres proyectos. axe 0 serias/críticas y 0 px de desbordamiento; solo usuarios `e2e-sec-*` y marcas `e2e-composer-*` (se borran en afterEach). `helpers.ts` gana `seedComposerBrand`/`cleanComposerBrands`; el barrido de axe del compositor vive en `composer.spec.ts` (necesita una marca con cuentas), no en `AUTH_PAGES`; `pages.spec.ts` no se ha modificado. Hallazgo del E2E: el mensaje de media de Instagram (opciones por cuenta) quedaba encima del editor; `TargetOptions` pasa debajo de la media en `Compose.vue`.

---

### T-08 — Revisión de robustez de la Fase 1 (regresión del compositor, contadores y accesibilidad)

- **Descripción**: Puerta de salida de la fase: revisión dirigida de lo que se pudo romper al dividir `Compose.vue` (estado por red, media, biblioteca, IA), de la divergencia entre el contador del cliente y el del servidor (emojis compuestos, hashtags con unicode), del XSS por texto del usuario en `v-html` o en el selector de emoji y de la accesibilidad por teclado. Checklist firmada en las Notas; cada hallazgo Important o Critical lleva un test.
- **Changelog**: Al volver atrás con el navegador con cambios sin guardar el editor pide confirmación, los contadores cuentan como el servidor y «Guardar en biblioteca» ya no guarda un texto antiguo.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.5h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-07
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `tests/Feature/Posts/Compose/ComposeRobustnessTest.php`, `resources/js/Components/Composer/*.vue`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `npm run test:e2e` → batería completa en verde (desktop, mobile y mobile-360)
  - `grep -rn "v-html" resources/js/Components/Composer resources/js/Pages/Posts` → vacío (ningún texto del usuario se pinta como HTML)
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [x] La suite completa pasa sin desactivar ni modificar tests existentes
- [x] Cada hallazgo Important o Critical tiene test y corrección
- [x] Ninguna fila de datos del usuario id 28 se toca

**Subtareas**
- [x] Revisar la paridad de recuento entre `textCount.js` y `mb_strlen`
- [x] Revisar `v-html`, el selector de emoji y `AiSuggest`
- [x] Suite completa, Pint y E2E

**Notas**: Puerta de salida de la fase 1. **Evidencia**: la revisión de dos lentes (intento 1) devolvió 2 Important y 6 Minor/avisos; corregidos abajo (sección «Revisión de dos lentes — intento 1»), cada Important con test E2E. `php artisan test --compact` → 1244 passed; `vendor/bin/pint --test` → passed; `npm run build` OK; `npx tsc --noEmit -p .` sin errores; `composer.spec.ts` → 10 passed (desktop) y 10 passed (mobile-360). Ninguna fila del usuario id 28 tocada. El `grep v-html` sobre el compositor queda vacío.

---


## Fase 2 — C-08 · Panel de errores, barra de acciones y «Publicar ahora»

**Estado**: en-progreso · **Estimado**: 29,0h · **Real**: — · **Coste est.**: 1.450 € + tokens (⚠️ verificar) · **Tokens est.**: 1,17M

### T-09 — `ErrorsPanel`: una sola lista de errores con el icono de la red y la cuenta

- **Descripción**: Agrega los errores en vivo (`targets.{i}`) y los del servidor en una lista con el icono de la red y la cuenta. Región con `aria-live` educado y enlace al control afectado. Deshabilita Programar y Publicar ahora mientras haya errores.
- **Changelog**: Los errores del formulario aparecen en una sola lista, con el icono de la red y la cuenta afectada, y un enlace que lleva al campo que hay que corregir.
- **Estado**: completado
- **Tiempo humano**: est. 4,0h · real 3.5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,54h · real 0.5h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real 0.13h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/ErrorsPanel.vue`, `resources/js/Composables/useLiveValidation.js`, `resources/js/Composables/useComposer.js`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/NetworkPicker.vue`, `resources/js/Components/Composer/MediaSection.vue`, `resources/js/Components/Composer/LibraryPanel.vue` (eliminado: nota en Notas)
- **Cubre (tests)**: E2E-04
- **Verificación**:
  - `npm run build` → sin errores
  - `npx playwright test tests/e2e/composer.spec.ts` → 16 passed en desktop, mobile y mobile-360 (incluye E2E-04)

**Criterios de aceptación**
- [x] CA-24: una sola lista con icono y cuenta por error; Programar y Publicar ahora deshabilitados; Guardar borrador habilitado
- [x] Aserción aplazada de E2E-02 (revisión Fase 1, A1): con 31 hashtags en Instagram, «Programar» queda deshabilitado y «Guardar borrador» habilitado (el E2E de T-09 la cubre)
- [x] El panel es legible por lector de pantalla y sin desbordamiento a 375/360 px

**Subtareas**
- [x] Mapear claves de error a destino
- [x] `ErrorsPanel` accesible
- [x] Integrar con `ActionBar` (T-11)

**Notas**: **Evidencia**: `npm run build` → OK; `npx tsc --noEmit -p .` → sin errores. Los E2E de esta tarea (E2E-04, y la aserción aplazada de E2E-02: con 31 hashtags Programar queda deshabilitado y Guardar borrador habilitado) se escriben y ejecutan en T-16, porque necesitan `ActionBar` (T-11) y `DateTimePicker` (T-10): `composer.spec.ts --project=desktop` → 13 passed. `useLiveValidation` exporta `normalizeErrors()` y devuelve `errorList` (`{id, scope, targetIndex, accountId, network, networkLabel, accountName, message, focusId}`); `useComposer` los une a los del servidor (`form.errors`), que se descartan al editar. Solo los errores EN VIVO bloquean Programar y Publicar ahora (`hasBlockingErrors`): así un error del servidor no deja el botón atascado. `ErrorsPanel` mantiene siempre montada la región `aria-live="polite"` y cada error trae un botón «Ir» que enfoca la pastilla de la cuenta (`account-pill-{id}`), el texto, los archivos o la fecha. Se retiran los bloques de error sueltos de `TargetOptions`, `NetworkPicker` y `MediaSection` (una sola lista). Desviación: `LibraryPanel.vue` se elimina aquí (su guardado pasa al menú de `ActionBar`, T-11/T-14); `Compose.vue` ya monta `ErrorsPanel`.
 **Corrección de revisión F2 intento 1 (A1, A3, B3)**: la fecha pasada entra en el panel y bloquea Programar EN VIVO (cliente, con la zona de la marca; el servidor revalida); Guardar borrador y Publicar ahora siguen habilitados. Una subida de media en curso (estado de `MediaUploader` → `useComposer.uploadingMedia`) bloquea Programar y Publicar ahora. **Evidencia real**: `npx playwright test tests/e2e/composer.spec.ts` → desktop 16 passed, mobile (375) 16 passed, mobile-360 16 passed (E2E-04 con el paso «fecha pasada»: reloj fijado, panel con «debe ser futura», Programar deshabilitado, Borrador y Publicar ahora habilitados, axe verde). El 375 que faltaba (`mobile`) se ha ejecutado ahora.
---

### T-10 — `DateTimePicker` propio con la zona de la marca y sin días pasados

- **Descripción**: Selector accesible por teclado que muestra la zona de la marca, bloquea los días pasados y guarda `Y-m-d\TH:i` como hoy. Con un `input datetime-local` nativo de respaldo si el selector propio falla en accesibilidad (mitigación de la evaluación).
- **Changelog**: La fecha de publicación se elige en un calendario propio que muestra la zona horaria de la marca, no deja elegir días pasados y se maneja con el teclado.
- **Estado**: completado
- **Tiempo humano**: est. 6,0h · real 5.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,81h · real 0.7h (estimado)
- **Supervisión**: est. 0,20h (≈25 % IA) · real 0.18h (estimado)
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/DateTimePicker.vue`, `resources/js/Components/Composer/ScheduleSection.vue`, `resources/js/Composables/useComposer.js`
- **Cubre (tests)**: E2E-04, M-03
- **Verificación**:
  - `npm run test:e2e -- --grep "E2E-04"` → pasa (zona visible, día pasado bloqueado, valor guardado `Y-m-d\TH:i`)
  - `php artisan test --compact tests/Feature/Posts` → en verde (el formato guardado no cambia)

**Criterios de aceptación**
- [x] CA-27: muestra la zona de la marca, no deja días pasados y guarda el mismo formato que hoy
- [x] Operable con teclado y sin violaciones de axe

**Subtareas**
- [x] Calendario y hora con rol `dialog`/`grid`
- [x] Respaldo nativo
- [x] Test del formato enviado

**Notas**: **Evidencia**: `npm run build` → OK. `composer.spec.ts --project=desktop` → 13 passed, entre ellos E2E-04 (se ejecuta en T-16): zona `Europe/Madrid` visible, el día de ayer deshabilitado y ArrowLeft no sale de «hoy», día elegido → `data-value` = `Y-m-d\TH:i`, y la petición que envía Programar lleva `scheduled_at: "<día>T14:30"` (mismo formato que antes). axe sin violaciones serias o críticas con el selector abierto. El selector compara con «hoy» y la hora actual EN LA ZONA DE LA MARCA (`Intl.DateTimeFormat`, zona inválida → UTC); si la fecha es hoy, la hora mínima es la próxima franja de 5 minutos. Roles: `dialog` + `grid`/`gridcell`, tabulación circular y Escape devuelve el foco al botón. Respaldo nativo: el prop `native` pinta un `input datetime-local` con `min`; no se activa porque axe y el teclado pasan con el selector propio (decisión local, no cruza el umbral de ADR). `useComposer.js` no cambia en esta tarea (el `form.scheduled_at` ya existía). Pendiente M-03 (revisión manual del usuario en el navegador).
 **Corrección de revisión F2 intento 1 (A2, A6, A9, B4)**: E2E-04 usa una marca en `Pacific/Auckland` con el navegador en `Europe/Madrid` y el reloj fijado a las 20:00 UTC (D en Madrid, D+1 en Auckland): falla si el selector usa la hora local. B4: si en la zona de la marca no queda ninguna franja hoy (≥ 23:56), el día de hoy se deshabilita y el foco inicial pasa a mañana (E2E «ninguna franja hoy»). Respaldo `native` testeado: `?selector=nativo` pinta el `datetime-local` con `min` en la zona de la marca (E2E «respaldo nativo»: fecha pasada → error y Programar deshabilitado; futura → se envía `Y-m-d\TH:i`). **Evidencia real**: `php artisan test --compact tests/Feature/Posts` → 55 passed; `composer.spec.ts` → 16 passed en desktop, mobile y mobile-360.
---

### T-11 — `ActionBar`: botón dividido (Programar, Guardar borrador, Guardar en biblioteca y Publicar ahora)

- **Descripción**: Botón dividido con menú accesible (flechas, Escape, `aria-haspopup`). Programar y Publicar ahora se deshabilitan con errores. «Publicar ahora» muestra «Enviando…» hasta que cambia el estado. Enviar a revisión y recurrente quedan fuera.
- **Changelog**: La barra de acciones pasa a un botón dividido: Programar, y en su menú Guardar borrador, Guardar en biblioteca y Publicar ahora, con Enviando… y sin posibilidad de enviar dos veces.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 3.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.4h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-09, T-10
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/ActionBar.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Composables/useComposer.js`
- **Cubre (tests)**: E2E-04, E2E-05, M-03
- **Verificación**:
  - `npm run build` → sin errores
  - `npx playwright test tests/e2e/composer.spec.ts` → 16 passed en desktop, mobile y mobile-360 (incluye E2E-04 y E2E-05)

**Criterios de aceptación**
- [x] Menú operable con teclado y con nombre accesible
- [x] Con errores: Programar y Publicar ahora deshabilitados, Guardar borrador y biblioteca habilitados
- [x] Doble clic en Publicar ahora no envía dos peticiones (botón bloqueado mientras `form.processing`)

**Subtareas**
- [x] Componente del menú
- [x] Estados `Enviando…` y errores
- [x] Conectar con las acciones de T-12 y T-14

**Notas**: **Evidencia**: `npm run build` → OK. `composer.spec.ts --project=desktop` → 13 passed (E2E-04 y E2E-05 se escriben en T-16): menú con ArrowDown/ArrowUp (saltan los elementos deshabilitados), Escape cierra y devuelve el foco, `aria-haspopup="menu"`; con errores Programar y Publicar ahora deshabilitados y Guardar borrador/Guardar en biblioteca habilitados; Publicar ahora muestra «Enviando…», bloquea el botón y el menú y una segunda petición (`requestSubmit()`) NO llega a enviarse (`guardados === 1`). `useComposer` activa `sendingNow` de forma síncrona y `submit()` también lo respeta, así un Enter o doble clic antes de que Inertia marque `processing` no duplica la petición. «Guardar borrador» pasa de botón suelto al menú (los E2E existentes usan el nuevo helper `guardarBorrador`). Las acciones de T-12 (`publishNow`) y T-14 (`saveToLibrary`, diálogo de nombre) ya están conectadas aquí; el endpoint de la biblioteca llega en T-14. Pendiente M-03.
 **Corrección de revisión F2 intento 1 (A3, B3)**: «Programar», «Publicar ahora» y «Guardar en biblioteca» se deshabilitan mientras haya una subida de media en curso (E2E «con una subida de media en curso…»: botón con «Subiendo…», los tres deshabilitados y Guardar borrador habilitado; al terminar se habilitan). Programar se bloquea además con la fecha pasada (T-09). **Evidencia real**: `npx playwright test tests/e2e/composer.spec.ts` (E2E-04 y E2E-05) → desktop 16, mobile (375) 16 y mobile-360 16 passed; el proyecto `mobile` se ha ejecutado ahora (antes no).
---

### T-12 — Publicar ahora en `PostService`: `publishNow()`, `publish_now` y despacho tras el commit

- **Descripción**: Extrae `DispatchDuePosts::dueTargets(?Post)` y añade `PostService::publishNow()`: valida con `publish_now` (misma validación de contenido, sin exigir fecha futura), fija la fecha a ahora y despacha `PublishPostTarget` de cada destino con `DB::afterCommit` (la cola `database` tiene `after_commit=false`). `PublishPostTarget` **no se toca** (ADR-005, opción O1): conserva `ShouldBeUnique` y el `claim()` con `lockForUpdate`. `store` y `update` aceptan `publish_now`.
- **Changelog**: Se puede publicar una publicación al instante sin esperar a su hora, con las mismas comprobaciones que al programar y sin riesgo de publicarla dos veces.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 4.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.6h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0.15h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Jobs/DispatchDuePosts.php`, `app/Http/Requests/PostRequest.php`, `app/Http/Controllers/PostController.php`, `tests/Feature/Publishing/PublishNowTest.php`, `docs/modulos/publicacion.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PublishNow` → en verde
  - `git diff --stat -- app/Jobs/PublishPostTarget.php` → vacío (el núcleo del claim no cambia)
  - `php artisan test --compact tests/Feature/Publishing` → en verde (las suites existentes sin modificar)

**Criterios de aceptación**
- [x] CA-25: con `Queue::fake`, el job de cada destino se encola en la misma petición y el destino está `programada` y vencido al ejecutarse
- [x] Un post con errores de contenido se rechaza con los mismos mensajes que al programar
- [x] Una cuenta que requiere reconexión da el mismo error de validación que al programar

**Subtareas**
- [x] Extraer `dueTargets(?Post)` sin cambiar el comportamiento del despachador
- [x] `publishNow()` y la acción `publish_now` en `plan()`
- [x] Despacho con `afterCommit`
- [x] Pest con `Queue::fake`

**Notas**: **Evidencia**: `php artisan test --compact --filter=PublishNow` → 15 passed (44 assertions); `php artisan test --compact tests/Feature/Publishing` → 76 passed (las suites existentes sin modificar); `git diff --stat -- app/Jobs/PublishPostTarget.php` → vacío; `vendor/bin/pint --test` → passed. `DispatchDuePosts::dueTargets(?Post, ?int)` es estático y es la consulta del despachador del minuto y de Publicar ahora. `publish_now` va en `PostRequest` con `prohibited_if` sobre `scheduled_at`; `plan()` pone la fecha con `now()` (no se parsea de la entrada) y `create()`/`update()` encolan tras el commit con `DB::afterCommit` (test: dentro de una transacción no se encola; con rollback, nunca). Decisión local (no cruza el umbral de ADR): `publishNow()` pasa por `update()`, así un post en vuelo/publicado se rechaza con el mismo error y un fallido se reintenta como al programar. ADR-005 ya está `aceptada`.

---

### T-13 — Publicar ahora fuera del compositor: ruta, calendario, lista y herramienta MCP `publish_post_now`

- **Descripción**: `POST posts/{post}/publish-now` (reutiliza `publishNow()` y `dueTargets()`), acción «Publicar ahora» con confirmación en la tarjeta del calendario y en la lista, y la herramienta MCP `publish_post_now` (`post_id`), registrada en `SchedulerServer`. Un post no editable (en vuelo, publicado o con un destino final) rechaza la acción con el mismo error que `update`. Un post ajeno se trata como no encontrado (`NotOwned`).
- **Changelog**: Se puede publicar ahora una publicación desde el calendario (semana, mes y lista) con confirmación, y desde un agente de IA con la nueva herramienta publish_post_now.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.3h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `routes/web.php`, `app/Http/Controllers/PostController.php`, `app/Mcp/Tools/PublishPostNow.php`, `app/Mcp/Servers/SchedulerServer.php`, `app/Mcp/Tools/PostPayload.php`, `app/Services/CalendarService.php`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Components/Calendar/PublishNowConfirm.vue`, `tests/Feature/Posts/PublishNowEndpointTest.php`, `tests/Feature/Mcp/Tools/PublishPostNowToolTest.php`, `docs/modulos/calendario.md`, `docs/modulos/mcp.md`
- **Cubre (tests)**: E2E-06, API-01, M-05
- **Verificación**:
  - `php artisan test --compact --filter=PublishNowEndpoint` → en verde (borrador, programada, no editable y post ajeno)
  - `php artisan test --compact --filter=PublishPostNowTool` → en verde (paridad con la web, `NotOwned`, no editable)
  - `npx playwright test tests/e2e/composer.spec.ts` → 16 passed en desktop, mobile y mobile-360 (incluye E2E-06)

**Criterios de aceptación**
- [x] Confirmación antes de enviar, accesible y cancelable
- [x] El MCP cumple las mismas reglas que la web y no permite publicar posts de otra marca o usuario
- [x] La tarjeta muestra «Enviando…» y el calendario se refresca

**Subtareas**
- [x] Ruta y método del controlador
- [x] Acción en `Calendar/Index.vue` (semana, mes y lista)
- [x] `PublishPostNow` y registro
- [x] Tests de ruta y de herramienta

**Notas**: **Evidencia**: `php artisan test --compact --filter=PublishNowEndpoint` → 12 passed (borrador, programada, no editable ×3, errores de contenido, post ajeno 404, sin sesión, `publish_now` desde el compositor, `prohibited_if` con `scheduled_at`, `throttle:30,1`, `can_publish_now` del calendario); `php artisan test --compact --filter=PublishPostNowTool` → 7 passed (paridad con la web, `NotOwned` igual para ajeno e inexistente, no editable con el mismo mensaje que `update_post`, ability `posts:write`, `create_post` con `publish_now`); `php artisan test --compact tests/Feature/Mcp tests/Feature/Isolation` → 222 passed (antes de esta tarea). E2E-06 (calendario y lista; se escribe en T-16): `composer.spec.ts --project=desktop` → 13 passed. La tarjeta muestra «Enviando…» y el calendario hace `router.reload` cada 4 s (máximo 15) hasta que el estado deja de ser borrador/programada. Desviaciones: (1) `SchedulerServer::$defaultPaginationLength = 25`: con 18 herramientas, `tools/list` paginaba a 15 y dejaba fuera `get_report`/`reply_comment` (rompía `McpStatsTest`); (2) `publish_now` también se documenta en `PostPayload` (create_post/update_post, según el diseño §4); (3) la ruta lleva `throttle:30,1` (revisión T-17: límite de peticiones). Pendiente M-05 (prueba con un cliente MCP real).
 **Corrección de revisión F2 intento 1 (B1, B2, A4, A7, A3, Throttle)**: (B1) la ventana de `findPublished` parte ahora del primer intento del destino (`PublishRequestFactory::searchWindowStart`: mínimo entre `scheduled_at` y el primer `publish_attempts.started_at`, menos 5 min), así mover la fecha con «Publicar ahora», `reschedule()` o `update()` tras un intento ambiguo no deja fuera lo ya publicado (sin tocar `PublishPostTarget` ni `app/Connectors/Meta`); `FakePublisher` respeta `since` y dos tests Pest lo reproducen (publishNow y cambio solo de fecha: 1 publicación; sin el cambio fallan con 2). (B2) «Publicar ahora» pone `next_attempt_at = null` en los destinos `programada` con reintento transitorio; un destino en pausa por cuota no se fuerza y la respuesta dice «Hay destinos en pausa por la cuota de la red…»; el sondeo del calendario quita el id de `sending` al agotar las vueltas. (A7) el compositor pasa `publishing_post_id` por la sesión flash y el calendario sondea ese post como si se hubiera publicado desde él. (Throttle) limitador con nombre `publish-now` (30/min por usuario) compartido por `posts.publish-now` y por `brands.posts.store`/`posts.update` cuando llevan `publish_now`. (A4) E2E-06 cancela con botón y con Escape también en semana y mes, con axe en las tres vistas con la confirmación abierta (arreglado un contraste de las tarjetas no editables del mes). Sustituye a la nota anterior «`throttle:30,1`». **Evidencia real**: `php artisan test --compact` → 1295 passed; `PublishNowEndpoint` (incluye cuota y reintento transitorio y 429 compartido) y `PublishNowDoubleDispatch` (9) en verde; `composer.spec.ts` → desktop 16, mobile 16, mobile-360 16 passed.
---

### T-14 — Guardar en biblioteca también con el post sin guardar

- **Descripción**: Un endpoint que llama a `LibraryService::store` con el payload completo del compositor (texto, media y destinos), pide un nombre y no crea ningún post.
- **Changelog**: Guardar en biblioteca funciona también con la publicación sin guardar y con cambios pendientes: pide un nombre, guarda texto, archivos y redes, y no crea ninguna publicación.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 2.5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.4h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-11
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/LibraryController.php`, `app/Services/LibraryService.php`, `routes/web.php`, `resources/js/Components/Composer/ActionBar.vue`, `resources/js/Composables/useComposer.js`, `tests/Feature/Library/LibrarySaveFromComposerTest.php`
- **Cubre (tests)**: E2E-07
- **Verificación**:
  - `php artisan test --compact --filter=LibrarySaveFromComposer` → en verde (no se crea ningún `posts`)
  - `npm run test:e2e -- --grep "E2E-07"` → pasa

**Criterios de aceptación**
- [x] CA-26: se crea la plantilla con texto, media y destinos, sin crear un post
- [x] El nombre es obligatorio y la marca ajena se rechaza

**Subtareas**
- [x] Endpoint y validación
- [x] Diálogo de nombre en `ActionBar`
- [x] Test de no creación de post

**Notas**: **Evidencia**: `php artisan test --compact --filter=LibrarySaveFromComposer` → 6 passed (crea la plantilla con texto, media y destinos; `Post::count() === 0`; nombre obligatorio; marca ajena 404; descarta cuentas y media de otro usuario y tipos de otra red; solo texto; sin sesión). E2E-07 (`composer.spec.ts --project=desktop` → 13 passed): con texto, dos redes y SIN guardar el post, «Guardar en biblioteca» abre el diálogo de nombre, guarda, el usuario sigue en el compositor con el aviso, la plantilla aparece en la Biblioteca y el calendario no tiene publicaciones nuevas. Ruta nueva `POST brands/{brand}/library` (`brands.library.store`, `LibraryController::storeFromComposer` → `LibraryService::saveFromComposer`); la ruta `library.store` con `post_id` (calendario) no cambia. Desviaciones: (1) el diálogo de nombre y el botón viven en `ActionBar` (menú del botón dividido) y se elimina el aviso de B2 de la Fase 1 («guarda antes»), que ya no aplica porque se envía el contenido actual; el test E2E de B2 se sustituye por E2E-07; (2) el E2E de E2E-07 no sube media (la subida real escribe en el disco de datos; la media entra por Pest). El texto por destino entra en la biblioteca en T-22.

---

### T-15 — Tests que fijan que «Publicar ahora» no encola ni publica dos veces

- **Descripción**: Riesgo principal del plan. Casos: (1) `publishNow()` y `DispatchDuePosts` en el mismo minuto y luego los dos jobs: el publicador se llama una vez; (2) doble petición `publish-now` seguida; (3) Publicar ahora sobre un destino `publicando`; (4) Publicar ahora desde el endpoint del post y el despacho del minuto a la vez; (5) el job corre antes del commit simulado y el destino queda `programada` y vencido.
- **Changelog**: Hay pruebas que garantizan que Publicar ahora, el despachador de cada minuto, una doble petición o un reintento nunca publican la misma publicación dos veces.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 1.8h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.3h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-12, T-13
- **Tipo**: test
- **Archivos**: `tests/Feature/Publishing/PublishNowDoubleDispatchTest.php`, `tests/Feature/Publishing/PublishingTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PublishNowDoubleDispatch` → en verde, con `Http::fake` contando una sola subida por destino
  - `php artisan test --compact tests/Feature/Publishing` → en verde

**Criterios de aceptación**
- [x] Un destino con Publicar ahora y el despachador de ese minuto produce exactamente una llamada de publicación
- [x] Un segundo `publish-now` sobre un post en vuelo se rechaza sin encolar
- [x] Los tests fallan si se quita el `claim()` o `ShouldBeUnique` (comprobado una vez a mano y anotado)

**Subtareas**
- [x] Montar el escenario con `Queue::fake` y con la cola `sync` para ejecutar ambos jobs
- [x] Contar llamadas al `Publisher` falso
- [x] Comprobar la mutación quitando una defensa

**Notas**: **Evidencia**: `php artisan test --compact --filter=PublishNowDoubleDispatch` → 7 passed (41 assertions); `php artisan test --compact tests/Feature/Publishing` → 83 passed (las suites existentes sin modificar; `PublishingTest.php` no se toca). Casos: (1) `publishNow()` + `DispatchDuePosts` del mismo minuto con `Queue::fake` → `assertPushed(PublishPostTarget, 1)` y, ejecutando el job dos veces, `FakePublisher::publishCalls === 1`; (2) cola sync real: publica una vez y el despachador ya no encuentra nada; (3) dos peticiones `publish-now` seguidas → un solo encolado y una sola publicación, y una tercera sobre el destino ya publicado se rechaza; (4) destino `publicando` → rechazado sin encolar y un job que llegue sale en el claim; (5) endpoint + despachador del minuto en cualquier orden (3 pasadas, 1 encolado); (6) job que corre antes del commit ve el borrador y no publica, y tras `publishNow()` el destino está `programada` y vencido (`dueTargets`); (7) tras un timeout ambiguo, el reintento consulta `findPublished` y no duplica. **Mutación comprobada a mano una vez** (ejecutada y revertida con `git`; `git diff --stat -- app/Jobs/PublishPostTarget.php` → vacío): (a) quitando `ShouldBeUnique` de `PublishPostTarget` fallan 3 de 7 (el job se encola 2 y 3 veces en lugar de 1); (b) quitando de `claim()` la guarda `else { return null; }` (un destino ya publicado o en vuelo se vuelve a reclamar) fallan 4 de 7 (`publishCalls` 2 o 3 en lugar de 1, y el job adelantado publica con el estado viejo). Con las dos defensas restauradas: 7 passed.

---

### T-16 — E2E de C-08 (CA-24..CA-27) con axe y sin desbordamiento

- **Descripción**: E2E de los cuatro criterios de C-08 y de Publicar ahora desde el calendario y la lista, con la infraestructura existente. Nunca publica en una red real: comprueba el estado «Enviando…» y la respuesta con cuentas del entorno `APP_ENV=e2e`, no la publicación.
- **Changelog**: Hay pruebas automáticas del panel de errores, el selector de fecha, el botón dividido, Publicar ahora (compositor, calendario y lista) y Guardar en biblioteca, con accesibilidad y sin desbordamiento en móvil.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.3h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-09, T-10, T-11, T-13, T-14
- **Tipo**: test
- **Archivos**: `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/flows.spec.ts`
- **Cubre (tests)**: E2E-04, E2E-05, E2E-06, E2E-07, E2E-12, A11Y-01, A11Y-02
- **Verificación**:
  - `npx playwright test tests/e2e/composer.spec.ts` → 16 passed en desktop, mobile y mobile-360
  - `npx playwright test tests/e2e/pages.spec.ts --project=desktop -g "calendario|conexiones de la marca"` → 4 passed (sustituye al barrido completo, que no incluye axe; la accesibilidad del compositor y del calendario con la confirmación abierta está en `composer.spec.ts`: E2E-04, E2E-06 en lista, semana y mes, A11Y-01)

**Criterios de aceptación**
- [x] Un test por CA-24..CA-27 y por la acción del calendario y la lista
- [x] `expectNoOverflow` a 375 y 360 px
- [x] Ningún test toca al usuario id 28

**Subtareas**
- [x] Escribir los E2E
- [x] Cuentas de prueba sin red real
- [x] axe en calendario con la acción nueva

**Notas**: **Evidencia** (por la restricción de memoria: solo `--project=desktop`, y el compositor una vez en `mobile-360`; el proyecto `mobile` 375 no se ha ejecutado en esta fase): `npx playwright test tests/e2e/composer.spec.ts --project=desktop` → 13 passed; `--project=mobile-360` → 13 passed (E2E-04, E2E-05, E2E-06, E2E-07, E2E-12 y A11Y-01/A11Y-02 con axe sin violaciones serias o críticas y `expectNoOverflow`); `npx playwright test tests/e2e/pages.spec.ts --project=desktop -g "calendario|conexiones de la marca"` → 4 passed (el barrido de `pages.spec.ts` no incluye axe: la accesibilidad del compositor y del calendario con la confirmación abierta vive en `composer.spec.ts`: E2E-04 con el menú y el selector abiertos, E2E-06 con la confirmación). Cada test usa solo usuarios `e2e-sec-*` y marcas `e2e-composer-*` (`cleanComposerBrands` las borra y retira de la cola `jobs` solo los `PublishPostTarget` de esos destinos, coincidencia exacta de `targetId`: Publicar ahora encola de verdad y nada E2E debe publicarse si hubiera un worker). Ningún dato del usuario id 28 tocado. Cubre también la aserción aplazada de E2E-02 (31 hashtags → Programar deshabilitado y Guardar borrador habilitado). Desviaciones: (1) los E2E de la Fase 1 afectados por esta fase se adaptan aquí: «Guardar borrador» pasa al menú (`guardarBorrador`), los `role=alert` de errores pasan al panel (`errors-panel`) y el test de B2 («biblioteca exige guardar antes») se sustituye por E2E-07; (2) `flows.spec.ts` (sesión demo) lleva el mismo ajuste del menú pero NO se ha ejecutado: crea y borra marcas del usuario demo y no se toca esa cuenta; (3) E2E-07 no sube media.
 **Corrección de revisión F2 intento 1 (A2, A3, A4, A5, A8, A9, B3, B4)**: la nota de evidencia anterior queda sustituida por esta. Se ejecuta `npx playwright test tests/e2e/composer.spec.ts` una vez en cada proyecto: desktop 16 passed, mobile (375) 16 passed, mobile-360 16 passed (el proyecto `mobile`, que faltaba, ya está). Novedades: E2E-04 con marca en `Pacific/Auckland` y reloj fijado (A2) y paso de fecha pasada (A1); E2E-04 respaldo nativo (A9); E2E-04 sin franjas hoy (B4); E2E-05 subida en curso (B3); E2E-06 cancelar en semana y mes y axe con la confirmación abierta en lista, semana y mes (A4/A11Y-02); E2E-07 comprueba los destinos Instagram y Facebook de la plantilla (A8). `flows.spec.ts` (sesión demo) NO se ha ejecutado (A5): usa el usuario demo, que puede ser el id 28 cuyos datos no se tocan, y crea y borra marcas; el ajuste del menú de esa batería queda sin verificar. `pages.spec.ts`: la Verificación original (barrido con axe) no es posible porque ese barrido no incluye axe; se sustituye por `-g "calendario|conexiones de la marca"` (4 passed) y por los E2E con axe de `composer.spec.ts`; la Verificación de esta tarea ya lo dice así. Hallazgo aparte (no corregido, fuera de alcance): `posts.scheduled_at` es `timestamp` de MySQL y una fecha posterior a 2038 provoca un 500 en el servidor en lugar de un error de validación.
---

### T-17 — Revisión de robustez de la Fase 2 (doble publicación, permisos y estados)

- **Descripción**: Revisión dirigida: doble despacho (web, calendario, MCP y despachador), post ajeno por IDOR en `publish-now` y `publish_post_now`, acción sobre un post no editable, carrera entre `publishNow()` y una edición, límite de peticiones de la acción y que el job no se encole antes del commit. Checklist firmada; cada hallazgo lleva su test.
- **Changelog**: Publicar ahora ya no puede duplicar una publicación tras un fallo ambiguo, funciona también con reintentos pendientes y respeta la pausa por cuota; el compositor avisa si la fecha es pasada.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2.0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.6h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0.15h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-15, T-16
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `tests/Feature/Publishing/PublishNowReviewTest.php`, `app/Services/PostService.php`, `app/Http/Controllers/PostController.php`, `app/Mcp/Tools/PublishPostNow.php`, `app/Services/PublishRequestFactory.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/CalendarController.php`, `routes/web.php`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Components/Composer/ActionBar.vue`, `resources/js/Components/Composer/DateTimePicker.vue`, `resources/js/Components/Composer/EditorToolbar.vue`, `resources/js/Components/Composer/ScheduleSection.vue`, `resources/js/Components/MediaUploader.vue`, `tests/Support/FakePublisher.php`, `tests/Feature/Posts/PublishNowEndpointTest.php`, `tests/Feature/Publishing/PublishNowDoubleDispatchTest.php`, `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `php artisan test --compact --filter=PublishNow` → todos los casos adversariales fijados
  - `npm run test:e2e` → batería completa en verde
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [x] La suite completa pasa sin desactivar ni modificar tests existentes
- [x] Cada hallazgo Important o Critical tiene test y corrección
- [x] `PublishPostTarget` sigue sin cambios

**Subtareas**
- [x] Revisar IDOR y estados
- [x] Revisar carreras y `afterCommit`
- [x] Suite completa, Pint y E2E

**Notas**: Puerta de salida de la fase 2. Revisión de dos lentes, intento 1, corregida: ver la sección «Revisión de dos lentes — intento 1: Fase 2 (T-09..T-16)» al final. Nota sobre «`PublishPostTarget` sigue sin cambios»: cumplido (no se ha tocado, ni `app/Connectors/Meta`); la corrección de B1 vive en `PublishRequestFactory`. **Evidencia**: `php artisan test --compact` → 1295 passed; `php artisan test --compact --filter=PublishNow` → verde (PublishNowEndpoint, PublishNowDoubleDispatch, PublishPostNowTool); `vendor/bin/pint --test` → passed; `npm run build` → OK; `npx tsc --noEmit -p .` → sin errores; `composer.spec.ts` → 16 passed en desktop, mobile y mobile-360 (la batería E2E completa no se ejecuta por la restricción de memoria: solo el compositor, en los tres proyectos).

---


## Fase 3 — C-03 · Texto por red

**Estado**: borrador · **Estimado**: 32,0h · **Real**: — · **Coste est.**: 1.600 € + tokens (⚠️ verificar) · **Tokens est.**: 1,29M

### T-18 — Migración `post_targets.text`, modelo y reglas de `PostRequest`

- **Descripción**: Migración **solo aditiva** (`text` nullable; `null` = vinculado a la plantilla), `fillable` y `PostTarget::effectiveText()` (texto propio si no es `null`, si no `post->text`), y las reglas `targets.*.text` en `PostRequest::rules()`, la única fuente que reutiliza el MCP.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-17
- **Tipo**: db
- **Archivos**: `database/migrations/2026_11_09_090000_add_text_to_post_targets_table.php`, `app/Models/PostTarget.php`, `app/Http/Requests/PostRequest.php`, `tests/Unit/PostTargetEffectiveTextTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan migrate:fresh --env=testing --no-interaction` → migra sin error y existe la columna `post_targets.text`
  - `php artisan test --compact tests/Unit/PostTargetEffectiveTextTest.php` → vinculado, desvinculado y cadena vacía
  - `php artisan test --compact tests/Feature/Posts tests/Feature/Mcp` → en verde

**Criterios de aceptación**
- [ ] La migración solo añade: no altera filas existentes ni borra nada
- [ ] Un destino con `text = ''` (texto propio vacío) se distingue de uno `null` (vinculado)
- [ ] Ningún test ni migración toca al usuario id 28

**Subtareas**
- [ ] Migración aditiva y `down()` simétrica
- [ ] `effectiveText()`
- [ ] Reglas de `PostRequest` (máximo y tipos)

**Notas**: —

---

### T-19 — `plan()` y `fill()` por texto efectivo: reinicio por destino, texto viejo para la reconciliación y `schedule()`

- **Descripción**: En `plan()` se valida el texto efectivo de cada destino (máximos y hashtags). En `fill()` el «contenido cambiado» se decide por destino y el texto efectivo **viejo** se calcula antes de cualquier escritura para guardarlo en `progress.reconcile.text` vía `freshAttempt()`. `schedule()` y `publishNow()` reconstruyen los destinos desde la BD y **copian `text`**, o al programar un borrador se perdería el texto propio.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0,94h · real —
- **Supervisión**: est. 0,24h (≈25 % IA) · real —
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-18
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Services/MediaValidator.php`, `tests/Feature/Posts/TargetTextPlanFillTest.php`, `tests/Feature/Publishing/TargetTextFreshAttemptTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=TargetTextPlanFill` → en verde (CA-10: editar el texto propio de un destino reinicia solo ese)
  - `php artisan test --compact --filter=TargetTextFreshAttempt` → en verde (el texto viejo correcto en `reconcile.text` en los tres casos)
  - `php artisan test --compact tests/Feature/Posts tests/Feature/Publishing` → en verde

**Criterios de aceptación**
- [ ] Plantilla editada: solo se reinician los destinos vinculados; texto propio editado: solo ese destino
- [ ] `reconcile.text` contiene el texto efectivo viejo, nunca el nuevo
- [ ] Programar un borrador conserva el texto propio de cada destino
- [ ] Editar un destino sin cambios no reinicia ningún intento

**Subtareas**
- [ ] Calcular el texto efectivo viejo por destino antes de `fill()`
- [ ] Cambio por destino en `fill()`
- [ ] Copiar `text` en `schedule()` y `publishNow()`
- [ ] Pest de los tres casos

**Notas**: Es el cálculo de mayor riesgo del plan: un texto viejo mal guardado hace que un reintento no reconozca lo ya publicado.

---

### T-20 — `PublishRequestFactory` con el texto efectivo y tests anti-duplicado en Instagram y Facebook

- **Descripción**: `make()` usa `$target->effectiveText()` y `makeReconcile()` el texto efectivo viejo. Los publicadores no cambian. Tests con `Http::fake` de **reintento tras un timeout** en Instagram (`InstagramPublisher::findPublished` compara la descripción) y Facebook (`FacebookPublisher::findPublished`): (a) destino desvinculado, (b) plantilla editada entre intentos, (c) texto propio editado entre intentos, (d) destino vinculado. En todos, el reintento reconoce lo ya publicado y hace una sola publicación.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: backend
- **Archivos**: `app/Services/PublishRequestFactory.php`, `tests/Feature/Publishing/TargetTextIdempotencyInstagramTest.php`, `tests/Feature/Publishing/TargetTextIdempotencyFacebookTest.php`, `tests/Feature/Publishing/RevisionFase3Intento3Test.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=TargetTextIdempotencyInstagram` → en verde: una sola publicación por destino
  - `php artisan test --compact --filter=TargetTextIdempotencyFacebook` → en verde: una sola publicación por destino
  - `git diff --stat -- app/Connectors/Meta` → vacío (los publicadores de Meta no cambian)
  - `php artisan test --compact tests/Feature/Publishing` → en verde

**Criterios de aceptación**
- [ ] CA-11: un reintento tras un timeout con texto propio no duplica en Instagram ni en Facebook
- [ ] Los cuatro escenarios (a-d) están cubiertos en cada red
- [ ] Los tests fallan si `make()` vuelve a usar `$post->text` (comprobado una vez a mano y anotado)

**Subtareas**
- [ ] `make()` y `makeReconcile()`
- [ ] `Http::fake` con timeout en la creación y el medio ya publicado en el listado
- [ ] Los cuatro escenarios por red

**Notas**: Aviso del diseño: dos destinos de la misma cuenta con el mismo texto efectivo ya podían confundirse; se documenta, no se arregla aquí.

---

### T-21 — `NetworkTabs`: pestañas Plantilla y por destino, desvincular y volver a vincular

- **Descripción**: Pestañas ARIA (Plantilla y una por destino), desvincular y vincular, confirmación «Se perderá el texto propio de esta red» al volver a vincular, y contadores y errores calculados con el texto efectivo.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0,94h · real —
- **Supervisión**: est. 0,24h (≈25 % IA) · real —
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/NetworkTabs.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Components/Composer/NetworkCounters.vue`, `resources/js/Components/Composer/ErrorsPanel.vue`
- **Cubre (tests)**: E2E-08, M-02, M-03
- **Verificación**:
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-08"` → pasa en los tres proyectos

**Criterios de aceptación**
- [ ] CA-08: al desvincular Facebook y escribir su texto, su contador usa ese texto y el de Instagram, la plantilla
- [ ] CA-09: al volver a vincular y confirmar, el texto propio se borra
- [ ] Operable con teclado (flechas entre pestañas)

**Subtareas**
- [ ] Patrón de pestañas accesible
- [ ] Desvincular y vincular con confirmación
- [ ] Texto efectivo en contadores y errores

**Notas**: La previsualización por red (C-02) queda fuera de alcance.

---

### T-22 — Biblioteca con el texto propio por destino y primer texto efectivo en el calendario y la lista

- **Descripción**: `LibraryService::store()` y `use()` conservan `text` por destino (compatible con las plantillas ya guardadas, que no lo traen). `CalendarService` muestra el primer texto efectivo si la plantilla está vacía.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: backend
- **Archivos**: `app/Services/LibraryService.php`, `app/Http/Controllers/LibraryController.php`, `app/Services/CalendarService.php`, `resources/js/Pages/Calendar/Index.vue`, `tests/Feature/Library/LibraryTargetTextTest.php`, `tests/Feature/Calendar/CalendarEffectiveTextTest.php`
- **Cubre (tests)**: E2E-08
- **Verificación**:
  - `php artisan test --compact --filter=LibraryTargetText` → en verde (incluye una plantilla antigua sin `text`)
  - `php artisan test --compact --filter=CalendarEffectiveText` → en verde

**Criterios de aceptación**
- [ ] CA-12 (parte de biblioteca): guardar y usar una plantilla conserva los textos propios
- [ ] Las plantillas guardadas antes siguen funcionando
- [ ] Un post con todos los destinos desvinculados muestra el primer texto efectivo

**Subtareas**
- [ ] `targets` con `text` en `PostTemplate`
- [ ] `use()` crea el borrador con los textos
- [ ] `CalendarService` y la lista

**Notas**: —

---

### T-23 — Paridad del MCP: texto por destino en `PostPayload`, `get_post`, `create_post` y `update_post`

- **Descripción**: El esquema de `PostPayload` y la salida de `presentPost()` incluyen `text` y `effective_text` por destino. Las reglas siguen siendo `PostRequest::rules()`.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/SchedulerTool.php`, `app/Mcp/Tools/GetPost.php`, `app/Mcp/Tools/CreatePost.php`, `app/Mcp/Tools/UpdatePost.php`, `tests/Feature/Mcp/Tools/McpTargetTextTest.php`, `docs/modulos/mcp.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=McpTargetText` → en verde (crear, actualizar, leer, máximo por red)
  - `php artisan test --compact tests/Feature/Mcp` → en verde

**Criterios de aceptación**
- [ ] CA-12: `create_post`, `update_post` y `get_post` aceptan y devuelven el texto por destino con las mismas reglas que la web
- [ ] Un error de validación por destino sale igual que en la web

**Subtareas**
- [ ] Esquema y presentación
- [ ] Tests de las tres herramientas
- [ ] Actualizar `docs/modulos/mcp.md`

**Notas**: Regla de paridad: cada cambio de modelo del plan toca el MCP en su misma fase.

---

### T-24 — E2E y Pest de C-03 (CA-08..CA-12)

- **Descripción**: E2E del flujo desvincular, escribir, guardar, reabrir y volver a vincular, con axe y sin desbordamiento. Pest del guardado y la lectura de `targets.*.text`.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-21, T-22, T-23
- **Tipo**: test
- **Archivos**: `tests/e2e/composer.spec.ts`, `tests/Feature/Posts/Compose/TargetTextTest.php`
- **Cubre (tests)**: E2E-08, E2E-12
- **Verificación**:
  - `npm run test:e2e -- --grep "E2E-08"` → verde en los tres proyectos
  - `php artisan test --compact tests/Feature/Posts/Compose` → en verde

**Criterios de aceptación**
- [ ] Un test por CA-08, CA-09 y CA-12
- [ ] Reabrir el post conserva el texto propio
- [ ] Sin desbordamiento a 375 y 360 px

**Subtareas**
- [ ] E2E
- [ ] Pest de guardado y lectura

**Notas**: —

---

### T-25 — Revisión de robustez de la Fase 3 (doble publicación por texto propio e idempotencia)

- **Descripción**: Puerta de salida de la fase y del riesgo principal: recorre cada camino que escribe `text` (web, MCP, biblioteca, `schedule`, `publishNow`, `update`) buscando uno que no pase por `fill()`; texto propio vacío, solo espacios o con emojis compuestos frente al `trim()` de la idempotencia de Instagram y Facebook; YouTube y TikTok (su idempotencia no usa el texto); cambio de vinculado a desvinculado entre intentos. Checklist firmada; cada hallazgo lleva su test.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-24
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `tests/Feature/Publishing/TargetTextReviewTest.php`, `app/Services/PostService.php`, `app/Services/PublishRequestFactory.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `php artisan test --compact --filter=TargetText` → todos los casos fijados
  - `npm run test:e2e` → batería completa en verde
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [ ] La suite completa pasa sin desactivar ni modificar tests existentes
- [ ] Cada hallazgo Important o Critical tiene test y corrección
- [ ] Los publicadores de Meta, YouTube y TikTok siguen sin cambios

**Subtareas**
- [ ] Auditar los caminos de escritura de `text`
- [ ] Casos de borde de `trim()` y unicode
- [ ] Suite completa, Pint y E2E

**Notas**: Puerta de salida de la fase 3.

---


## Fase 4 — C-04 · Opciones por red persistentes (presets)

**Estado**: borrador · **Estimado**: 27,0h · **Real**: — · **Coste est.**: 1.350 € + tokens (⚠️ verificar) · **Tokens est.**: 1,09M

### T-26 — Verificar en la documentación vigente los campos ⚠️ de YouTube, TikTok e Instagram

- **Descripción**: Consulta la documentación oficial vigente de `notifySubscribers`, `status.containsSyntheticMedia`, `selfDeclaredMadeForKids`, los límites de tags de `videos.insert`, los campos de TikTok (`disable_duet`, `disable_stitch`, `brand_organic_toggle`, `brand_content_toggle`, `is_aigc`) y si la Graph API de Instagram tiene un campo de etiqueta IA. Registra versión, fecha y URL. Lo no confirmado queda oculto (CA-16), no fingido.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-25
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/testing/verificacion-campos-c04.md`, `config/media_specs.php`
- **Cubre (tests)**: M-04
- **Verificación**:
  - lectura: `testing/verificacion-campos-c04.md` lista cada campo con veredicto (Confirmado, No confirmado o No existe), versión de API, fecha y URL
  - `grep -c "Confirmado\|No confirmado\|No existe" docs/roadmap/2026-10-23-post-composer/testing/verificacion-campos-c04.md` → al menos 9

**Criterios de aceptación**
- [ ] Cada campo ⚠️ tiene veredicto y fuente
- [ ] Se fija qué opciones entran en T-27 y T-28 y cuáles se ocultan

**Subtareas**
- [ ] Consultar la documentación de Google, TikTok y Meta
- [ ] Escribir el informe
- [ ] Fijar la lista de opciones aprobadas

**Notas**: —

---

### T-27 — Opciones de YouTube en `openSession` conservando el marcador de idempotencia

- **Descripción**: Categoría, tags del usuario, `selfDeclaredMadeForKids` y las opciones confirmadas en T-26 (`notifySubscribers`, etiqueta IA). El marcador `targetTag` va **siempre primero** y se reserva su hueco: los tags del usuario se recortan para no pasar de ≈ 500 caracteres. Las reglas entran en `PostRequest`.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-26
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `app/Http/Requests/PostRequest.php`, `tests/Feature/Publishing/YouTubeOptionsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=YouTubeOptions` → en verde con `Http::fake` (CA-13: la petición lleva los valores y conserva `targetTag` en `tags`)
  - `php artisan test --compact tests/Feature/Publishing` → en verde (`findPublished` sigue encontrando el vídeo por el marcador)

**Criterios de aceptación**
- [ ] CA-13: la petición de subida lleva categoría, hecho para niños, tags y las opciones confirmadas
- [ ] El marcador `targetTag` es el primer tag aunque el usuario ponga muchos
- [ ] `findPublished` reconoce un vídeo con tags del usuario

**Subtareas**
- [ ] Mapear las opciones en `openSession`
- [ ] Recorte de tags con el hueco del marcador
- [ ] Test con tags largos

**Notas**: Marcar «hecho para niños» desactiva los comentarios en YouTube: aviso en la UI (T-30).

---

### T-28 — Opciones de TikTok en `postInfo` (solo publicación directa)

- **Descripción**: `disable_duet`, `disable_stitch`, `brand_organic_toggle`, `brand_content_toggle` y `is_aigc` (según T-26) solo cuando la cuenta publica en directo. En modo borrador no se envían. El publicador mantiene su regla de no abrir más de lo pedido.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-26
- **Tipo**: backend
- **Archivos**: `app/Connectors/TikTok/TikTokPublisher.php`, `app/Http/Requests/PostRequest.php`, `tests/Feature/Publishing/TikTokOptionsTest.php`
- **Cubre (tests)**: M-04
- **Verificación**:
  - `php artisan test --compact --filter=TikTokOptions` → en verde con `Http::fake` (CA-14)
  - `php artisan test --compact tests/Feature/Publishing` → en verde

**Criterios de aceptación**
- [ ] CA-14: `post_info` lleva `disable_duet`, `disable_stitch` y `brand_organic_toggle`
- [ ] Con la cuenta en modo borrador esas opciones no se envían ni se ofrecen
- [ ] La divulgación comercial respeta los avisos exigidos por la auditoría de TikTok

**Subtareas**
- [ ] Mapear opciones en `postInfo`
- [ ] Condicionarlo a `direct_post`
- [ ] Test de modo borrador

**Notas**: —

---

### T-29 — Persistencia de presets: tabla `composer_presets`, `ComposerPresetService` y valores iniciales

- **Descripción**: Migración **solo aditiva** (`composer_presets`, único `brand_id, network`) y `ComposerPresetService::forBrand()` / `remember()`, que `store` y `update` llaman al guardar. Solo interruptores: título, tags y enlace no persisten. El MCP **no** escribe presets (decisión del usuario). Una marca ajena no se lee ni se escribe.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-28
- **Tipo**: db
- **Archivos**: `database/migrations/2026_11_10_090000_create_composer_presets_table.php`, `app/Models/ComposerPreset.php`, `app/Services/ComposerPresetService.php`, `app/Http/Controllers/PostController.php`, `tests/Feature/Posts/ComposerPresetsTest.php`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan migrate:fresh --env=testing --no-interaction` → migra sin error
  - `php artisan test --compact --filter=ComposerPresets` → en verde (CA-15: marca A recuerda, marca B no; textos vacíos; marca ajena rechazada)
  - `php artisan test --compact tests/Feature/Posts tests/Feature/Mcp` → en verde

**Criterios de aceptación**
- [ ] CA-15: el interruptor guardado en la marca A aparece en un post nuevo de A y no en B
- [ ] Los campos de texto no persisten
- [ ] Un usuario no puede leer ni escribir presets de una marca ajena

**Subtareas**
- [ ] Migración aditiva
- [ ] Modelo con ámbito de usuario
- [ ] `remember()` en `store` y `update`
- [ ] Props con los presets

**Notas**: —

---

### T-30 — `PresetsPanel`: opciones globales y por red, ocultando lo que no aplica

- **Descripción**: Interruptores accesibles por red (YouTube, TikTok y lo ya existente de Instagram), con la regla de ocultar lo no admitido (modo borrador de TikTok, opciones no confirmadas en T-26) y el aviso de «hecho para niños».
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-29
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/PresetsPanel.vue`, `resources/js/Composables/useComposer.js`
- **Cubre (tests)**: E2E-09, M-02, M-03
- **Verificación**:
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-09"` → pasa en los tres proyectos

**Criterios de aceptación**
- [ ] CA-16: la etiqueta IA solo aparece en las redes cuya API la acepta
- [ ] Interruptores con nombre accesible y estado anunciado
- [ ] Sin desbordamiento a 375 y 360 px

**Subtareas**
- [ ] Panel por red
- [ ] Reglas de visibilidad
- [ ] Aviso de comentarios desactivados

**Notas**: —

---

### T-31 — Paridad del MCP: `options` en `PostPayload` y `get_post`

- **Descripción**: El esquema `options` entra en `PostPayload` y `presentPost()`, con las mismas reglas de `PostRequest`. Solo las opciones confirmadas en T-26.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-28
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/SchedulerTool.php`, `tests/Feature/Mcp/Tools/McpOptionsTest.php`, `docs/modulos/mcp.md`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=McpOptions` → en verde
  - `php artisan test --compact tests/Feature/Mcp` → en verde

**Criterios de aceptación**
- [ ] Una opción no admitida por la cuenta da error de validación del destino, igual que en la web
- [ ] `get_post` devuelve las opciones

**Subtareas**
- [ ] Esquema
- [ ] Test
- [ ] Documentar

**Notas**: —

---

### T-32 — Pest y E2E de C-04 (CA-13..CA-16)

- **Descripción**: E2E con dos marcas `e2e-`: guardar una opción en A, abrir un post nuevo en A y B, y comprobar que la etiqueta IA no aparece donde no se admite. Con axe y sin desbordamiento.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-30, T-31
- **Tipo**: test
- **Archivos**: `tests/e2e/composer.spec.ts`, `tests/Feature/Posts/Compose/PresetsTest.php`
- **Cubre (tests)**: E2E-09, E2E-12
- **Verificación**:
  - `npm run test:e2e -- --grep "E2E-09"` → verde en los tres proyectos
  - `php artisan test --compact tests/Feature/Posts/Compose` → en verde

**Criterios de aceptación**
- [ ] Un test por CA-14 (UI), CA-15 y CA-16
- [ ] Las marcas de prueba se borran con `borrarMarcasE2e`

**Subtareas**
- [ ] E2E con dos marcas
- [ ] Pest de presets

**Notas**: —

---

### T-33 — Revisión de robustez de la Fase 4 (marcador de YouTube, aislamiento de presets y divulgación de TikTok)

- **Descripción**: Revisión de: expulsión del marcador `targetTag` con tags largos o unicode; opciones que llegan por MCP y no por la UI; aislamiento de `composer_presets` entre marcas y usuarios; TikTok nunca más abierto de lo pedido; mass-assignment de `options`. Checklist firmada; cada hallazgo lleva su test.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `tests/Feature/Publishing/PresetsReviewTest.php`, `app/Connectors/Google/YouTubePublisher.php`, `app/Services/ComposerPresetService.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `php artisan test --compact --filter=PresetsReview` → casos adversariales fijados
  - `npm run test:e2e` → batería completa en verde
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [ ] La suite completa pasa sin desactivar ni modificar tests existentes
- [ ] Cada hallazgo Important o Critical tiene test y corrección

**Subtareas**
- [ ] Auditar el recorte de tags
- [ ] Auditar el aislamiento de presets
- [ ] Suite completa, Pint y E2E

**Notas**: Puerta de salida de la fase 4.

---


## Fase 5 — C-09 notas y C-05a generador UTM

**Estado**: borrador · **Estimado**: 14,0h · **Real**: — · **Coste est.**: 700 € + tokens (⚠️ verificar) · **Tokens est.**: 0,57M

### T-34 — Notas internas: migración `posts.notes`, `PostService::updateNotes` y `PATCH posts/{post}/notes`

- **Descripción**: Migración **solo aditiva** (`notes` text nullable, máximo 5.000 caracteres, texto plano). `updateNotes()` y `PATCH posts/{post}/notes` funcionan aunque el post no sea editable y no tocan los destinos. Las notas **no** entran en la comparación de «contenido cambiado» de `fill()`.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-33
- **Tipo**: backend
- **Archivos**: `database/migrations/2026_11_11_090000_add_notes_to_posts_table.php`, `app/Models/Post.php`, `app/Services/PostService.php`, `app/Http/Requests/PostRequest.php`, `app/Http/Controllers/PostController.php`, `routes/web.php`, `tests/Feature/Posts/PostNotesTest.php`
- **Cubre (tests)**: API-02
- **Verificación**:
  - `php artisan migrate:fresh --env=testing --no-interaction` → migra sin error
  - `php artisan test --compact --filter=PostNotes` → en verde (publicado, en vuelo, 5.001 caracteres rechazado, post ajeno, `fill()` no reinicia intentos)
  - `php artisan test --compact tests/Feature/Posts tests/Feature/Publishing` → en verde

**Criterios de aceptación**
- [ ] CA-28 (servidor): guardar una nota en un post publicado no toca los destinos
- [ ] Editar una nota no reinicia ningún intento
- [ ] Una nota de un post ajeno se rechaza

**Subtareas**
- [ ] Migración aditiva
- [ ] `updateNotes()` y ruta
- [ ] Excluir `notes` de `fill()`

**Notas**: —

---

### T-35 — `NotesPanel`, indicador en el calendario y MCP (`get_post` y `update_post_notes`)

- **Descripción**: Panel lateral con texto plano y contador, indicador de nota en la tarjeta del calendario, `notes` en `presentPost()` y la herramienta MCP `update_post_notes` (`post_id`, `notes`), editable aunque el post no lo sea y registrada en `SchedulerServer`.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-34
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/NotesPanel.vue`, `resources/js/Pages/Calendar/Index.vue`, `app/Services/CalendarService.php`, `app/Mcp/Tools/SchedulerTool.php`, `app/Mcp/Tools/UpdatePostNotes.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `docs/modulos/mcp.md`
- **Cubre (tests)**: E2E-10, M-05
- **Verificación**:
  - `php artisan test --compact --filter=McpNotes` → en verde (paridad con la web, `NotOwned`, límite)
  - `npm run test:e2e -- --grep "E2E-10"` → pasa en los tres proyectos

**Criterios de aceptación**
- [ ] CA-28: el calendario muestra un indicador de nota y `get_post` la devuelve
- [ ] El indicador tiene texto accesible y no solo un icono
- [ ] `update_post_notes` respeta las mismas reglas que la web

**Subtareas**
- [ ] Panel y guardado
- [ ] Indicador en las tres vistas del calendario
- [ ] Herramienta MCP

**Notas**: —

---

### T-36 — `UtmModal`: generador de UTM en el cliente

- **Descripción**: Modal accesible con URL, `utm_source`, `utm_medium`, `utm_campaign` y, opcionales, `utm_term` y `utm_content`. Inserta en el cursor la URL con los parámetros codificados. Añadir queda deshabilitado sin los obligatorios. Todo en el cliente: no hay modelo ni endpoint (el acortador, C-05b, está aplazado).
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-33
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/UtmModal.vue`, `resources/js/Composables/utm.js`, `resources/js/Components/Composer/EditorToolbar.vue`, `tests/e2e/composer.spec.ts`
- **Cubre (tests)**: E2E-11, M-03
- **Verificación**:
  - `npm run build` → sin errores
  - `npm run test:e2e -- --grep "E2E-11"` → pasa en los tres proyectos

**Criterios de aceptación**
- [ ] CA-17: se inserta en el cursor la URL con los parámetros codificados; sin los obligatorios, Añadir está deshabilitado
- [ ] Una URL que ya trae parámetros conserva los suyos y no duplica `utm_*`
- [ ] Un valor con espacios o `&` se codifica

**Subtareas**
- [ ] Función `buildUtmUrl`
- [ ] Modal con foco atrapado
- [ ] Test de codificación

**Notas**: —

---

### T-37 — Pest y E2E de notas y UTM (CA-17, CA-28)

- **Descripción**: E2E de una nota en un post publicado y de la inserción UTM, con axe y sin desbordamiento. Smoke de la ruta de notas.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-35, T-36
- **Tipo**: test
- **Archivos**: `tests/e2e/composer.spec.ts`, `tests/Feature/Posts/PostNotesTest.php`
- **Cubre (tests)**: E2E-10, E2E-11, E2E-12, API-02
- **Verificación**:
  - `npm run test:e2e -- --grep "E2E-10|E2E-11"` → verde en los tres proyectos
  - `php artisan test --compact tests/Feature/Posts` → en verde

**Criterios de aceptación**
- [ ] Un test por CA-17 y CA-28
- [ ] Sin desbordamiento a 375 y 360 px

**Subtareas**
- [ ] E2E
- [ ] Smoke de `PATCH posts/{post}/notes`

**Notas**: —

---

### T-38 — Revisión de robustez de la Fase 5 (notas fuera de `fill()`, XSS y permisos)

- **Descripción**: Revisión de: notas que reinician intentos por colarse en `fill()`, notas con HTML o scripts mostradas en calendario y compositor, edición de notas de posts ajenos por web y MCP, y un UTM que inserte una URL peligrosa (`javascript:`). Checklist firmada; cada hallazgo lleva su test.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-37
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `tests/Feature/Posts/NotesUtmReviewTest.php`, `app/Services/PostService.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `npm run test:e2e` → batería completa en verde
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [ ] La suite completa pasa sin desactivar ni modificar tests existentes
- [ ] Cada hallazgo Important o Critical tiene test y corrección

**Subtareas**
- [ ] Auditar `fill()` y XSS
- [ ] Auditar permisos web y MCP
- [ ] Suite completa, Pint y E2E

**Notas**: Puerta de salida de la fase 5.

---


## Fase 6 — C-11 · Cierre: E2E, axe, MCP, documentación y smoke

**Estado**: borrador · **Estimado**: 12,0h · **Real**: — · **Coste est.**: 600 € + tokens (⚠️ verificar) · **Tokens est.**: 0,48M

### T-39 — Adaptar `flows.spec.ts` y `pages.spec.ts` al nuevo compositor, con axe

- **Descripción**: Actualiza los selectores de los flujos que usan el compositor, añade el compositor y el calendario al barrido de axe y comprueba que ninguna página nueva desborda a 375 y 360 px.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-38
- **Tipo**: test
- **Archivos**: `tests/e2e/flows.spec.ts`, `tests/e2e/pages.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/composer.spec.ts`
- **Cubre (tests)**: E2E-12, A11Y-01, A11Y-02
- **Verificación**:
  - `npm run test:e2e` → batería completa en verde (desktop, mobile y mobile-360)
  - `npx tsc --noEmit -p .` → 0 errores

**Criterios de aceptación**
- [ ] CA-32: axe sin violaciones serias ni críticas en compositor y calendario
- [ ] Los E2E existentes siguen en verde
- [ ] Solo usuarios `e2e-sec-*`; ninguna referencia al usuario id 28

**Subtareas**
- [ ] Adaptar `flows.spec.ts`
- [ ] Añadir páginas al barrido de axe
- [ ] Ejecutar la batería completa

**Notas**: —

---

### T-40 — Regresión de las herramientas MCP de posts y documentación

- **Descripción**: Documenta lo nuevo en la guía de usuario, `publicacion.md` (incluida la nota de dos destinos de una cuenta con el mismo texto), `mcp.md` (`publish_post_now`, `update_post_notes`, `text`, `options`, `notes`) y `calendario.md`. Regresión de las herramientas MCP de posts.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-38
- **Tipo**: docs
- **Archivos**: `docs/usuario/guia-de-usuario.md`, `docs/modulos/publicacion.md`, `docs/modulos/mcp.md`, `docs/modulos/calendario.md`, `tests/Feature/Mcp/Tools/McpToolsTest.php`
- **Cubre (tests)**: M-05
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp` → en verde
  - `grep -n "publish_post_now\|update_post_notes" docs/modulos/mcp.md` → al menos 2 coincidencias
  - lectura: la guía describe el compositor, Publicar ahora, texto por red, presets, notas y UTM

**Criterios de aceptación**
- [ ] CA-33 (documentación): guía, `publicacion.md` y `mcp.md` describen lo nuevo
- [ ] La paridad web y MCP queda tabulada en `mcp.md`

**Subtareas**
- [ ] Guía de usuario
- [ ] Módulos
- [ ] Tabla de paridad

**Notas**: —

---

### T-41 — Smoke real: una publicación por red con las opciones aprobadas

- **Descripción**: Con el usuario: una publicación por red real con las opciones aprobadas (YouTube, TikTok en borrador, Instagram y Facebook con texto propio) y un Publicar ahora, registrado en `testing/`. Depende de las cuentas reales, de la cuota de YouTube (≈ 6 subidas al día) y de que TikTok siga en modo borrador.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-39, T-40
- **Tipo**: test
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/testing/smoke-real.md`
- **Cubre (tests)**: M-01, M-04
- **Verificación**:
  - lectura: `testing/smoke-real.md` registra, por red, la URL o el ID publicado, las opciones usadas y que no hay duplicados
  - lectura: el recuento de destinos publicados del smoke coincide con el listado de `posts` en la app

**Criterios de aceptación**
- [ ] CA-33 (smoke): una publicación por red con las opciones aprobadas registrada
- [ ] Ninguna publicación duplicada en Instagram ni en Facebook
- [ ] Se anotan las incidencias abiertas, no se ocultan

**Subtareas**
- [ ] Preparar las cuentas y la cuota
- [ ] Ejecutar con el usuario
- [ ] Registrar la evidencia

**Notas**: Puede quedar pendiente del usuario, como M-02..M-08 de la iniciativa anterior; el plan se cierra con M-01 y M-04 marcados como pendientes si es así.

---

### T-42 — Revisión final, regresión completa y cierre del plan

- **Descripción**: Suite Pest, Pint, build y Playwright completos; comprueba que lo declarado «sin cambios» sigue intacto (`PublishPostTarget`, publicadores de Meta) y que no se ha tocado ningún dato del usuario id 28. Entrega los M-xx como checklist, actualiza la spec, el índice del roadmap y el estado de ADR-005 (solo con el visto bueno del usuario). Puerta de salida de la fase 6.
- **Changelog**: 
- **Estado**: borrador
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-41
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-23-post-composer/tasks.md`, `docs/roadmap/2026-10-23-post-composer/improvement-plan.md`, `docs/roadmap/2026-10-23-post-composer/spec.md`, `docs/roadmap/README.md`, `docs/knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md`
- **Cubre (tests)**: M-01, M-02, M-03, M-04, M-05
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `npm run build && vendor/bin/pint --test` → sin errores
  - `npm run test:e2e` → batería completa en verde
  - `git diff --stat main -- app/Jobs/PublishPostTarget.php app/Connectors/Meta app/Http/Middleware/McpGuard.php` → vacío
  - `grep -rnE "find\(28\)|where\('id', *28\)|id *= *28" app database tests/e2e` → vacío (ningún código toca al usuario id 28)
  - `grep -rln "dropColumn\|Schema::drop" database/migrations/2026_11_09* database/migrations/2026_11_1*` → solo ficheros donde aparece dentro de `down()`

**Criterios de aceptación**
- [ ] Todos los criterios globales del plan cumplidos, salvo los M-xx pendientes del usuario
- [ ] Las migraciones del plan solo añaden
- [ ] Spec en `implementada` solo si el smoke está verificado por el usuario

**Subtareas**
- [ ] Regresión completa
- [ ] Comprobaciones de «sin cambios» y del usuario id 28
- [ ] Cierre de ledger, spec y roadmap

**Notas**: Puerta de salida de la fase 6.

---

## Revisión de dos lentes — intento 1: Fase 1 (T-01..T-07) — corregida

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|-------|-------|-----|-------|------------|-----------|
| B1 | Funcional | Important | `UnsavedGuard` no cubre el botón Atrás: Inertia gestiona `popstate` sin evento `before` | T-06 | Listener de `popstate` instalado antes que el de Inertia (`composables/backGuard.js`, desde `app.js`) que detiene su propagación, deshace el retroceso con `history.go(1)` y abre el diálogo; confirmar hace `history.back()`. Se eligió esto frente a `useRemember` porque conserva URL, foco y formulario sin remontar la página | E2E «el botón Atrás del navegador pide confirmación…» (cancelar conserva URL y texto; segundo Atrás; confirmar sale) → verde desktop y mobile-360 |
| B2 | Funcional | Important | «Guardar en biblioteca» con cambios sin guardar guardaba el texto anterior y salía | T-05 | El botón se desactiva con el formulario sucio y muestra «Guarda los cambios (borrador) antes…» (la biblioteca copia el texto guardado; coherente con no enviar borradores a medias) | E2E «Guardar en biblioteca exige guardar antes…» → verde |
| B3 | Código | Minor | `insertAtCursor` mezclaba offsets LF con CRLF | T-05 | El modelo se normaliza `
`→`
` al cargar y el cursor se calcula sobre `el.value` | E2E «el emoji se inserta en el cursor de un texto con varias líneas» → verde |
| B4 | Código | Minor | El contador contaba espacios de los extremos; el servidor recorta | T-04 | `countChars` cuenta el texto recortado; `MediaValidator` mide `$length` recortado también para el máximo | E2E contador recortado («   hola   » → 4 / 2.200) + Pest `MediaValidatorHashtags` (límite sobre texto recortado) → verde |
| B5 | Código | Minor | `leaving` no volvía a `false` si `router.visit` fallaba | T-06 | `onCancel`/`onError`/`onFinish` restauran `leaving` | revisión de código; cubierto por el E2E de salir (T-06) sin regresión |
| B6 | Tests | Minor | Título del test de hashtags contradecía lo que comprueba (`a#b`, `#` en URL) | T-03 | Regla: no cuenta un `#` precedido de letra, dígito, `_` o `/`, igual en PHP (`MediaValidator::countHashtags`) y JS (`textCount.js`); test corregido (3, no 4) y test nuevo de URL/`a#b` | `php artisan test --compact` → 1244 passed; E2E `a#b https://x.com/p#ancla #uno` → «1 / 30 #» |
| A1 | Plan | Aviso | Aserción aplazada «Programar deshabilitado con 31 hashtags» sin recoger | T-07/T-09 | Anotada en `test-plan.md` (E2E-02) y como criterio de T-09 | `test-plan.md`, T-09 |
| A2 | Plan | Aviso | Barra con botones con texto en lugar de solo iconos | T-05 | Desviación declarada en las Notas de T-05 | T-05 Notas |
| A3 | Tests | Aviso | Sin test del aviso al superar el máximo de caracteres | T-07 | E2E «contador… avisa al superar el máximo de caracteres» (2.201 / 2.200 · supera el máximo + alerta en vivo) | verde desktop y mobile-360 |
| A4 | Tests | Aviso | El test de media con teclado no activaba el control | T-05 | Se pulsa Espacio y se espera el evento `filechooser` | E2E-03 barra del editor → verde |
| A5/A6 | Ledger | Aviso | Verificaciones y evidencias de T-01/T-07 inexactas; resumen de Fase 1 | T-01, T-07 | Notas y Verificación corregidas; Fase 1 = 8/8 (100%) | ledger-lint |
| AX | A11y | Aviso | Sin `aria-live` en contadores; pastilla sin «Requiere reconexión» en su `aria-label` | T-04/T-02 | `aria-live="polite"` en contadores y recuento de hashtags; `aria-label` con «. Requiere reconexión» | axe verde en E2E-12 y A11Y-01 |

Hallazgo extra al probar A3: el texto largo sin espacios desbordaba horizontalmente la vista previa (17 000 px); corregido con `overflow-wrap:anywhere` en `PreviewPanel.vue`.

## Revisión de dos lentes — intento 1: Fase 2 (T-09..T-16) — corregida

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|-------|-------|-----|-------|------------|-----------|
| B1 | Funcional | Important | Doble publicación: tras un intento ambiguo, «Publicar ahora» (o cambiar la fecha) movía `scheduled_at` y `findPublished` buscaba desde la fecha nueva | T-13 | `PublishRequestFactory::searchWindowStart`: la ventana parte del primer intento del destino (mínimo con `scheduled_at`, −5 min); sin tocar `PublishPostTarget` ni `app/Connectors/Meta` | `FakePublisher` respeta `since`; 2 tests en `PublishNowDoubleDispatchTest` (publishNow y cambio solo de fecha → 1 publicación; fallan con 2 sin la corrección) |
| B2 | Funcional | Important | «Publicar ahora» con `next_attempt_at` futuro no encolaba nada pero decía «unos segundos»; el sondeo no quitaba el id de `sending` | T-13 | `releaseBackoff` pone `next_attempt_at = null` (no si `error_code = cuota`: mensaje «Hay destinos en pausa por la cuota…»); el sondeo vacía `sending` al agotar las vueltas | 2 tests en `PublishNowEndpointTest` (reintento transitorio se encola; cuota no se fuerza y el mensaje es honesto); suite 1295 passed |
| A1 | Accesibilidad | Important | La fecha pasada no entraba en el panel ni bloqueaba Programar en vivo | T-09 | `dateErrorList` en `useComposer` con la zona de la marca; bloquea Programar, no Borrador ni Publicar ahora | E2E-04 (paso de fecha pasada, reloj fijado) verde en los tres proyectos |
| A2 | Tests | Important | El test de zona horaria no distinguía la zona de la marca de la del navegador | T-10/T-16 | Marca `Pacific/Auckland`, navegador `Europe/Madrid`, reloj a las 20:00 UTC; el test exige que las dos fechas difieran | E2E-04 verde; el día «hoy» de Madrid sale deshabilitado |
| A3 | Ledger | Important | Faltaba el proyecto `mobile` y las Verificaciones de T-09, T-11, T-13 y T-16 no eran exactas | T-09, T-11, T-13, T-16 | Se ejecuta `composer.spec.ts` en desktop, mobile y mobile-360 y se corrigen Verificaciones y notas | desktop 16, mobile 16, mobile-360 16 passed |
| B3 | Funcional | Minor | Programar, Publicar ahora y Biblioteca seguían activos con una subida de media en curso | T-09/T-11 | `MediaUploader` emite `busy` → `useComposer.uploadingMedia` | E2E-05 «subida en curso» verde |
| B4 | Funcional | Minor | Sin franjas válidas hoy en la zona de la marca, «hoy» seguía elegible | T-10 | `todayExhausted` deshabilita hoy y el foco pasa a mañana (también el `min` del nativo) | E2E «ninguna franja hoy» verde |
| A4 | Tests | Minor | Cancelar la confirmación solo se probaba en lista y sin axe en semana y mes | T-16 | Cancelar con botón y Escape y axe con la confirmación abierta en lista, semana y mes; contraste de tarjetas no editables del mes corregido (`opacity-80` → fondo) | E2E-06 verde en los tres proyectos |
| A5 | Ledger | Minor | `flows.spec.ts` sin ejecutar y la Verificación de `pages.spec.ts` sustituida sin anotar | T-16 | `flows.spec.ts` no se ejecuta (sesión demo, posible usuario 28; queda anotado); la sustitución de `pages.spec.ts` queda escrita en la Verificación y las Notas | T-16 Verificación y Notas |
| A6 | Ledger | Minor | Sin evidencia de `tests/Feature/Posts` en T-10 | T-10 | Ejecutado y anotado | `php artisan test --compact tests/Feature/Posts` → 55 passed |
| A7 | Funcional | Minor | Sin «Enviando…» al volver al calendario tras «Publicar ahora» desde el compositor | T-13 | `publishing_post_id` en la sesión flash → `Calendar/Index.vue` lo añade a `sending` y sondea | Prop `flash.publishing_post_id`; E2E-05 verde |
| A8 | Tests | Minor | E2E-07 no comprobaba los destinos de la plantilla | T-16 | Comprueba Instagram y Facebook en la fila de la plantilla | E2E-07 verde |
| A9 | Tests | Minor | El respaldo `native` de `DateTimePicker` no tenía test | T-10/T-16 | `?selector=nativo` activa el `datetime-local`; E2E con `min`, fecha pasada y envío | E2E «respaldo nativo» verde |
| TH | Seguridad | Minor | `brands.posts.store` y `posts.update` con `publish_now` sin el mismo límite que `posts.publish-now` | T-13 | Limitador con nombre `publish-now` (30/min por usuario) en las tres rutas; solo cuenta `publish_now` en store/update | `PublishNowEndpointTest`: 30 peticiones pasan, la 31 da 429 y la ruta propia comparte el cupo |
