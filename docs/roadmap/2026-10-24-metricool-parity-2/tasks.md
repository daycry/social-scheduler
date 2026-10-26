---
generacion:
  inicio: 2026-10-24T14:24:58Z
  fin: 2026-10-24T14:34:55Z
  fuente: medido
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-6b00b1cb305a442dbe0216b444253a16
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 18, salida: 97737, cache_creacion: 100247, cache_lectura: 949955 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.66
  duracion: 40m
  ratio_usado: 300000   # default no calibrado   # ventana compartida con improvement-plan.md
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Paridad con Metricool, segunda ola

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-24 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (C-01b = A1, C-04 = B1) |
| **Plan de pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Las horas de este fichero son **base** (sin el +20 % de contingencia); el plan muestra las cifras con margen. Orden fijo (el de la evaluación): Fase 1 (C-01a) → Fase 2 (C-01b) → Fase 3 (C-02) → Fase 4 (C-03) → Fase 5 (C-04a) → Fase 6 (C-04b) → Fase 7 (transversal y cierre). Tokens a ≈ 40,5k por hora base (90 % entrada, 10 % salida) y horas IA = tokens / 300.000 (ratio no calibrado), como la evaluación.

> **Restricciones que valen para todas las tareas:**
>
> - **Alcance cerrado** (aprobado por el usuario): C-01a, C-01b (IG, FB y YT), C-02, C-03, C-04a y C-04b. D1: textos por usuario con marca opcional · D2: reconectará YouTube (`youtube.force-ssl`) · D3: campañas por marca y por post · D4: configurará Google Cloud · D5: cola `media` nueva con su worker.
> - **C-01b = A1** (columnas en `post_targets` y job `PostFirstComment`; el texto de un comentario `fallido` se puede editar antes de reintentar) y **C-04 = B1** (un solo pipeline URL/Drive por pasadas de 80 s con `Range` y `UrlGuard`). `PublishPostTarget` no se toca.
> - **Serie obligatoria** en `PostRequest`, `PostService` y `PostPayload`: C-01b (T-12, T-15) antes que C-03 (T-27, T-28).
> - **Migraciones solo aditivas** (T-01, T-08, T-24, T-34).
> - **Nunca redes reales:** Pest con `Http::fake`; Playwright solo con `e2e-sec-*` y marcas `e2e-`. El smoke real es del usuario (M-01..M-05).
> - **Entorno:** `MEDIA_DISK=local` en producción; UI en español; no se ejecutan servidores ni workers (Playwright levanta el suyo en T-50).
> - **Clase y primer oráculo.** Cada tarea declara si es *capacidad nueva* (primer oráculo: RED escrito antes del código) o *cambio de comportamiento* (primer oráculo: caracterización en verde antes de tocar).
> - **Sin Jira:** no hay `.claude/jira.json`.

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — C-01a · Textos guardados | 6 | 6 | 100% | 0 / 16,5h | 0.71 / 2,2h | 0 / 0,6h | 0 / 668k |
| Fase 2 — C-01b · Primer comentario (IG, FB y YT) | 12 | 12 | 100% | 0 / 35,5h | 2.25 / 4,8h (incl. 0.34 estimado de T-16; resto medido) | 0 / 1,2h | 0 / 1,44M |
| Fase 3 — C-02 · Cuadrícula de Instagram | 5 | 5 | 100% | 0 / 23,5h | 0.26 / 3,2h (incl. 0.05 estimado de T-19; resto medido) | 0 / 0,8h | 0 / 952k |
| Fase 4 — C-03 · Campañas | 9 | 9 | 100% | 0 / 36,5h | 1.36 / 4,9h | 0 / 1,2h | 0 / 1,48M |
| Fase 5 — C-04a · Media desde URL | 9 | 9 | 100% | 0 / 24,5h | 1.69 / 3,3h (estimado; sin medir) | 0 / 0,8h | 0 / 992k |
| Fase 6 — C-04b · Media desde Google Drive | 0 | 6 | 0% | 0 / 14,5h | 0 / 2,0h | 0 / 0,5h | 0 / 587k |
| Fase 7 — Transversal y cierre | 0 | 3 | 0% | 0 / 3,5h | 0 / 0,5h | 0 / 0,1h | 0 / 142k |
| **TOTAL** | **33** | **50** | **66%** | **0 / 154,5h** | **0 / 20,9h** | **0 / 5,2h** | **0 / 6,26M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real). No hay `.claude/jira.json`: no aplica.

> **Diferencias con la evaluación (declaradas).** Las horas por entrega son las de la evaluación (C-01a 17h, C-01b 36h, C-02 24h, C-03 37h, C-04a 25h, C-04b 15,5h = 154,5h base). Las tareas transversales **no suman horas nuevas**: salen de las partidas de documentación y revisión de cada entrega. T-48 (guía de Google Cloud, 1h) sale de C-04b; T-49 (0,5h) de C-04a; T-50 (regresión, 2h) de C-01a, C-01b, C-02 y C-03 (0,5h cada una). Las revisiones de robustez (T-18, T-41, T-47) también están dentro de las partidas de la evaluación. Total: **154,5h base = 185,4h con margen**.

## Conocimiento pendiente del Curator

Candidatos del `architect` pendientes de decisión. Se conservan la ruta y el hash de la propuesta original; el Curator devolverá el `operation_id` y el recibo al aplicarlos.

```curation-refs-v1
[{"candidate":"docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md","sha256":"1cfc13515922274563a93d43123a0d0fea46a6b6f989ee457cc0fb22d44bfa90"},{"candidate":"docs/knowledge/candidates/pending/2026-10-24-importacion-media-pipeline-unico-por-pasadas.md","sha256":"183d95537cc91beaf5fbb2c922598c08b989c1e557967e5029ad445f07be2df1"}]
```

---

## Fase 1 — C-01a · Textos guardados

**Estado**: completado · **Estimado**: 16,5h base (19,8h con margen) · **Real**: — · **Coste est.**: 990 € + tokens (⚠️ verificar) · **Tokens est.**: 668k

### T-01 — Migración, modelo y factoría de `SavedText`

- **Descripción**: Crear la tabla `saved_texts` (`user_id`, `brand_id` nulo, `kind` `fragmento`/`hashtags`, `title`, `body`) y el modelo con `UserScope`. Requisito: D1 (por usuario con marca opcional; nula = «Todas las marcas»), CA-01 y CA-03.
- **Changelog**: Nueva tabla de textos guardados por usuario, con marca opcional («Todas las marcas»).
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.16h (medido)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_saved_texts_table.php`, `app/Models/SavedText.php`, `database/factories/SavedTextFactory.php`, `tests/Feature/SavedTexts/SavedTextModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/SavedTexts/SavedTextModelTest.php` → todos en verde (aislamiento por usuario y `brand_id` nulo)

**Criterios de aceptación**
- [x] La migración es aditiva y reversible (`down()` borra solo `saved_texts`).
- [x] El modelo usa `UserScope`: otro usuario no ve filas ajenas.
- [x] `brand_id` nulo es válido; al borrar la marca se borran sus textos (`cascadeOnDelete`).

**Subtareas**
- [x] Escribir el test RED de aislamiento
- [x] Migración, modelo y factoría

**Notas**: Patrón: `app/Models/ComposerPreset.php` y el trait `app/Models/Concerns/BelongsToUser.php` (`UserScope`). Migraciones solo aditivas.

**Evidencia**: SavedTextModelTest: 3 passed (4 assertions); RED previo: Class App\Models\SavedText not found.

### T-02 — `SavedTextService`, request, controlador, política y rutas CRUD

- **Descripción**: CRUD de textos guardados con propiedad (otro usuario recibe 403) y validación de `kind`, título y cuerpo. Requisito: CA-03.
- **Changelog**: Se pueden crear, editar y borrar textos guardados; los de otro usuario devuelven 403.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real 0.05h (medido)
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `app/Services/SavedTextService.php`, `app/Http/Controllers/SavedTextController.php`, `app/Http/Requests/SavedTextRequest.php`, `app/Policies/SavedTextPolicy.php`, `routes/web.php`, `tests/Feature/SavedTexts/SavedTextCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/SavedTexts/SavedTextCrudTest.php` → todos en verde (alta, edición, borrado y 403 de otro usuario)

**Criterios de aceptación**
- [x] Alta, edición y borrado devuelven los códigos esperados y persisten.
- [x] Un usuario ajeno recibe 403 al editar o borrar (CA-03).
- [x] La marca, si se informa, debe ser del usuario.

**Subtareas**
- [x] Test RED del 403
- [x] Servicio, request y política
- [x] Rutas `settings/saved-texts*`

**Notas**: Patrón: `app/Services/ComposerPresetService.php` y `app/Policies/PostTemplatePolicy.php`. Rutas junto a las de presets en `routes/web.php`.

**Evidencia**: SavedTextCrudTest: 5 passed (24 assertions). Nota: tests escritos junto al código, sin RED previo registrado.

### T-03 — Página de ajustes «Textos guardados» con entrada de navegación

- **Descripción**: Página `SavedTexts/Index.vue`: lista, búsqueda, alta, edición y borrado, con filtro por marca o «Todas las marcas». UI en español. Requisito: CA-03.
- **Changelog**: Nueva página «Textos guardados» (menú de usuario) para crear, buscar, filtrar por marca, editar y borrar tus textos.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real 0.11h (medido)
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/SavedTexts/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Feature/Ui/SavedTextsPageTest.php`
- **Cubre (tests)**: E2E-01
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/SavedTextsPageTest.php` → verde (componente Inertia `SavedTexts/Index` con sus props)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] La página lista los textos del usuario y filtra por marca.
- [x] Alta, edición y borrado funcionan sin recargar la app.
- [x] Hay entrada de navegación hacia la página.

**Subtareas**
- [x] Test RED de la página Inertia
- [x] Componente Vue
- [x] Entrada de menú

**Notas**: Patrón de páginas de ajustes: `resources/js/Pages/Profile` y `resources/js/Pages/Library`. El E2E con axe va en T-05.

**Evidencia**: SavedTextsPageTest: 2 passed (28 assertions); RED previo: Inertia page component file [SavedTexts/Index] does not exist; npm run build exit 0.

### T-04 — Menú «Textos guardados» en el compositor e inserción en el cursor

- **Descripción**: Menú en `EditorToolbar` con los textos de la marca más los de «Todas las marcas»; inserción en el cursor del campo activo (plantilla, texto propio del destino o primer comentario) con «campo activo» en `useComposer.js`. Requisito: CA-01, CA-02.
- **Changelog**: El compositor tiene un menú «Textos guardados» que inserta tus fragmentos y hashtags en el cursor.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real 0.21h (medido; incl. fix1 0.05h)
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/EditorToolbar.vue`, `resources/js/Components/Composer/TextEditor.vue`, `resources/js/Components/Composer/SavedTextsMenu.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Pages/Posts/Compose.vue`, `app/Http/Controllers/PostController.php`, `tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php`
- **Cubre (tests)**: E2E-02
- **Verificación**:
  - `php artisan test --compact tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php` → verde (props del compositor: la marca A ve «Todas» y A, no B)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] El compositor de la marca A recibe los textos de «Todas las marcas» y los de A, y no los de B (CA-01).
- [x] Insertar en mitad del texto deja el contenido en el cursor y actualiza el contador de hashtags de Instagram (CA-02).
- [x] El «campo activo» se registra en `useComposer.js`, no por componente.

**Subtareas**
- [x] Test RED de las props por marca
- [x] `SavedTextsMenu.vue` y campo activo
- [x] Inserción en el cursor

**Notas**: Añadido `Compose.vue` a Archivos (declara la prop `savedTexts`; no estaba en el plan). Patrón: `resources/js/Composables/useComposer.js` y `textCount.js`; menú hermano `resources/js/Components/Composer/EmojiPicker.vue`. El campo del primer comentario (T-14) reutiliza el campo activo.

**Revisión 1**: `forBrand` carga `brand` en bloque (gap 5, sin N+1; test de conteo de consultas).

**Evidencia**: SavedTextsComposerPropsTest verde (tests/Feature/SavedTexts + Posts: 102 passed); RED previo: savedTexts ausente; npm run build exit 0. Inserción en cursor y contador: se cubren en E2E-02 (T-05).

### T-05 — E2E de textos guardados con axe y sin desbordamiento

- **Descripción**: Playwright de la página de ajustes y del menú del compositor en desktop, 375 y 360 px, con axe y `expectNoOverflow`. Requisito: CA-01..CA-03.
- **Changelog**: Textos guardados cubiertos con pruebas de navegador en escritorio y móvil, con accesibilidad y sin desbordamiento.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.15h (medido)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-03, T-04
- **Tipo**: test
- **Archivos**: `tests/e2e/saved-texts.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-01, E2E-02
- **Verificación**:
  - `npx playwright test tests/e2e/saved-texts.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/saved-texts.spec.ts --project=mobile-360` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [x] 0 violaciones `serious`/`critical` de axe.
- [x] Sin desbordamiento horizontal a 375 y 360 px.
- [x] Solo usuarios `e2e-sec-*` y marcas `e2e-`.

**Subtareas**
- [x] Spec del CRUD
- [x] Spec de inserción en el cursor

**Notas**: Patrón: `tests/e2e/composer.spec.ts` y los helpers de `tests/e2e/helpers.ts` (`loginAs`, `expectNoOverflow`, `borrarMarcasE2e`).

**Evidencia**: saved-texts.spec.ts: desktop 2/2, mobile 2/2, mobile-360 2/2 (E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test, tras npm run build); axe sin serious/critical y 0 px de desbordamiento. Requirio aplicar migrate en la BD de desarrollo.

### T-06 — Documentación y revisión de C-01a

- **Descripción**: Documentar los textos guardados (guía de usuario y módulo de publicación) y revisar propiedad y aislamiento antes de cerrar la entrega.
- **Changelog**: La guía de usuario explica cómo crear, filtrar e insertar textos guardados en el compositor.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.03h (medido)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-05
- **Tipo**: docs
- **Archivos**: `docs/usuario/guia-de-usuario.md`, `docs/modulos/publicacion.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "Textos guardados" docs/usuario/guia-de-usuario.md docs/modulos/publicacion.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [x] La guía explica cómo crear, filtrar por marca e insertar un texto.
- [x] El módulo describe la tabla y la política.
- [x] Revisión de propiedad hecha: ningún endpoint devuelve textos ajenos.

**Subtareas**
- [x] Redactar documentación
- [x] Revisión de robustez de propiedad

**Notas**: lectura: contrastar con CA-01..CA-03.

---

**Evidencia**: grep "Textos guardados": guia 1 coincidencia, modulo 1. Revision de propiedad: SavedTextCrudTest (403 ajeno en update y delete), SavedTextModelTest y list/forBrand filtran por user_id: ningun endpoint devuelve textos ajenos.

## Fase 2 — C-01b · Primer comentario (IG, FB y YT)

**Estado**: completado · **Estimado**: 35,5h base (42,6h con margen) · **Real**: — · **Coste est.**: 2.130 € + tokens (⚠️ verificar) · **Tokens est.**: 1,44M

### T-07 — Scope `youtube.force-ssl` y detección de reconexión

- **Descripción**: Añadir `youtube.force-ssl` a los scopes de YouTube y detectar las cuentas conectadas sin él: el primer comentario de YouTube se desactiva con «Reconecta el canal para comentar». Requisito: D2 (el usuario reconectará) y diseño §6.
- **Changelog**: YouTube pide el permiso de comentarios al conectar y avisa de que hay que reconectar el canal si se conectó antes.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.06h (medido)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `config/social.php`, `app/Connectors/Google/YouTubePublisher.php`, `tests/Feature/Connections/YouTubeCommentScopeTest.php`
- **Cubre (tests)**: M-02
- **Verificación**: `php artisan test --compact tests/Feature/Connections/YouTubeCommentScopeTest.php` → verde (scope pedido y aviso sin scope)

**Criterios de aceptación**
- [x] El conjunto de scopes de YouTube incluye `youtube.force-ssl` y conserva los anteriores.
- [x] Una cuenta sin el scope se marca como «no puede comentar» sin romper la publicación.
- [x] Los tests de conexiones existentes siguen en verde.

**Subtareas**
- [x] Caracterización de los scopes actuales
- [x] Cambio de scopes y comprobación al estilo `hasScopes`

**Notas**: `config/social.php:60-62` (scopes de YouTube). Patrón de comprobación: `Comments::hasScopes` en `app/Connectors/Meta/Comments.php:32`. La reconexión invalida los tokens actuales (riesgo aceptado en D2).

**Evidencia**: YouTubeCommentScopeTest + GoogleOAuthTest: 21 passed; RED previo: force-ssl ausente de los scopes y canComment() inexistente.


### T-08 — Migración aditiva del estado del comentario en `post_targets` y enum `FirstCommentStatus`

- **Descripción**: Seis columnas nulas en `post_targets` (`first_comment`, `first_comment_status`, `first_comment_attempts`, `first_comment_external_id`, `first_comment_error`, `first_comment_at`) con índice `(first_comment_status, first_comment_at)` y el enum `FirstCommentStatus` (pendiente · comentando · publicado · fallido · no_admitido). Requisito: diseño, opción A1.
- **Changelog**: Los destinos guardan el primer comentario y su estado, sin afectar al estado de la publicación.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.04h (medido)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_add_first_comment_to_post_targets.php`, `app/Models/PostTarget.php`, `app/Enums/FirstCommentStatus.php`, `tests/Feature/FirstComment/FirstCommentColumnsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment/FirstCommentColumnsTest.php` → verde (columnas, casts y valores del enum)

**Criterios de aceptación**
- [x] La migración solo añade columnas nulas y se revierte sin perder datos.
- [x] `PostTarget` castea el estado al enum.
- [x] El estado del comentario es independiente de `PostStatus`.

**Subtareas**
- [x] Test RED de columnas y casts
- [x] Migración, enum y modelo

**Notas**: Patrón de enums: `app/Enums/`. Modelo: `app/Models/PostTarget.php`. Diseño §5 (A1: columnas en `post_targets`, no tabla aparte).

**Evidencia**: FirstCommentColumnsTest: 4 passed; RED previo: columnas y enum inexistentes. migrate aplicada en dev.


### T-09 — Contrato `FirstCommenter` y `comment()`/`findOwnComment()` en Instagram y Facebook

- **Descripción**: Interfaz `App\Connectors\Contracts\FirstCommenter`, `ConnectorRegistry::commenterFor()` y su implementación en `InstagramPublisher` y `FacebookPublisher` sobre `Meta/Comments` (buscar con `fetch()` + `is_own` + texto normalizado). Requisito: CA-04, diseño A1.
- **Changelog**: Instagram y Facebook pueden dejar un comentario propio y detectar si ya existe, para no duplicarlo.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.40h (medido; incl. fix1 0.14h, compartido con T-10, y fix2 0.10h)
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Connectors/Contracts/FirstCommenter.php`, `app/Connectors/ConnectorRegistry.php`, `app/Connectors/Meta/Comments.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php`, `app/Connectors/Meta/Facebook/FacebookPublisher.php`, `app/Connectors/Exceptions/CommentNotAllowedException.php`, `tests/Feature/Connectors/FirstCommenterMetaTest.php`, `app/Connectors/Exceptions/CommentSearchInconclusiveException.php`, `tests/Support/FakeGraph.php`, `config/social.php` (revisión 1: búsqueda acotada del comentario propio, excepción de búsqueda inconclusa y `FakeGraph::commentsPage`)
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/FirstCommenterMetaTest.php` → verde con `Http::fake` (comenta, encuentra el propio, mapea errores)

**Criterios de aceptación**
- [x] `comment()` devuelve el id del comentario y `findOwnComment()` lo encuentra por `is_own` y texto.
- [x] Los errores de Graph se traducen al español; «comentarios cerrados» se mapea a `no_admitido`.
- [x] `Publisher` no cambia y TikTok no implementa el contrato.

**Subtareas**
- [x] Test RED con `Http::fake`
- [x] Interfaz y registro
- [x] Instagram y Facebook

**Notas**: `app/Connectors/Meta/Comments.php:50` (`fetch`), `:133` (`reply`), `:151` (`normalize` con `is_own`). Pendiente de verificar en la documentación de Meta: el comentario de Reels de Facebook va sobre el id del vídeo.

**Revisión 1**: `Comments::findOwn` pasa a búsqueda acotada (solo nivel superior, `social.first_comment.find_pages`/`find_budget_seconds`), orden `chronological` en Facebook (Instagram no documenta `order`: recorrido con tope) y lanza `CommentSearchInconclusiveException` si no llega al final (gaps 3 y 4). Decisión: ante búsqueda inconclusa el job NO crea a ciegas en reintentos o recuperación (reintenta con backoff y, agotado, queda fallido); solo en el primer intento, donde nada pudo enviarse antes, crea.

**Evidencia**: FirstCommenterMetaTest 8 passed + tests/Feature/Connectors/Meta: 98 passed; RED previo: 0 passed (contrato inexistente).


### T-10 — `comment()` y `findOwnComment()` en YouTube con cuota

- **Descripción**: `commentThreads.insert` y `commentThreads.list?videoId=…` filtrado por `authorChannelId` en `YouTubePublisher`, con las 50 unidades de cuota en `YouTubeQuota`. Requisito: CA-04, D2.
- **Changelog**: YouTube puede dejar el primer comentario en el vídeo publicado, con control de cuota y aviso de vídeos que no admiten comentarios.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.15h (medido; incl. fix1 0.06h)
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-07, T-09
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeQuota.php`, `tests/Feature/Connectors/FirstCommenterYouTubeTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/FirstCommenterYouTubeTest.php` → verde con `Http::fake` (inserta, encuentra el propio; privado o «para niños» → `no_admitido`)

**Criterios de aceptación**
- [x] Comentar descuenta 50 unidades de cuota.
- [x] Vídeo privado o «hecho para niños» da `no_admitido`.
- [x] Sin el scope no se llama a la API.

**Subtareas**
- [x] Test RED con `Http::fake`
- [x] Implementación y cuota

**Notas**: Patrón de llamadas y cuota: `app/Connectors/Google/YouTubePublisher.php` (`beginPass` en `:413`). Verificado en la documentación oficial: `commentThreads.insert` exige `youtube.force-ssl` y cuesta 50 unidades.

**Revisión 1**: `findOwnComment` traduce 403 `commentsDisabled`/404 `videoNotFound` a `CommentNotAllowedException` (gap 1, test por el camino del job con `YouTubePublisher` real). `commentThreads.list` solo admite `order=time|relevance` (sin cronológico ascendente): se usa `searchTerms` con el texto, hasta `find_pages` páginas, más una página reciente sin filtro; cuota de la búsqueda 1 + 1 por llamada (gap 4).

**Evidencia**: FirstCommenterYouTubeTest + tests/Feature/Connectors/Google: 75 passed; RED previo: 0 passed. Corrige de paso un ¿ mal codificado de T-07 en YouTubePublisher.


### T-11 — Job `PostFirstComment`, enganche en `published()` y recuperación

- **Descripción**: Job `PostFirstComment` (`ShouldBeUnique`, cola `poll`, reclamo con `lockForUpdate`) encolado desde `PublishOutcomes::published()` con `afterCommit`; idempotencia en tres capas, backoff `[60, 300]`, tope de 3 intentos, espera a que el vídeo de YouTube esté publicado y paso 6 de `PublishRecovery`. Un fallo no degrada el destino. Requisito: CA-04, CA-05; diseño A1.
- **Changelog**: El primer comentario se publica solo tras publicar el post, sin duplicarse, y si falla no afecta a la publicación.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real 0.36h (medido; incl. fix1 0.06h y fix2 0.10h)
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-08, T-09, T-10
- **Tipo**: backend
- **Archivos**: `app/Jobs/PostFirstComment.php`, `tests/Support/FakeCommenter.php`, `app/Services/PublishOutcomes.php`, `app/Services/PublishRecovery.php`, `config/publishing.php`, `tests/Feature/FirstComment/PostFirstCommentJobTest.php`, `tests/Feature/FirstComment/FirstCommentRecoveryTest.php`, `database/migrations/2026_11_14_090000_add_first_comment_sent_at_to_post_targets.php`, `app/Models/PostTarget.php` (revisión 2: marca `first_comment_sent_at` escrita antes del POST; aviso FirstCommentFailed en la rama sin `external_id`)
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/FirstComment/PostFirstCommentJobTest.php` → verde (repetir el job no crea un segundo comentario; un fallo deja el destino `Published`)
  - `php artisan test --compact tests/Feature/Publishing/PublishingTest.php` → verde (el camino de publicación no cambia)
  - `php artisan test --compact tests/Feature/FirstComment/FirstCommentRecoveryTest.php` → verde

**Criterios de aceptación**
- [x] Si ya hay `first_comment_external_id`, el job no hace nada; siempre busca el comentario propio antes de crear uno.
- [x] El destino sigue `Published` ante cualquier fallo del comentario; el comentario queda `fallido` con el motivo en español.
- [x] `PublishPostTarget` no se modifica y `published()` es el único punto de entrada.

**Subtareas**
- [x] Caracterización de `PublishingTest` y `PublishNowDoubleDispatchTest`
- [x] Test RED de idempotencia
- [x] Job, enganche y recuperación

**Notas**: `app/Services/PublishOutcomes.php:38` (`published`). Reclamo: copiar el `claim()` de `app/Jobs/PublishPostTarget.php`. Idempotencia: `tests/Feature/Publishing/TargetTextIdempotencyInstagramTest.php`. Recuperación: `app/Services/PublishRecovery.php:32` (`run`).

**Revisión 1**: un `Throwable` con la pasada agotada cierra en `fallido` con motivo y aviso (gap 2); `PublishRecovery::resumeComments` cierra en `fallido` con motivo los `pendiente`/`comentando` sin `external_id` en vez de relanzarlos (gap 6); búsqueda inconclusa gestionada en el job (gap 3).

**Evidencia**: tests/Feature/FirstComment + tests/Feature/Publishing: 163 passed (PublishingTest en verde antes y despues, PublishNowDoubleDispatchTest incluido); RED previo: 16 de 17 fallaban (job inexistente). pint --test: passed.


### T-12 — `PostRequest`, `PostService::plan()`/`fill()` y máximos en `media_specs`

- **Descripción**: Regla `targets.*.first_comment`, máximo por tipo en `config/media_specs.php` (IG 2.200, FB 8.000, YT 10.000) y `fill()` sin reiniciar el intento de publicación cuando solo cambia el comentario; no se ofrece en TikTok ni en Stories. Requisito: CA-06.
- **Changelog**: Cada destino de Instagram, Facebook y YouTube admite un primer comentario con el máximo de su red; TikTok y las Stories no.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.12h (medido)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `config/media_specs.php`, `tests/Feature/FirstComment/FirstCommentValidationTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/FirstComment/FirstCommentValidationTest.php` → verde (máximos por red; TikTok y Stories rechazados)
  - `php artisan test --compact tests/Feature/Posts/TargetTextPlanFillTest.php` → verde (el texto por destino no cambia)

**Criterios de aceptación**
- [x] Se rechaza un comentario por encima del máximo de su red.
- [x] TikTok y Stories no admiten `first_comment`.
- [x] Cambiar solo `first_comment` no llama a `freshAttempt()`.

**Subtareas**
- [x] Caracterización de `TargetTextPlanFillTest`
- [x] Test RED de máximos
- [x] Regla, `plan()` y `fill()`

**Notas**: Patrón: validación de texto por destino en `app/Services/PostService.php` (`plan()` en `:460`) y límites en `config/media_specs.php`. Comparte ficheros con C-03: va antes (en serie).

**Evidencia**: FirstComment+Posts+Mcp: 337 passed (TargetTextPlanFillTest en verde antes y despues); RED previo: 10 de 11 fallaban. pint --test: passed.


### T-13 — `FirstCommentService`: editar, reintentar y aviso in-app

- **Descripción**: Servicio con `update()` (solo si el comentario está `fallido`; no toca `text` ni el intento) y `retry()`, rutas `PATCH posts/{post}/targets/{target}/first-comment` y `POST …/first-comment/retry` con `throttle` y política del post, y la notificación `FirstCommentFailed` (canal `database`). Requisito: CA-05 y la decisión del usuario de poder editar el comentario fallido antes de reintentar (A1).
- **Changelog**: Si el primer comentario falla puedes editar su texto y reintentarlo desde la web, y recibes un aviso en la aplicación del fallo.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.23h (medido; incl. fix2 0.11h)
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-12
- **Tipo**: backend
- **Archivos**: `app/Services/FirstCommentService.php`, `app/Http/Controllers/FirstCommentController.php`, `app/Notifications/FirstCommentFailed.php`, `resources/js/Pages/Notifications/Index.vue`, `routes/web.php`, `tests/Feature/FirstComment/FirstCommentRetryTest.php`, `resources/js/Components/Composer/TargetStatusPanel.vue`, `app/Http/Controllers/PostController.php` (revisión 2: «Ya está publicado» y «Publicar igualmente» con confirmación; `first_comment_may_exist` en los props)
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment/FirstCommentRetryTest.php` → verde (edita solo en `fallido`, reintenta con el texto vigente, 403 ajeno, aviso creado)

**Criterios de aceptación**
- [x] `update()` rechaza si el comentario no está `fallido`.
- [x] `retry()` reinicia intentos, despacha el job y usa el texto vigente.
- [x] Un fallo genera una notificación in-app.

**Subtareas**
- [x] Test RED del flujo editar y reintentar
- [x] Servicio, rutas y política
- [x] Notificación

**Notas**: Patrón de notificaciones: `app/Notifications/PublishFailed.php`. Política: `app/Policies/PostPolicy.php`. Es la única edición admitida en un destino `Published` y no pasa por `lockEditableTargets()`.

**Evidencia**: FirstCommentRetryTest + FirstComment + Publishing + Notifications en verde; RED verificado retirando servicio y controlador: 11 de 13 fallaban (el test se escribio tras el codigo, RED retrospectivo). pint --test: passed.


### T-14 — UI del primer comentario: campo por destino, contador, estado, editar y Reintentar

- **Descripción**: Campo `textarea` de primer comentario por destino con contador por red, oculto en TikTok y Stories, con inserción de textos guardados, estado y botones Editar/Reintentar en `TargetStatusPanel`. Requisito: CA-05, CA-06.
- **Changelog**: El compositor permite escribir un primer comentario por destino (Instagram, Facebook y YouTube), con contador, estado, edición y Reintentar si falla.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.2h (medido)
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-04, T-12, T-13
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/NetworkTabs.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/TargetStatusPanel.vue`, `resources/js/Composables/useComposer.js`, `tests/Feature/Posts/Compose/ComposeTest.php`, `resources/js/Components/Composer/FirstCommentField.vue`, `resources/js/Pages/Posts/Compose.vue`, `app/Http/Controllers/PostController.php`
- **Cubre (tests)**: E2E-03, E2E-04, M-01
- **Verificación**:
  - `php artisan test --compact tests/Feature/Posts/Compose/ComposeTest.php` → verde (props del destino con `first_comment` y su estado)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] El campo aparece en IG, FB y YT, y no en TikTok ni en Stories.
- [x] El estado del comentario se ve en `TargetStatusPanel`, con Reintentar y edición del texto cuando está `fallido`.
- [x] El contador respeta el máximo de la red.

**Subtareas**
- [x] Test RED de props
- [x] Campo y contador
- [x] Estado, editar y Reintentar

**Notas**: Patrón: `resources/js/Components/Composer/TargetStatusPanel.vue` y `TargetOptions.vue`. La inserción de textos guardados reutiliza el campo activo de T-04.

**Evidencia**: ComposeTest + Posts + FirstComment: 141 passed; RED previo: 2 de 18 fallaban sin el controlador. npm run build OK. UI verificada por E2E en T-17. pint --test: passed.


### T-15 — Paridad MCP del primer comentario (`PostPayload`, `create_post`, `update_post`, `get_post`)

- **Descripción**: Esquema `targets.*.first_comment` en `PostPayload` y estado del comentario en `GetPost`. `update_post` sobre un post publicado sigue rechazando `first_comment`. Requisito: CA-07.
- **Changelog**: El MCP admite el primer comentario por destino al crear y editar publicaciones, y get_post devuelve el comentario y su estado.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.07h (medido)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/GetPost.php`, `app/Mcp/Tools/CreatePost.php`, `app/Mcp/Tools/UpdatePost.php`, `app/Mcp/Tools/SchedulerTool.php`, `tests/Feature/Mcp/Tools/McpFirstCommentTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpFirstCommentTest.php` → verde (crea con `first_comment` y `get_post` lo devuelve con estado)
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpTargetTextTest.php` → verde (la paridad de texto por destino no cambia)

**Criterios de aceptación**
- [x] Las reglas de validación son las de `PostRequest::rules()`.
- [x] `get_post` devuelve `first_comment`, `first_comment_status` y `first_comment_error`.
- [x] Editar el comentario fallido por MCP queda fuera de esta iteración.

**Subtareas**
- [x] Caracterización de `McpTargetTextTest`
- [x] Test RED de paridad
- [x] Esquema y salida

**Notas**: `app/Mcp/Tools/PostPayload.php:35` (`validatedPayload` ya reutiliza `PostRequest::rules()`). Patrón de test: `tests/Feature/Mcp/Tools/McpTargetTextTest.php`. Tarea transversal de paridad MCP.

**Evidencia**: tests/Feature/Mcp: 218 passed (McpTargetTextTest en verde antes y despues); RED previo: el test de create/get fallaba sin first_comment en la salida. Editar el comentario fallido por MCP queda fuera. pint --test: passed. Nota de alcance: se toca tambien SchedulerTool.php (presentPost).


### T-16 — Pest de integración del primer comentario con `Http::fake`

- **Descripción**: Casos CA-04..CA-07 de extremo a extremo con redes simuladas: «creado pero no listado», doble despacho, error de red, reconexión y reintento.
- **Changelog**: Cobertura de extremo a extremo del primer comentario en Instagram, Facebook y YouTube con redes simuladas.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-13, T-15
- **Tipo**: test
- **Archivos**: `tests/Feature/FirstComment/FirstCommentFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment` → todos en verde

**Criterios de aceptación**
- [x] Nunca se llama a una red real.
- [x] El caso «la lista de Meta aún no muestra el comentario» no duplica.
- [x] Cubre IG, FB y YT.

**Subtareas**
- [x] Escenarios de flujo
- [x] Casos de borde de idempotencia

**Notas**: Patrón: `tests/Feature/Publishing/TargetTextIdempotencyFacebookTest.php`.

**Evidencia**: tests/Feature/FirstComment: todos en verde (FirstCommentFlowTest 9 casos IG/FB/YT con FakeGraph/FakeHttp, sin red real); fichero inexistente antes (oraculo: ausente).


### T-17 — E2E y documentación del primer comentario

- **Descripción**: Playwright con axe del campo, el estado y Reintentar (destino sembrado con un helper), a 375 y 360 px, más la documentación del módulo y de la reconexión de YouTube. Requisito: CA-05, CA-06.
- **Changelog**: La guía de usuario y la documentación del módulo explican el primer comentario: estados, edición, reintento y la reconexión de YouTube.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.24h (medido)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-14, T-15
- **Tipo**: test
- **Archivos**: `tests/e2e/first-comment.spec.ts`, `tests/e2e/helpers.ts`, `docs/modulos/publicacion.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: E2E-03, E2E-04
- **Verificación**:
  - `npx playwright test tests/e2e/first-comment.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/first-comment.spec.ts --project=mobile-360` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [x] 0 violaciones `serious`/`critical` de axe.
- [x] Sin desbordamiento a 375 y 360 px.
- [x] La documentación explica estados, edición y reconexión de YouTube.

**Subtareas**
- [x] Helper de siembra de destino publicado
- [x] Spec E2E
- [x] Documentación

**Notas**: Patrón: `tests/e2e/composer.spec.ts`. Solo `e2e-sec-*`; nunca comenta en redes reales.

**Evidencia**: first-comment.spec.ts: desktop 4 passed; mobile (375) y mobile-360 8 passed; axe sin serious/critical y sin desbordamiento. npm run build previo. Helpers seedPublishedWithComment/firstCommentOf y limpieza de jobs PostFirstComment en helpers.ts.


### T-18 — Revisión de robustez del primer comentario

- **Descripción**: Revisión específica de doble comentario y de estados colgados (LES-001, LES-009): carreras entre el job y la recuperación, edición concurrente y comentarios en `comentando` tras un `queue:restart`.
- **Changelog**: Un primer comentario que se cuelga repetidamente deja de reintentarse y queda fallido con aviso, sin crear comentarios duplicados.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.04h (medido)
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17
- **Tipo**: docs
- **Archivos**: `tests/Feature/FirstComment/FirstCommentFlowTest.php`, `app/Jobs/PostFirstComment.php`, `docs/roadmap/2026-10-24-metricool-parity-2/tasks.md`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment tests/Feature/Publishing` → todos en verde

**Criterios de aceptación**
- [x] Sin gaps Critical/Important abiertos.
- [x] Cada hallazgo corregido tiene test.
- [x] Sección «Revisión de dos lentes» anotada en la tarea.

**Subtareas**
- [x] Revisar carreras
- [x] Anotar hallazgos

**Notas**: lectura: confirmar que no hay camino a un duplicado fuera de la ventana «la red aceptó, el worker murió antes de guardar el id».

**Revisión de dos lentes** (autorrevisión del implementador; sin segundo revisor independiente)

- Lente A (carreras e idempotencia). Job vs recuperación: seguras, `ShouldBeUnique` + reclamo con `lockForUpdate`; `comentando` reciente no se pisa. Edición/Reintentar concurrentes con el job: `FirstCommentService` relee con lock y solo actúa en `fallido` (test: en `comentando` se rechazan y no cambian el texto). Ventana «la red aceptó y el worker murió antes de guardar el id»: el siguiente intento (o el paso 6 de `PublishRecovery`) busca el comentario propio y lo adopta (tests con y sin job intermedio). `queue:restart`: el `comentando` colgado vuelve por `PublishRecovery` pasado `stuck_after_minutes`.
- Lente B (estados colgados). **Hallazgo Important corregido:** un worker que muere siempre dejaba el comentario `comentando` y la recuperación lo relanzaba sin fin (los intentos crecían sin tope). Ahora, colgado con intentos agotados, el job solo consulta la red: si el comentario está lo adopta; si no, queda `fallido` con aviso y NO crea otro (dos tests). Resto de caminos a duplicado: ninguno fuera de la ventana declarada; el id guardado hace inocuo repetir el job.
- Riesgos asumidos: el texto se compara normalizado (espacios) con el comentario propio, así que un comentario propio manual idéntico se adopta; la lista de Meta puede tardar en mostrar un comentario recién creado, pero el id ya queda guardado tras `comment()`. Sin gaps Critical/Important abiertos.

---

**Evidencia**: tests/Feature/FirstComment + tests/Feature/Publishing: 199 passed. RED previo: el caso de comentario colgado y agotado volvia a comentar (2 fallos). Pint ok.


## Fase 3 — C-02 · Cuadrícula de Instagram

**Estado**: completado · **Estimado**: 23,5h base (28,2h con margen) · **Real**: IA 0.26h (medido; T-19 estimado 0.05h) · humano —  · **Coste est.**: 1.410 € + tokens (⚠️ verificar) · **Tokens est.**: 952k

### T-19 — `InstagramGrid`: lectura paginada de `/{ig}/media`

- **Descripción**: Lector nuevo (sin tocar `InstagramPublisher`) con `media_type`, `media_url`, `thumbnail_url`, `permalink` y `timestamp`, primera pieza del carrusel y errores. Requisito: CA-08.
- **Changelog**: La cuadrícula de Instagram puede leer lo ya publicado de la cuenta (imagen, vídeo y primera pieza del carrusel) sin permisos nuevos.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real 0.05h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Instagram/InstagramGrid.php`, `tests/Feature/Instagram/InstagramGridReaderTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Instagram/InstagramGridReaderTest.php` → verde con `Http::fake` (paginación, carrusel, error de Graph)

**Criterios de aceptación**
- [x] Lee y normaliza las publicaciones con los campos pedidos.
- [x] Los errores de Graph se traducen sin lanzar excepción hacia la página.
- [x] No necesita permisos nuevos (`instagram_basic`).

**Subtareas**
- [x] Test RED con `Http::fake`
- [x] Lector paginado

**Notas**: Ya se lee `/{ig}/media` para la idempotencia en `app/Connectors/Meta/Instagram/InstagramPublisher.php:402`; llamada vía `app/Connectors/Meta/GraphClient.php`.

**Evidencia**: InstagramGridReaderTest: 6 passed (50 assertions). RED previo: 5 fallos por clase inexistente. Pint ok. Tiempo IA estimado: el marcador del meter se perdio.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 5, 10, 16): `is_shared_to_feed` pedido y Reels con `false` descartados; `timestamp` normalizado a ISO-8601 con dos puntos en el servidor; mensajes de lectura sin «publicar»; `read()` acepta un tope de elementos. Verificación: `php artisan test --compact tests/Feature/Instagram` → 25 passed.

### T-20 — `GridService`: mezcla con lo programado, orden, caché y selector de cuenta

- **Descripción**: Servicio que pone arriba los destinos programados (la más tardía primero; sin Stories; Reel solo con «compartir en el feed»; URL de `MediaAsset::publicUrl()`) y debajo lo publicado, con caché de 10 min y selector de cuenta. Requisito: CA-08, CA-09.
- **Changelog**: La cuadrícula ordena las publicaciones programadas arriba (la más tardía primero) y las ya publicadas debajo; las Stories no aparecen y las lecturas de Instagram se reutilizan 10 minutos.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real 0.05h (medido)
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: backend
- **Archivos**: `app/Services/GridService.php`, `tests/Feature/Instagram/GridServiceTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Instagram/GridServiceTest.php` → verde (orden, Story excluida, caché de 10 min)

**Criterios de aceptación**
- [x] Las programadas van antes que las publicadas, la más tardía primero (CA-08).
- [x] Una Story programada no aparece (CA-09).
- [x] La segunda lectura dentro de 10 min sale de caché.

**Subtareas**
- [x] Test RED de orden y Stories
- [x] Servicio y caché

**Notas**: Patrón de servicios de lectura y caché: `app/Services/CalendarService.php`.

**Evidencia**: GridServiceTest + InstagramGridReaderTest: 13 passed (73 assertions). RED previo: clase GridService inexistente. Pint ok.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 4, 11, 12, 16): error de Graph cacheado `instagram.grid_error_cache_minutes` (2 min); selector solo con cuentas activas; `publicando`/`procesando` siguen en la cuadrícula; `config/social.php` `instagram.grid_cache_minutes` (10) y `grid_published` (30) usados por el servicio (se piden 30, no 72). Misma verificación: 25 passed.

### T-21 — Ruta, controlador y `Pages/Instagram/Grid.vue` con navegación y miniatura de vídeo

- **Descripción**: Página «Cuadrícula» por cuenta de Instagram de la marca: tres columnas, insignias, enlaces al compositor e Instagram, estados vacío y de error, aviso de posts fijados y 360 px; vídeo sin miniatura con `<video preload="metadata">`. Requisito: CA-08..CA-10.
- **Changelog**: Nueva página «Cuadrícula» en Planificación: muestra las tres columnas del perfil de Instagram con lo programado arriba y lo publicado debajo, selector de cuenta y avisos de error y de posts fijados.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 9,0h · real —
- **Tiempo IA (ejec.)**: est. 1,22h · real 0.09h (medido)
- **Supervisión**: est. 0,30h (≈25 % IA) · real —
- **Previsión IA**: 328k in / 36k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: frontend
- **Archivos**: `routes/web.php`, `app/Http/Controllers/InstagramGridController.php`, `resources/js/Pages/Instagram/Grid.vue`, `resources/js/navigation.js` (la entrada «Cuadrícula» vive aquí y no en el layout), `tests/Feature/Ui/InstagramGridPageTest.php`
- **Cubre (tests)**: E2E-05, M-03, M-05
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/InstagramGridPageTest.php` → verde (componente Inertia y propiedad de la cuenta)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] Tres columnas con el orden del perfil y enlace a cada pieza.
- [x] Un usuario ajeno no accede a la cuadrícula de otra marca.
- [x] Se avisa de que los fijados no se reflejan.

**Subtareas**
- [x] Test RED de la página
- [x] Controlador y ruta
- [x] Componente y navegación

**Notas**: Patrón de página y controlador: `resources/js/Pages/Calendar` y `app/Http/Controllers/CalendarController.php`.

**RED no registrado**: la tarea es de clase «capacidad nueva» y su test Pest no dejó evidencia de fallo previo (`RED: …`) en el ledger; se anota aquí (revisión F3-4, gap 17) en vez de inventarla a posteriori.

**Evidencia**: InstagramGridPageTest: 6 passed; Ui+Instagram verdes; npm run build ok; pint ok. Entrada de navegacion en resources/js/navigation.js (el Layout no contiene la lista).

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 10, 11): el selector recibe solo cuentas IG activas y los errores de la página hablan de leer. E2E de Playwright NO ejecutado en esta pasada (el servidor depende de MySQL): queda pendiente de lanzar por el orquestador; la UI se cubre con Pest (props de Inertia).

### T-22 — E2E de la cuadrícula con axe y sin desbordamiento

- **Descripción**: Playwright de la cuadrícula en desktop, 375 y 360 px, con axe. Requisito: CA-10.
- **Changelog**: La cuadrícula de Instagram queda cubierta por una prueba de extremo a extremo en escritorio y móvil (375 y 360 px), sin desbordamiento y con accesibilidad comprobada.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.04h (medido)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-21
- **Tipo**: test
- **Archivos**: `tests/e2e/instagram-grid.spec.ts`, `tests/e2e/helpers.ts` (siembra de la cuadrícula)
- **Cubre (tests)**: E2E-05
- **Verificación**:
  - `npx playwright test tests/e2e/instagram-grid.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/instagram-grid.spec.ts --project=mobile` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [x] 0 violaciones `serious`/`critical` de axe.
- [x] Sin desbordamiento a 375 y 360 px.
- [x] Datos solo con marcas `e2e-`.

**Subtareas**
- [x] Spec de cuadrícula

**Notas**: Patrón: `tests/e2e/pages.spec.ts`.

**Evidencia**: npm run build ok; instagram-grid.spec.ts: desktop 1 passed, mobile 1 passed, mobile-360 1 passed (axe sin serious/critical, sin desbordamiento, sin errores de consola; Story fuera; Graph nunca llamado: publicadas sembradas en cache).

### T-23 — Documentación y revisión de C-02

- **Descripción**: Documentar la cuadrícula y sus límites (URLs del CDN que caducan, fijados, recorte de teselas) y revisar el consumo de Graph (una lectura cada 10 min por cuenta).
- **Changelog**: La guía de usuario y la documentación técnica explican la cuadrícula de Instagram, sus límites (miniaturas que caducan, fijados, recorte, actualización cada 10 minutos) y su consumo de Graph.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.03h (medido)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-22
- **Tipo**: docs
- **Archivos**: `docs/modulos/calendario.md`, `docs/usuario/guia-de-usuario.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "Cuadrícula" docs/modulos/calendario.md docs/usuario/guia-de-usuario.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [x] Documentados los límites de la cuadrícula.
- [x] Revisadas la caché y los errores de Graph.

**Subtareas**
- [x] Redactar documentación
- [x] Revisión

**Notas**: lectura: contrastar con CA-08..CA-10.

**Evidencia**: grep Cuadrícula: calendario.md y guia-de-usuario.md con coincidencias; docs/README.md actualizado. Revisión: caché 10 min por cuenta (máx. 3 peticiones a Graph por ventana), errores de Graph no se cachean y no rompen la página; contrastado con CA-08..CA-10.

**Nota F3-4 (gap 17)**: la cuadrícula ya no «no cachea» los errores sino que los recuerda 2 min (`grid_error_cache_minutes`), la caché y el tamaño salen de `config/social.php` y se descartan los Reels sin «compartir en el feed»; `docs/modulos/calendario.md` actualizado.

---

## Fase 4 — C-03 · Campañas

**Estado**: completado · **Estimado**: 36,5h base (43,8h con margen) · **Real**: — · **Coste est.**: 2.190 € + tokens (⚠️ verificar) · **Tokens est.**: 1,48M

### T-24 — Migración, modelo `Campaign`, `UserScope` y regla «misma marca»

- **Descripción**: Tabla `campaigns` por marca (nombre, color, fechas, archivo) y `posts.campaign_id` (`nullOnDelete`). Requisito: D3 (por marca y asignada al post entero), CA-12.
- **Changelog**: Nueva tabla de campañas por marca y campo de campaña en los posts; un post solo admite campañas de su marca.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.14h (medido)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_campaigns_table.php`, `database/migrations/*_add_campaign_id_to_posts.php`, `app/Models/Campaign.php`, `app/Models/Post.php`, `database/factories/CampaignFactory.php`, `tests/Feature/Campaigns/CampaignModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Campaigns/CampaignModelTest.php` → verde (aislamiento y regla de misma marca)

**Criterios de aceptación**
- [x] Migraciones aditivas.
- [x] Borrar la campaña deja el post sin campaña.
- [x] Un post solo admite campañas de su marca.

**Subtareas**
- [x] Test RED
- [x] Migraciones y modelo

**Notas**: Patrón: `app/Models/ComposerPreset.php`; trait `app/Models/Concerns/BelongsToUser.php`.

**Evidencia**: CampaignModelTest: 4 passed (8 assertions); RED previo: Class App\Models\Campaign not found. php artisan migrate aplicado.

### T-25 — `CampaignService`, controlador y validación

- **Descripción**: CRUD de campañas con archivo, propiedad y validación. Requisito: CA-12, CA-13.
- **Changelog**: Se pueden crear, editar, archivar y borrar campañas de una marca; las de otro usuario devuelven 403.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real 0.14h (medido)
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-24
- **Tipo**: backend
- **Archivos**: `app/Services/CampaignService.php`, `app/Http/Controllers/CampaignController.php`, `app/Http/Requests/CampaignRequest.php`, `app/Policies/CampaignPolicy.php`, `routes/web.php`, `tests/Feature/Campaigns/CampaignCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Campaigns/CampaignCrudTest.php` → verde (CRUD, archivo y 403 ajeno)

**Criterios de aceptación**
- [x] Alta, edición, archivo y borrado funcionan.
- [x] Otro usuario recibe 403.
- [x] Las fechas validan que fin ≥ inicio.

**Subtareas**
- [x] Test RED
- [x] Servicio, controlador y política

**Notas**: Patrón: `app/Services/ComposerPresetService.php` y `app/Policies/PostTemplatePolicy.php`.

**Evidencia**: CampaignCrudTest: 4 passed; CampaignModelTest sigue verde (8 tests, 37 assertions en Campaigns). RED previo: rutas campaigns.* inexistentes.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gap 13): `campaigns/{id}` de otro usuario responde 404 por UserScope (antes 403 con `withoutUserScope`); tests ajustados en `CampaignCrudTest` y `CampaignShowPageTest`. `CampaignPolicy` queda como defensa en profundidad.

### T-26 — Página de campañas: lista, alta, edición, archivo y color

- **Descripción**: `Pages/Campaigns/Index.vue` con la gestión completa y entrada de navegación. Requisito: CA-13.
- **Changelog**: Nueva página Campañas, en Planificación, para crear, editar, archivar, restaurar y borrar las campañas de la marca, con su color y fechas.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real 0.1h (medido)
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-25
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Campaigns/Index.vue`, `resources/js/navigation.js` (la entrada de navegación vive aquí, no en el layout), `app/Http/Controllers/CampaignController.php`, `routes/web.php`, `tests/Feature/Ui/CampaignsPageTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/CampaignsPageTest.php` → verde (componente Inertia y props)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] Lista, crea, edita y archiva campañas de la marca.
- [x] El color se ve con contraste suficiente.
- [x] Entrada de navegación disponible.

**Subtareas**
- [x] Test RED
- [x] Componente

**Notas**: Patrón de páginas de gestión: `resources/js/Pages/Autolists`.

**Evidencia**: CampaignsPageTest: 3 passed; Campaigns+Ui: 11 passed (77 assertions); npm run build ok. RED previo: ruta campaigns.index inexistente. E2E-06 se ejecuta en T-31.

**Nota F3-4 (gap 17)**: se retira de `Archivos` el `AuthenticatedLayout.vue`, que no se tocó (la entrada de navegación vive en `navigation.js`).

### T-27 — Selector de campaña en el compositor, `PostRequest` y `PostService::fill()`

- **Descripción**: Selector en `ComposerHeader` con alta en línea; `campaign_id` en `PostRequest` y `fill()`; las copias desde biblioteca y plantillas conservan la campaña. Requisito: CA-12.
- **Changelog**: El compositor permite elegir la campaña de la publicación (o crearla al momento); solo se admiten campañas de la marca y las copias desde la biblioteca la conservan.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 4,5h · real —
- **Tiempo IA (ejec.)**: est. 0,61h · real 0.33h (medido)
- **Supervisión**: est. 0,15h (≈25 % IA) · real —
- **Previsión IA**: 164k in / 18k out tok · ⚠️ verificar €
- **Dependencias**: T-25, T-12
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/ComposerHeader.vue`, `resources/js/Components/Composer/ActionBar.vue` (errors.campaign_id en «Guardar en biblioteca», fix F3-4), `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Services/LibraryService.php`, `app/Http/Controllers/PostController.php`, `app/Http/Controllers/LibraryController.php`, `app/Models/PostTemplate.php`, `database/migrations/*_add_campaign_id_to_post_templates.php` (la plantilla conserva la campaña), `resources/js/Composables/useComposer.js`, `resources/js/Pages/Posts/Compose.vue`, `tests/Feature/Campaigns/CampaignAssignTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**:
  - `php artisan test --compact tests/Feature/Campaigns/CampaignAssignTest.php` → verde (rechaza campaña de otra marca; las copias conservan la campaña)
  - `php artisan test --compact tests/Feature/Library` → verde (biblioteca sin regresión)

**Criterios de aceptación**
- [x] Asignar una campaña de la marca A a un post de la marca B se rechaza (CA-12).
- [x] Duplicar o guardar desde biblioteca conserva la campaña.
- [x] Alta en línea desde el compositor.

**Subtareas**
- [x] Caracterización de `tests/Feature/Library` y `PostsTest`
- [x] Test RED
- [x] Selector y servicio

**Notas**: Comparte `PostRequest`, `PostService` y `PostPayload` con C-01b: va después (T-12, T-15), en serie. `app/Services/LibraryService.php:68` (`store` copia destinos).

**Evidencia**: Caracterización previa en verde: Library+Posts 107 passed. CampaignAssignTest: 8 passed (RED previo: 7 fallos); Campaigns+Library+Posts+Ui+SavedTexts+FirstComment+Mcp: 447 passed (2598 assertions); npm run build ok; migrate aplicado.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 1, 8, 9): `PATCH posts/{post}/campaign` (`PostService::updateCampaign`) y selector activo aunque el post no sea editable (metadato, como las notas); misma marca y no archivada; «Guardar en biblioteca» muestra `errors.campaign_id`; el borrado de la campaña entre `plan()` y `save()` (hook `InvalidArgumentException` o FK) se traduce a `PostValidationException`, nunca 500. Verificación: `php artisan test --compact tests/Feature/Campaigns tests/Feature/Mcp tests/Feature/Ui` → 306+ passed. E2E pendiente (ver T-21).

### T-28 — Paridad MCP de campañas: `campaign_id` y `list_campaigns`

- **Descripción**: `campaign_id` en `create_post`, `update_post` y `get_post`, y herramienta `list_campaigns` registrada en el servidor. Requisito: CA-13. Tarea transversal de paridad MCP.
- **Changelog**: El MCP admite campaign_id al crear y editar publicaciones, get_post devuelve la campaña y hay una herramienta nueva list_campaigns (20 herramientas, caben en la primera página).
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.12h (medido)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-15
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/GetPost.php`, `app/Mcp/Tools/ListCampaigns.php`, `app/Mcp/Tools/UpdatePostCampaign.php` (fix F3-4: campaña en posts publicados), `README.md` (recuento de herramientas MCP, fix F3-4), `app/Mcp/Servers/SchedulerServer.php`, `app/Mcp/Tools/SchedulerTool.php` (presentPost), `docs/modulos/mcp.md` (recuento 19 a 20), `tests/Feature/Mcp/Tools/McpToolsTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php` (recuento), `tests/Feature/Mcp/Tools/McpCampaignsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpCampaignsTest.php` → verde (paridad de validación y `list_campaigns`)
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpFirstCommentTest.php` → verde (sin regresión)

**Criterios de aceptación**
- [x] `campaign_id` valida igual que en la web.
- [x] `list_campaigns` devuelve solo las campañas de la marca.
- [x] `get_post` expone la campaña.

**Subtareas**
- [x] Caracterización del payload actual
- [x] Test RED
- [x] Herramienta y registro

**Notas**: `app/Mcp/Servers/SchedulerServer.php:42` (`$tools`). Patrón de herramienta: `app/Mcp/Tools/ListBrands.php`.

**Evidencia**: Caracterización previa: Mcp 218 passed. McpCampaignsTest: 5 passed (RED previo: 3 fallos); Mcp+Campaigns: 239 passed (1383 assertions); McpFirstCommentTest en verde; tools/list = 20 (< 25 por página); mcp.md actualizado.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 1, 14, 17): decisión MCP = herramienta DEDICADA `update_post_campaign` (22 herramientas) y no relajar `update_post`: este sustituye texto, media y destinos y exige `schedule`, así que aceptar «solo campaign_id» en un post publicado mezclaría dos contratos; `update_post_notes` ya sienta el precedente. `campaign_id` es `integer|null` en el esquema y las campañas archivadas se rechazan con mensaje (criterio de archivado = `archived_at` no nulo; la campaña que el post ya tiene se conserva o se quita). Recuentos 21→22 en tests, `README.md:41` y `docs/modulos/mcp.md`.

### T-29 — `CampaignAnalytics`: extraer la consulta del último snapshot y agregar

- **Descripción**: Extraer de `AnalyticsService::postStats` la consulta del último snapshot sin cambiar el dashboard, y agregar por red, totales y serie diaria; una métrica sin datos es `null`, no 0. Requisito: CA-11.
- **Changelog**: Se calculan las métricas agregadas de una campaña (suma del último dato de cada publicación, por red y día); lo que no tiene datos se muestra como sin datos, no como 0.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 8,5h · real —
- **Tiempo IA (ejec.)**: est. 1,15h · real 0.17h (medido)
- **Supervisión**: est. 0,29h (≈25 % IA) · real —
- **Previsión IA**: 310k in / 34k out tok · ⚠️ verificar €
- **Dependencias**: T-24
- **Tipo**: backend
- **Archivos**: `app/Services/CampaignAnalytics.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Campaigns/CampaignAnalyticsTest.php`, `tests/Feature/Analytics/AnalyticsServiceTest.php` (la caracterización de postStats vive aquí; DashboardTest ya estaba en verde y no se toca)
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Dashboard/DashboardTest.php` → verde antes y después de extraer
  - `php artisan test --compact tests/Feature/Campaigns/CampaignAnalyticsTest.php` → verde (suma de últimos snapshots; `null` y sin snapshots)

**Criterios de aceptación**
- [x] Los KPIs suman los últimos snapshots de cada destino (CA-11).
- [x] «Sin datos» se distingue de 0.
- [x] El dashboard conserva su comportamiento.

**Subtareas**
- [x] Caracterización del dashboard
- [x] Test RED de agregación
- [x] Extracción y servicio

**Notas**: `app/Services/AnalyticsService.php:228` (`postStats`). Las métricas se congelan a los 30 días (`recent_post_days`, `config/social.php:158`): el panel lo dice.

**Evidencia**: Caracterización previa en verde: Dashboard+Analytics 25 passed, y test nuevo de postStats (26 passed) antes de extraer; tras extraer withLatestSnapshot: Dashboard+Analytics+Reports 55 passed. CampaignAnalyticsTest: 6 passed (RED previo: clase inexistente).

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 2, 7): `withLatestSnapshot` acota `lm` a los destinos de la consulta (`WHERE post_target_id IN (SELECT id …)`); caracterizado primero (Dashboard+Analytics+Campaigns 54 passed antes y después) y test del SQL acotado. Serie diaria = suma de los snapshots leídos cada día en SQL, sin arrastre ni null→0: DECISIÓN «documentarlo» (su último punto puede no coincidir con el KPI; lo dice el docblock, `docs/modulos/analitica.md` y la UI). Benchmark `bench.php 5000 1000 90` (sqlite en memoria, 900.000 snapshots), campaña pequeña de 20 posts: consulta de destinos 141,2 ms → 1,1 ms y summary 250 ms → 103 ms solo con este cambio; subconsulta `lm` aislada 45,0 ms (sin acotar) → 0,4 ms (acotada).

### T-30 — Panel `Pages/Campaigns/Show.vue`: KPIs, tabla por red, gráfica y posts

- **Descripción**: Panel de campaña con KPIs (posts, vistas, alcance acumulado, interacciones, likes, comentarios, compartidos, guardados, engagement), tabla por red, serie diaria con `LineChart` y lista de posts. Requisito: CA-11.
- **Changelog**: Nuevo panel de campaña con las métricas agregadas, el desglose por red, la evolución diaria y las publicaciones; indica el alcance acumulado y el congelado de métricas a 30 días.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,5h · real —
- **Tiempo IA (ejec.)**: est. 0,88h · real 0.14h (medido)
- **Supervisión**: est. 0,22h (≈25 % IA) · real —
- **Previsión IA**: 237k in / 26k out tok · ⚠️ verificar €
- **Dependencias**: T-26, T-29
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Campaigns/Show.vue`, `app/Http/Controllers/CampaignController.php`, `app/Services/CampaignService.php` (show_url), `routes/web.php`, `tests/Feature/Ui/CampaignShowPageTest.php`
- **Cubre (tests)**: E2E-07, M-05
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/CampaignShowPageTest.php` → verde (props del panel)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] Muestra «sin datos» donde no hay métrica.
- [x] Rotula «alcance acumulado» y avisa del congelado a 30 días.
- [x] Funciona a 360 px.

**Subtareas**
- [x] Test RED
- [x] Componente y gráfica

**Notas**: Patrón de gráficas: `resources/js/Pages/Dashboard` (`LineChart`).

**Evidencia**: CampaignShowPageTest: 4 passed (RED previo: ruta y componente inexistentes); Ui+Campaigns: 53 passed; npm run build ok.

**Corrección F3-4 (intento 1)**: Corrección F3-4 intento 1 (gaps 3, 6, 15): `daily()` agrega por fecha en SQL; `summary()` lee los destinos como filas planas y solo los 100 primeros posts con el texto recortado en SQL (`SUBSTR`), más `posts_total`/`posts_truncated`, con aviso en la UI; fecha en la zona de la marca (`scheduled_local` + zona) y estado con `status_label`. Benchmark `bench.php 5000 1000 90`, campaña de 1.000 posts × 2 redes × 90 días (180.000 snapshots): ANTES 5,158 s y 374,8 MB de pico, 3 consultas; DESPUÉS 0,471 s y 3,9 MB, 5 consultas (campaña de 20 posts: 250 ms/7,8 MB → 17 ms/0,5 MB). E2E pendiente (ver T-21).

### T-31 — E2E de campañas con axe y sin desbordamiento

- **Descripción**: Playwright de la gestión, el selector y el panel en desktop, 375 y 360 px. Requisito: CA-11..CA-13.
- **Changelog**: Pruebas de extremo a extremo de la gestión de campañas, el selector del compositor y el panel, con accesibilidad y sin desbordamiento a 375 y 360 px.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.19h (medido)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-30
- **Tipo**: test
- **Archivos**: `tests/e2e/campaigns.spec.ts`, `tests/e2e/helpers.ts` (siembra de campañas)
- **Cubre (tests)**: E2E-06, E2E-07
- **Verificación**:
  - `npx playwright test tests/e2e/campaigns.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/campaigns.spec.ts --project=mobile-360` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [x] 0 violaciones `serious`/`critical` de axe.
- [x] Sin desbordamiento a 375 y 360 px.
- [x] Datos solo con marcas `e2e-`.

**Subtareas**
- [x] Spec de gestión
- [x] Spec de panel

**Notas**: Patrón: `tests/e2e/flows.spec.ts`.

**Evidencia**: npm run build ok; campaigns.spec.ts (E2E_NO_SERVER=1, https://metricool.test): desktop 2 passed, mobile 2 passed, mobile-360 2 passed (axe sin serious/critical, sin desbordamiento, sin errores de consola; solo marcas e2e-composer-*).

### T-32 — Documentación y revisión de C-03

- **Descripción**: Documentar campañas, el congelado de métricas y el MCP, y revisar la regresión del dashboard.
- **Changelog**: La guía de usuario y la documentación técnica explican las campañas, el alcance acumulado, el límite de 30 días de las métricas y las herramientas MCP.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real 0.03h (medido)
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-28, T-31
- **Tipo**: docs
- **Archivos**: `docs/modulos/analitica.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "campaña" docs/modulos/analitica.md docs/modulos/mcp.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [x] `list_campaigns` y `campaign_id` documentados en el módulo MCP.
- [x] Documentado el límite de 30 días.
- [x] Dashboard sin regresión.

**Subtareas**
- [x] Documentación
- [x] Revisión

**Notas**: lectura: contrastar con CA-11..CA-13.

**Evidencia**: grep campaña: analitica.md (3 coincidencias) y mcp.md (1) y guía de usuario con sección Campañas. Revisión: Dashboard+Analytics+Reports 55 passed tras extraer withLatestSnapshot; contrastado con CA-11..CA-13.

---

## Fase 5 — C-04a · Media desde URL

**Estado**: completado · **Estimado**: 24,5h base (29,4h con margen) · **Real**: — · **Coste est.**: 1.470 € + tokens (⚠️ verificar) · **Tokens est.**: 992k

### T-33 — `UrlGuard`: guardia SSRF con DNS, IPs, redirecciones y puertos

- **Descripción**: Guardia contra SSRF (CWE-918): solo `http`/`https` en 80/443, sin credenciales, IDN, resolución A/AAAA con todas las IPs públicas, bloqueo explícito de rangos privados, reservados y link-local (IPv4, IPv6 e incrustadas), literales decimales u octales rechazados, y salto manual de hasta 5 redirecciones revalidadas, con la IP fijada. Requisito: CA-15.
- **Changelog**: Las importaciones por URL rechazan direcciones internas, puertos distintos de 80/443, credenciales y redirecciones hacia la red privada.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real 0.30 (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/Media/UrlGuard.php`, `app/Services/Media/UnsafeUrlException.php`, `app/Services/Media/ValidatedUrl.php`, `tests/Unit/UrlGuardTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/UrlGuardTest.php` → verde (tabla de IPs y redirecciones de CA-15)

**Criterios de aceptación**
- [x] `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254` y `::1` se rechazan sin ninguna petición (CA-15).
- [x] Una redirección a una IP interna se rechaza.
- [x] La IP validada se fija con `CURLOPT_RESOLVE` contra el DNS rebinding.

**Subtareas**
- [x] Test RED con la tabla de IPs
- [x] Resolución y rangos
- [x] Redirecciones e IP fijada

**Notas**: No hay ningún guardia SSRF en el repo (lo verificó el diseño); sin patrón previo. Revisión de seguridad en T-41.

**Evidencia**: RED: UrlGuardTest falló (99 errores, UrlGuard inexistente) · 2026-10-24. UrlGuardTest: 99 passed (175 assertions); tabla de IPv4/IPv6/mapped/NAT64/6to4, literales numéricos, esquemas, credenciales, puertos, mezcla de IPs, rebinding, redirecciones (5 máx) y CURLOPT_RESOLVE; Http::assertNothingSent/assertSentCount.

### T-34 — Migración `source`/`source_ref`/`import_state` y configuración de la cola `media`

- **Descripción**: Columnas aditivas en `media_assets` (`source` `upload|url|drive`, `source_ref`, `import_state` JSON), progreso en `toPublicArray()` y claves `media.import_budget_seconds` (80) e `import_queue`; `media` en `QUEUE_NAMES`. Requisito: diseño B1.
- **Changelog**: Las importaciones remotas tendrán su propia cola «media» con presupuesto de 80 s por pasada, y cada archivo registra su origen.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.10 (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_add_source_to_media_assets.php`, `app/Models/MediaAsset.php`, `config/media.php`, `config/queue.php`, `tests/Feature/Media/MediaAssetSourceTest.php`, `tests/Feature/Infra/InfraTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/MediaAssetSourceTest.php` → verde (columnas, valor por defecto `upload`, progreso en el array público)

**Criterios de aceptación**
- [x] Los assets existentes quedan con `source = upload`.
- [x] `toPublicArray()` expone `bytes` y `total` sin filtrar la URL sensible.
- [x] `media` figura en las colas por defecto.

**Subtareas**
- [x] Test RED
- [x] Migración, modelo y configuración

**Notas**: `config/queue.php:35` (`names`). Migraciones solo aditivas.

**Evidencia**: RED: MediaAssetSourceTest falló (columnas inexistentes) · 2026-10-24. MediaAssetSourceTest: 5 passed (21 assertions); migrate aplicada; tests/Feature/Media + Mcp 268 passed. Fuera de Archivos: tests/Feature/Infra/InfraTest.php (la cola `media` entra en la lista de colas por defecto).

### T-35 — `RemoteMediaImporter` y job `ImportRemoteMedia` por tramos con `Range`

- **Descripción**: Interfaz `RemoteSource` con `UrlSource` y job en la cola `media` (`ShouldBeUnique`, `ShouldBeEncrypted`, timeout 110) que descarga por pasadas de 80 s con `Range`/`If-Range` y punto de control en el `.part`, con tope de bytes, un único reinicio si el remoto cambia y, al final, `finalize()`. Requisito: CA-14, CA-16, CA-17; diseño B1.
- **Changelog**: Se pueden importar imágenes y vídeos desde una URL pública: se descargan en segundo plano por tramos y quedan como media lista, o inválida con el motivo.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 8,5h · real —
- **Tiempo IA (ejec.)**: est. 1,15h · real 0.60 (estimado)
- **Supervisión**: est. 0,29h (≈25 % IA) · real —
- **Previsión IA**: 310k in / 34k out tok · ⚠️ verificar €
- **Dependencias**: T-33, T-34
- **Tipo**: backend
- **Archivos**: `app/Services/Media/RemoteSource.php`, `app/Services/Media/UrlSource.php`, `app/Services/Media/RemoteMediaImporter.php`, `app/Services/Media/ImportFailedException.php`, `app/Services/Media/RangeIgnored.php`, `app/Services/Media/RemotePass.php`, `app/Jobs/ImportRemoteMedia.php`, `app/Services/MediaUploadService.php`, `tests/Feature/Media/ImportRemoteMediaTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/ImportRemoteMediaTest.php` → verde (JPEG válido listo; tope y tipo → `invalido`; vídeo en varias pasadas; sin `Range` → error claro)
  - `php artisan test --compact tests/Feature/Media/MediaUploadTest.php` → verde (la subida directa no cambia)

**Criterios de aceptación**
- [x] Una URL JPEG válida acaba en un asset `listo` (CA-14).
- [x] Superar `max_bytes` o un tipo real distinto corta la descarga y deja el asset `invalido` (CA-16).
- [x] Un vídeo grande se importa en varias pasadas, cada una bajo 120 s (CA-17); con `MEDIA_DISK=s3` falla con un error claro.

**Subtareas**
- [x] Test RED con `Http::fake`
- [x] Fuente y pasada
- [x] Job, reencolado y `finalize()`

**Notas**: Patrón de pasadas con presupuesto: `app/Connectors/Google/YouTubePublisher.php:413` (`beginPass`) y `upload_budget_seconds` (80). `app/Services/MediaUploadService.php:119` (`finalize`): solo se fija `declared_size` antes de llamarlo.

**Evidencia**: RED: ImportRemoteMediaTest falló (clases inexistentes) · 2026-10-24. ImportRemoteMediaTest: 38 passed (135 assertions): JPEG válido listo; tope por Content-Length y por bytes reales; MIME falseado y no admitido; 3 pasadas con Range/If-Range; progreso; sin Range → error claro; reinicio único; URL interna/redirección/rebinding; IP fijada por pasada; S3 → error claro; 503 → reintento y failed(). MediaUploadTest en verde. Desviación: el job usa ShouldBeUniqueUntilProcessing (hereda ShouldBeUnique) para poder reencolarse a sí mismo. Pendiente de smoke real (no hay red en tests): progreso/on_headers de cURL.

### T-36 — Endpoint de importación por URL con `throttle`, límite por usuario y limpieza de `.part`

- **Descripción**: `MediaController::importUrl` con `throttle` y máximo de 3 importaciones vivas por usuario, estado sondeable y limpieza de `.part` huérfanos en `app:media-prune`. Requisito: CA-14, CA-15.
- **Changelog**: Se puede importar media pegando una URL: se rechazan las direcciones internas, hay un máximo de 3 importaciones a la vez y la limpieza horaria borra las descargas abandonadas.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real 0.20 (estimado)
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/MediaController.php`, `routes/web.php`, `app/Console/Commands/PruneMedia.php`, `app/Services/MediaUploadService.php`, `tests/Feature/Media/ImportUrlEndpointTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/ImportUrlEndpointTest.php` → verde (alta, límite de 3, URL interna rechazada)
  - `php artisan test --compact tests/Feature/Media/MediaUploadTest.php` → verde (sin regresión)

**Criterios de aceptación**
- [x] Una URL interna se rechaza con 422 y mensaje en español.
- [x] La cuarta importación viva se rechaza.
- [x] `app:media-prune` borra los `.part` abandonados.

**Subtareas**
- [x] Caracterización de `MediaUploadTest`
- [x] Test RED
- [x] Endpoint y poda

**Notas**: Rutas de media en `routes/web.php:94-98`. `app/Console/Commands/PruneMedia.php`.

**Evidencia**: Caracterización: MediaUploadTest 11 passed antes de tocar. RED: ImportUrlEndpointTest 22 errores (ruta media.import-url inexistente) · 2026-10-24. ImportUrlEndpointTest: 23 passed (alta 202 en cola media, 12 URLs rechazadas con 422, límite de 3 por usuario, throttle 10/min, sondeo y 403 ajeno, prune de .part). tests/Feature/Media: 106 passed. Lógica compartida con MCP en MediaUploadService::importFromUrl.

### T-37 — UI «Desde URL» en `MediaSection` con sondeo y errores

- **Descripción**: Campo «Desde URL» con progreso (sondeo del estado) y errores en español en `MediaSection.vue`. Requisito: CA-14.
- **Changelog**: En el compositor aparece un campo «Desde URL» que importa imágenes y vídeos con barra de progreso y muestra en español el motivo si falla.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.15 (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-36
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/MediaSection.vue`, `tests/Feature/Ui/MediaImportUiTest.php`
- **Cubre (tests)**: E2E-08
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/MediaImportUiTest.php` → verde (props y ruta de importación)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] Se ve el progreso hasta `listo` y el motivo si queda `invalido`.
- [x] Accesible por teclado.
- [x] Sin desbordamiento a 360 px.

**Subtareas**
- [x] Test RED
- [x] Componente y sondeo

**Notas**: Patrón: subida y sondeo en `resources/js/Components/Composer/MediaSection.vue`.

**Evidencia**: MediaImportUiTest: 4 passed (28 assertions); npm run build exit 0. Desviación: el test se escribió junto al componente (no hay RED registrado); la interacción se verifica en T-39 con Playwright.

### T-38 — MCP `import_media_from_url`

- **Descripción**: Herramienta que llama al mismo servicio y devuelve el asset `pendiente`; `list_media` ya lista el estado. Requisito: CA-14, CA-15. Tarea transversal de paridad MCP.
- **Changelog**: Los agentes conectados por MCP pueden importar media desde una URL pública con la herramienta import_media_from_url, con las mismas protecciones que la web.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real 0.10 (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-36
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/ImportMediaFromUrl.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `docs/modulos/mcp.md`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpImportMediaTest.php` → verde (acepta URL pública, rechaza interna)

**Criterios de aceptación**
- [x] La misma `UrlGuard` protege la herramienta.
- [x] Devuelve el asset `pendiente`.
- [x] Registrada en el servidor.

**Subtareas**
- [x] Test RED
- [x] Herramienta

**Notas**: `app/Mcp/Servers/SchedulerServer.php:42`; herramienta hermana `app/Mcp/Tools/ListMedia.php`.

**Evidencia**: RED: McpImportMediaTest 14 fallos (herramienta no registrada, 400) · 2026-10-24. McpImportMediaTest: 14 passed; tests/Feature/Mcp: 237 passed (1396 assertions). tools/list ofrece 21 herramientas en una página (eran 20 antes de esta; el recuento de docs/modulos/mcp.md pasa a 21).

### T-39 — E2E de importación por URL con axe

- **Descripción**: Playwright: el flujo «Desde URL» con una URL rechazada por `UrlGuard` (la importación real se prueba en Pest), a 375 y 360 px. Requisito: CA-15.
- **Changelog**: Cubierto por pruebas automáticas: pegar una dirección interna en «Desde URL» muestra el error en español y no importa nada, en escritorio y móvil.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real 0.10 (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-37
- **Tipo**: test
- **Archivos**: `tests/e2e/media-import.spec.ts`
- **Cubre (tests)**: E2E-08
- **Verificación**:
  - `npx playwright test tests/e2e/media-import.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/media-import.spec.ts --project=mobile-360` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [x] Se muestra el error en español para una IP interna.
- [x] 0 violaciones `serious`/`critical` de axe.
- [x] Sin desbordamiento a 375 y 360 px.

**Subtareas**
- [x] Spec

**Notas**: Patrón: `tests/e2e/composer.spec.ts`.

**Evidencia**: npm run build exit 0; media-import.spec.ts (E2E_NO_SERVER=1, https://metricool.test): desktop 2 passed, mobile 2 passed, mobile-360 2 passed (axe sin serious/critical, sin desbordamiento, sin errores de consola salvo el 422 esperado; solo marcas e2e-composer-*).

### T-40 — Despliegue Hestia: cola `media` con su worker y cron

- **Descripción**: Cuarta línea de cron `ss-media` (`flock -n`, `--queue=media --stop-when-empty --max-time=280 --timeout=120 --tries=3`), `media` en `QUEUE_NAMES` y documentación del despliegue. Requisito: D5 (cola nueva con su worker).
- **Changelog**: La guía de despliegue en Hestia añade un cuarto cron (ss-media) para importar media, con su cola «media» y cómo procesarla a mano.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real 0.08 (estimado)
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: devops
- **Archivos**: `deploy/hestia/README.md`, `deploy/hestia/deploy.sh`, `docs/modulos/despliegue.md`, `docs/modulos/media.md`, `docs/referencia/configuracion.md`, `docs/arquitectura.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "ss-media" deploy/hestia/README.md docs/modulos/despliegue.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [x] El cron nuevo sigue el patrón de `ss-publish` y `ss-metrics`.
- [x] `--timeout` < `retry_after` (180 s).
- [x] Documentado cómo procesar la cola a mano.

**Subtareas**
- [x] Línea de cron
- [x] Documentación y solución de problemas

**Notas**: `deploy/hestia/README.md:84-87` (líneas `ss-publish` y `ss-metrics`); `docs/modulos/despliegue.md:112`.

**Evidencia**: Caracterización: tests/Feature/Infra en verde antes de tocar (T-34). Verificación: grep -n "ss-media" → deploy/hestia/README.md (2 coincidencias) y docs/modulos/despliegue.md (4). --timeout=120 < retry_after=180. deploy.sh no necesita cambios (WORKER_UNITS es una variable del llamador). Pendiente del usuario en producción: añadir `media` a QUEUE_NAMES del .env si lo define y crear el cron 4 en Hestia.

### T-41 — Revisión de seguridad SSRF de la importación

- **Descripción**: Revisión específica: DNS rebinding, redirecciones, literales numéricos, IPv6 incrustado, límites de tamaño y token de Drive fuera de logs y de `media_assets`.
- **Changelog**: Revisión de seguridad de las importaciones por URL: se corrigieron una posible filtración del token de la URL en errores y la reconstrucción de la URL validada.
- **Cerrada**: 2026-10-24
- **Estado**: completado
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 0,5h · real —
- **Tiempo IA (ejec.)**: est. 0,07h · real 0.06 (estimado)
- **Supervisión**: est. 0,02h (≈25 % IA) · real —
- **Previsión IA**: 18k in / 2k out tok · ⚠️ verificar €
- **Dependencias**: T-33, T-36, T-38
- **Tipo**: docs
- **Archivos**: `tests/Unit/UrlGuardTest.php`, `tests/Feature/Media/ImportUrlEndpointTest.php`, `tests/Feature/Media/ImportRemoteMediaTest.php`, `app/Services/Media/UrlGuard.php`, `app/Services/Media/RemoteMediaImporter.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/UrlGuardTest.php tests/Feature/Media/ImportUrlEndpointTest.php` → verde

**Criterios de aceptación**
- [x] Sin gaps Critical/Important abiertos.
- [x] Cada hallazgo tiene test.
- [x] Sección de revisión anotada en la tarea.

**Subtareas**
- [x] Revisar cada vector
- [x] Anotar hallazgos

**Notas**: lectura: verificar que ninguna petición sale a una IP no validada, incluida cada redirección.

**Evidencia**: RED: 4 pruebas nuevas fallaron contra el código previo · 2026-10-24. Revisión de vectores: (1) DNS rebinding: UrlGuard resuelve en cada validación y fija CURLOPT_RESOLVE por pasada y salto; probado con resolvedor que cambia y con espía de client(). (2) Redirecciones: cada Location se revalida antes de pedirse (assertSentCount); 5 saltos máx.; relativas, protocol-relative, esquemas y puertos. (3) Literales: decimal, octal, hex, abreviado, punto final, %-codificado, zonas IPv6. (4) IPv6 incrustado: ::ffff, 64:ff9b, 2002, ::/96, fc00::/7, fe80::/10, documentación. (5) Tamaño: Content-Length ausente, mentiroso a la baja y mayor que el máximo; MIME falseado (finfo) y no admitido. (6) Token de Drive: n/a en esta fase (T-42); la URL con query no sale en asset público, source_ref ni respuestas. HALLAZGOS corregidos: H1 (Important) el mensaje de excepción de Guzzle/cURL llevaba la URL completa con su query (posible token) hacia failed_jobs/log → RemoteMediaImporter relanza sin la original y con la URL enmascarada; H2 (Important) la petición usaba la cadena original: ahora se reconstruye desde lo validado (sin fragmento ni userinfo) y el host solo admite [a-z0-9-.] (rechaza %, _ y zonas IPv6). Sin gaps Critical/Important abiertos. Riesgo residual (no cubrible sin red): el corte por CURLOPT_PROGRESSFUNCTION y on_headers de cURL real, y que el job reanuda tras abortar; queda para el smoke del usuario. UrlGuardTest + tests/Feature/Media + Mcp + Ui + Infra: 508 passed (2257 assertions).

---

## Fase 6 — C-04b · Media desde Google Drive

**Estado**: borrador · **Estimado**: 14,5h base (17,4h con margen) · **Real**: — · **Coste est.**: 870 € + tokens (⚠️ verificar) · **Tokens est.**: 587k

### T-42 — `DriveFileSource` y endpoint de importación desde Drive

- **Descripción**: Fuente con host fijo `www.googleapis.com` y `files.get?alt=media` con Bearer, `fileId` validado, y endpoint que verifica el token con `tokeninfo` (`aud` y `drive.file`), pide metadatos y valida tipo y tamaño antes de crear el asset; el token viaja cifrado en el job y no se guarda. Requisito: CA-18; diseño B1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-35, T-36
- **Tipo**: backend
- **Archivos**: `app/Services/Media/DriveFileSource.php`, `app/Http/Controllers/MediaController.php`, `routes/web.php`, `config/services.php`, `tests/Feature/Media/ImportDriveTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/ImportDriveTest.php` → verde con `Http::fake` (token válido; `aud` ajeno rechazado; `401` → `invalido`)

**Criterios de aceptación**
- [ ] El token no se guarda en `media_assets` ni se registra.
- [ ] Un `fileId` inválido o un `aud` distinto se rechaza.
- [ ] Un `401` a mitad deja «Vuelve a elegir el fichero en Drive».

**Subtareas**
- [ ] Test RED con `Http::fake`
- [ ] Fuente
- [ ] Endpoint y `tokeninfo`

**Notas**: Reutiliza el pipeline de T-35. Verificado en la documentación oficial: `files.get?alt=media` admite `Range` y `drive.file` es no sensible.

### T-43 — `useDrivePicker.js` con Google Identity Services y Picker

- **Descripción**: Composable que pide el token con GIS (`drive.file`), abre el Picker filtrado a imagen y vídeo y entrega `fileId` y token; props de configuración (clave, app id, client id). Requisito: CA-18.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-42
- **Tipo**: frontend
- **Archivos**: `resources/js/Composables/useDrivePicker.js`, `app/Http/Controllers/PostController.php`, `tests/Feature/Ui/DrivePickerConfigTest.php`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/DrivePickerConfigTest.php` → verde (props presentes solo con la clave configurada)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] Sin `GOOGLE_PICKER_API_KEY` no se expone configuración.
- [ ] El token solo vive en memoria en el navegador.
- [ ] Los scripts de Google se cargan solo al usarlo.

**Subtareas**
- [ ] Test RED
- [ ] Composable

**Notas**: Patrón de composables: `resources/js/Composables/useComposer.js`.

### T-44 — UI «Google Drive» en `MediaSection` (oculta sin configurar)

- **Descripción**: Botón «Google Drive» que aparece solo con la clave configurada y reutiliza el sondeo de «Desde URL». Requisito: CA-18.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-43
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/MediaSection.vue`
- **Cubre (tests)**: E2E-09
- **Verificación**:
  - `npm run build` → compila sin errores
  - `npx playwright test tests/e2e/media-import.spec.ts --project=desktop -g "Drive"` → pasa (botón oculto sin clave)

**Criterios de aceptación**
- [ ] Sin clave no hay botón (CA-18).
- [ ] Con clave, el fichero elegido se importa como en CA-14.
- [ ] Accesible por teclado.

**Subtareas**
- [ ] Botón y estado
- [ ] Sondeo compartido

**Notas**: Mismo componente que T-37.

### T-45 — Pest de Drive con `Http::fake`

- **Descripción**: Casos de Drive: metadatos, tipo y tamaño, tramos con `Range`, token caducado y `ShouldBeEncrypted`. Requisito: CA-18.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-42
- **Tipo**: test
- **Archivos**: `tests/Feature/Media/ImportDriveFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/ImportDriveFlowTest.php` → verde

**Criterios de aceptación**
- [ ] Nunca se llama a Drive real.
- [ ] Cubre éxito, `401` y fichero demasiado grande.
- [ ] El payload del job va cifrado.

**Subtareas**
- [ ] Escenarios

**Notas**: Patrón: `tests/Feature/Media/ImportRemoteMediaTest.php` (T-35).

### T-46 — E2E de Drive: botón oculto sin clave

- **Descripción**: Playwright: sin `GOOGLE_PICKER_API_KEY` el botón no aparece, a 375 y 360 px, con axe. El Picker real es manual (M-04).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-44
- **Tipo**: test
- **Archivos**: `tests/e2e/media-import.spec.ts`
- **Cubre (tests)**: E2E-09
- **Verificación**: `npx playwright test tests/e2e/media-import.spec.ts --project=mobile-360` → todos pasan

**Criterios de aceptación**
- [ ] Sin clave no hay botón.
- [ ] 0 violaciones de axe.
- [ ] Sin desbordamiento a 360 px.

**Subtareas**
- [ ] Caso de ausencia de clave

**Notas**: Mismo fichero de spec que T-39.

### T-47 — Revisión de C-04b y smoke real con el usuario

- **Descripción**: Revisar el manejo del token de Drive y dejar preparado el smoke real a cargo del usuario (M-04), que depende de D4 (Google Cloud).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-45, T-46
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-24-metricool-parity-2/test-plan.md`
- **Cubre (tests)**: M-04
- **Verificación**: `php artisan test --compact tests/Feature/Media` → todos en verde

**Criterios de aceptación**
- [ ] Token fuera de logs y de `failed_jobs` en claro.
- [ ] M-04 listo para ejecutar por el usuario.
- [ ] El smoke real lo ejecuta el usuario, no el agente.

**Subtareas**
- [ ] Revisar
- [ ] Dejar M-04 listo

**Notas**: lectura: contrastar el tratamiento del token con el diseño §6.

---

## Fase 7 — Transversal y cierre

**Estado**: borrador · **Estimado**: 3,5h base (4,2h con margen) · **Real**: — · **Coste est.**: 210 € + tokens (⚠️ verificar) · **Tokens est.**: 142k

### T-48 — Guía de configuración externa para el usuario (Google Cloud y YouTube)

- **Descripción**: Guía paso a paso para activar Picker API y Drive API, crear la clave de API restringida al dominio, añadir `drive.file` a la pantalla de consentimiento (D4) y reconectar YouTube con `youtube.force-ssl` (D2).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-42, T-07
- **Tipo**: docs
- **Archivos**: `docs/guias/google-cloud-picker-drive.md`, `docs/README.md`, `docs/referencia/configuracion.md`, `docs/referencia/pendientes-manuales.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "drive.file\|youtube.force-ssl" docs/guias/google-cloud-picker-drive.md` → ambas cadenas presentes

**Criterios de aceptación**
- [ ] Lista los pasos y las variables `.env` (`GOOGLE_PICKER_API_KEY`, app id y client id).
- [ ] Explica la reconexión de YouTube y que invalida los tokens actuales.
- [ ] Enlazada desde `docs/README.md` y los pendientes manuales.

**Subtareas**
- [ ] Redactar la guía
- [ ] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/login-social-google-meta.md`. lectura: contrastar con D2 y D4.

### T-49 — Documentación consolidada de la iniciativa

- **Descripción**: Actualizar las cifras y el estado en `docs/README.md`, las referencias del MCP y el índice del roadmap.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 0,5h · real —
- **Tiempo IA (ejec.)**: est. 0,07h · real —
- **Supervisión**: est. 0,02h (≈25 % IA) · real —
- **Previsión IA**: 18k in / 2k out tok · ⚠️ verificar €
- **Dependencias**: T-48
- **Tipo**: docs
- **Archivos**: `docs/README.md`, `docs/modulos/mcp.md`, `docs/roadmap/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "metricool-parity-2" docs/roadmap/README.md` → una fila con el estado actualizado

**Criterios de aceptación**
- [ ] Las herramientas MCP nuevas figuran en la documentación.
- [ ] La fila del roadmap refleja el estado real.

**Subtareas**
- [ ] Actualizar índices

**Notas**: lectura: coherencia entre los módulos documentados.

### T-50 — Regresión completa: Pest, Playwright y Pint

- **Descripción**: Tarea final: suites completas en verde y estilo, antes de cerrar. El smoke real (M-01..M-05) lo ejecuta el usuario.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-06, T-18, T-23, T-32, T-41, T-47, T-49
- **Tipo**: test
- **Archivos**: `docs/roadmap/2026-10-24-metricool-parity-2/tasks.md`, `docs/roadmap/2026-10-24-metricool-parity-2/test-plan.md`, `docs/roadmap/2026-10-24-metricool-parity-2/spec.md`, `docs/roadmap/2026-10-24-metricool-parity-2/evaluation.md`, `docs/roadmap/2026-10-24-metricool-parity-2/design.md`, `docs/roadmap/2026-10-24-metricool-parity-2/improvement-plan.md`, `docs/referencia/metricool-analisis-bundle.md` (orquestador: artefactos de la cadena spec→evaluación→diseño→plan y el inventario fuente, commit 45092da; y, del Curator, los candidatos `docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md` y `docs/knowledge/candidates/pending/2026-10-24-importacion-media-pipeline-unico-por-pasadas.md`, más el índice `docs/roadmap/README.md`)
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07, E2E-08, E2E-09
- **Verificación**:
  - `php artisan test --compact` → 0 fallos
  - `npx playwright test --project=desktop` → 0 fallos
  - `npx playwright test --project=mobile` → 0 fallos
  - `npx playwright test --project=mobile-360` → 0 fallos
  - `vendor/bin/pint --test` → exit 0

**Criterios de aceptación**
- [ ] Pest y Playwright (desktop, mobile y mobile-360) completos en verde.
- [ ] Pint sin diferencias.
- [ ] Evidencia pegada en la tarea.

**Subtareas**
- [ ] Pest completo
- [ ] Playwright en los tres proyectos
- [ ] Pint

**Notas**: Requiere `npm run build` previo y el servidor de pruebas que levanta `playwright.config.ts`.

## Revisión de dos lentes — intento 1: Fase 1-2 (T-01..T-18) — 4 Important, 6 Minor

Lentes: A+B+C+D (C por ruta `AuthenticatedLayout.vue` · D por `SchedulerTool.php` y migraciones). Diff `main...feature/metricool-parity-2`. Lente A: los criterios de T-01..T-18 se cumplen (264 Pest en verde). Lente C: sin hallazgos (IDOR, mass assignment, XSS, SSRF y MCP revisados).

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | YouTube: un 403 `commentsDisabled` o un 404 `videoNotFound` de `commentThreads.list` (en `findOwnComment`) pasa por `exceptionFor()` y acaba en `fallido` pidiendo reconectar el canal. Debería dar `no_admitido` | T-10, T-11 | corregido (15eafce) | Lente B: `YouTubePublisher.php:300`, `PostFirstComment.php:94`; reproducido (probe S1) |
| 2 | Important | Un `Throwable` persistente con los intentos agotados deja el destino en `comentando` para siempre, sin aviso y sin Reintentar | T-11 | corregido (ae4ab3a) | Lente B: `PostFirstComment.php:112-115,143`; reproducido (probe S2) |
| 3 | Important | La idempotencia en Meta reutiliza `Comments::fetch()` del inbox: hasta 5 páginas, recorre también las respuestas y no tiene `$deadline`. Con un post popular supera el timeout de 110 s, y la rama `exhausted` vuelve a buscar, así que entra en bucle cada 10 min | T-09, T-11 | corregido (c8df39d) | Lente D gap 2: `Comments.php:52-131`, `PostFirstComment.php:389` |
| 4 | Important | La idempotencia no encuentra el comentario propio cuando el post tiene más de 250 comentarios (FB `reverse_chronological`) o más de 100 (YT `order=time`, los más recientes primero). En ese caso se duplica al reintentar o recuperar | T-09, T-10 | corregido (bf19aac; YouTube sin orden cronológico: `commentThreads.list` solo admite `time`/`relevance`, se usa `searchTerms` acotado; Instagram no documenta `order`, recorrido con tope) | Lente D (fuera de lente, verificado por el orquestador leyendo `findOwn`/`findOwnComment`) |
| 5 | Minor | N+1 en `SavedTextService::forBrand` (lazy `brand`) en cada carga del compositor | T-04 | corregido (ab81692) | Lente D gap 1: `SavedTextService.php:78-80`; con 100 textos, 101 consultas |
| 6 | Minor | `resumeComments` relanza cada pasada un `pendiente` cuyo `claim()` devuelve null para siempre (p. ej. `external_id` nulo) | T-11 | corregido (9a6f505) | Lente D (fuera de lente) |
| 7 | Minor | RED previo ausente en T-02 y retrospectivo en T-13 | T-02, T-13 | deuda aceptada (tests presentes y en verde; el ledger lo declara) | Lente A gap 1 |
| 8 | Minor | Ledger: T-07 `Archivos` cita `YouTubeConnector.php`, que no existe; la cabecera de la Fase 1 sigue `en-progreso`; T-16 dice «real Noneh» | T-06, T-07, T-16 | corregido (18a01fc) | Lente A gaps 2, 3 y 5 |
| 9 | Minor | La anotación de alcance de T-50 no declara los dos candidatos de `docs/knowledge/candidates/pending/` ni `docs/roadmap/README.md` | T-50 | corregido (1a182a9) | Lente A gap 4 |
| 10 | Minor | Con la cuota de YouTube agotada el comentario pasa a `fallido` a los ~6 min, aunque la cuota no se repone hasta el día siguiente | T-11 | anotado (degradación con aviso declarado) | Lente B (fuera de lente) |

## Revisión de dos lentes — intento 2: Fase 1-2 (T-01..T-18) — 1 Important, 1 Minor

Lentes: A+D (juntas) y B. Re-evaluación solo de lo corregido (`78e2a20..47b4228`). Los gaps 1, 2, 5, 6, 8 y 9 del intento 1 están resueltos. El gap 4 está resuelto en FB e IG; en YouTube no se puede verificar sin la API real (`searchTerms`; la evidencia de la documentación queda pendiente para el smoke M-01). El gap 3 cierra el bucle y el timeout, pero introduce el defecto 1. Tests: 312/312 y Publishing 142/142; `ledger-lint` y `scope-check` con exit 0.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | El «primer intento» se decide por `first_comment_attempts`, y Reintentar lo pone a 0. Con una búsqueda inconclusa, si el comentario pudo enviarse antes (timeout del POST que Meta sí creó), Reintentar lo crea a ciegas y queda duplicado. El mensaje de error pide justo esa acción | T-09, T-11, T-13 | corregido (999fa0f) | Lente B: `PostFirstComment.php:101`, `FirstCommentService.php:47-49`, `Comments.php:224`, `YouTubePublisher.php:337`; reproducido (probe2: 2 POST) |
| 2 | Minor | El resumen de horas de la Fase 2 (1.60) no cuadra con la suma de sus tareas (1.94, incluido T-16 estimado) | T-16 | corregido (aac5094) | Lente A+D: `tasks.md:50` frente a `:581` |
| 3 | Minor | La rama nueva de `resumeComments` deja `fallido` sin el aviso FirstCommentFailed (en la práctica no se alcanza) | T-11 | corregido (d168242) | Lente B (fuera de lente): `PublishRecovery.php:155-158` |

## Revisión de dos lentes — intento 3: Fase 1-2 (T-01..T-18) — 0 Important, 2 Minor

Lentes: A+B (juntas), re-evaluación de lo corregido en el intento 2 (`47b4228..HEAD`). El gap 1 del intento 2 queda corregido: probe3 hace 1 POST; `force` y `published` solo en `fallido`, con 404 para lo ajeno y throttle; `published` no llama a la red. Los gaps 2 y 3 también quedan corregidos. Tests: 665/665. `ledger-lint` y `scope-check` dan exit 0. **El bucle de revisión de la Fase 1-2 se cierra sin gaps Critical ni Important.**

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Minor | «Publicar igualmente» (`force`) sobrevive a `release()`. Si el POST forzado da timeout y la red sí lo creó, las pasadas siguientes vuelven a hacer POST a ciegas: 3 POST con un solo clic | T-11, T-13 | corregido (1d950d7) | probe5 (ahora 1 POST): `PostFirstComment.php:51,103,118-119,220` |
| 2 | Minor | La guía de usuario y `docs/modulos/publicacion.md` no recogen «Ya está publicado», «Publicar igualmente», `first_comment_sent_at` ni la ruta `/published`. Un «publicado» marcado a mano se ve igual que uno verificado | T-13, T-17 | corregido (dbfc4ed) | `guia-de-usuario.md:41`, `publicacion.md:52,56`, `FirstCommentService.php:66-70` |

## Revisión de dos lentes — intento 1: Fase 3-4 (T-19..T-32) — 3 Important, 14 Minor

Lentes: A, B (más autorización e IDOR, porque la heurística no activó C) y D. Las tres revisaron el rango fijo `26bd2a9..583cff9`. Según la lente A, las 14 tareas cumplen sus criterios y la paridad MCP está completa (19→20 con un assert equivalente). La lente B no encontró IDOR, mass assignment, XSS ni fuga de tokens. Tests: 35/35 de Campaigns e Instagram y 79/79 sumando Dashboard, Analytics y MCP.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | No se puede asignar ni quitar la campaña de un post que tenga algún destino publicado o en vuelo: `campaign_id` pasa por el bloqueo de edición de `update()`. Es un metadato, igual que las notas. Ni la web (selector deshabilitado) ni `update_post` dejan meter en una campaña posts ya publicados, y así el panel se queda vacío | T-27, T-28 | corregido (671ea6a) | Lente B: `PostService.php:130,140-141`; `ComposerHeader.vue` (`:disabled="!editable"`) |
| 2 | Important | `withLatestSnapshot`: la subconsulta `lm` calcula `MAX(date) GROUP BY post_target_id` sobre TODOS los snapshots del usuario, no solo sobre los destinos de la campaña. El coste crece lineal con el histórico (nunca se purga) | T-29, T-30 | corregido (94abc42) | Lente D: `AnalyticsService.php:294-301`, `CampaignAnalytics.php:49`; con 1,44 M filas, 206 ms frente a 0,8 ms acotada (×250) |
| 3 | Important | `daily()` hidrata como modelos todos los snapshots de la campaña, sin tope, y agrega en PHP. `summary()` carga todos los posts con su `text`. Una campaña de 1.000 posts × 2 redes × 90 días tarda 5,7 s y usa 375 MB; con 128 MB da un 500 a partir de unos 60 k snapshots | T-30 | corregido (3852150) | Lente D: `CampaignAnalytics.php:31,118-144` |
| 4 | Minor | Cuando Graph da error, el error no se cachea: cada visita a la cuadrícula repite entre 1 y 3 llamadas de hasta 15 s y consume cuota de la cuenta | T-20 | corregido (11296d5) | Lente D: `GridService.php:107-111` |
| 5 | Minor | Lo publicado no descarta los Reels con `is_shared_to_feed=false` | T-19, T-20 | corregido (11296d5) | Lente B: `InstagramGrid.php:17` |
| 6 | Minor | El panel de campaña muestra la fecha en UTC (no en la zona de la marca) y el estado en crudo | T-30 | corregido (3852150) | Lente B: `Campaigns/Show.vue:17,114` |
| 7 | Minor | La serie diaria arrastra el último valor no nulo mientras el KPI usa el último snapshot: el último punto no cuadra con el KPI | T-29 | corregido (3852150; documentado) | Lente B: `CampaignAnalytics.php:137` |
| 8 | Minor | El diálogo «Guardar en biblioteca» no muestra `errors.campaign_id` | T-27 | corregido (671ea6a) | Lente B: `ActionBar.vue:130` |
| 9 | Minor | Carrera: si se borra la campaña entre `plan()` y `save()`, el hook lanza una `InvalidArgumentException` y la web responde 500 | T-24, T-27 | corregido (671ea6a) | Lente B: `Post.php:44`, `PostController.php:59,82` |
| 10 | Minor | Los errores de Graph en la cuadrícula (que solo lee) hablan de «publicar» | T-19, T-21 | corregido (11296d5) | Lente B: `InstagramGrid.php:55` |
| 11 | Minor | Las cuentas IG desconectadas aparecen en el selector y pueden salir por defecto | T-20, T-21 | corregido (11296d5) | Lente B: `GridService.php:30-33` |
| 12 | Minor | Un destino en `publicando` o `procesando` desaparece de la cuadrícula hasta que caduca la caché | T-20 | corregido (11296d5) | Lente B: `GridService.php:53,108` |
| 13 | Minor | Las rutas `campaigns/{id}` de otro usuario devuelven 403 en vez de 404 (CWE-204), al contrario que la convención del proyecto | T-25 | corregido (671ea6a) | Lente B: `CampaignController.php:98` |
| 14 | Minor | MCP: en el esquema `campaign_id` es `integer` y no admite null, aunque la descripción diga que null la quita; además se aceptan campañas archivadas | T-28 | corregido (671ea6a) | Lente B (fuera de lente) |
| 15 | Minor | La lista del panel corta en 100 posts sin avisar (los KPIs sí cuentan todos) | T-30 | corregido (3852150) | Lente B (fuera de lente): `CampaignAnalytics.php:24,156` |
| 16 | Minor | Faltan `config/social.php` `instagram.grid_cache_minutes`/`grid_published` (30) de la spec: se usa una constante y se piden hasta 72 publicadas | T-19, T-20 | corregido (11296d5) | Lente A gap 2: `spec.md:76-77` |
| 17 | Minor | Ledger y documentación: `README.md:41` sigue diciendo «19 herramientas»; T-21 no tiene RED registrado; la evidencia de T-23 está fuera de su bloque; T-26 y T-29 tienen `Archivos` declarados que no se tocan | T-21, T-23, T-26, T-28, T-29 | corregido (efc2c66) | Lente A gaps 1, 3 y 4 |

Anotado sin corregir: en `PostFirstComment.php:59`, `requestedAt` sale del reloj del servidor web y se compara con `first_comment_sent_at`, que pone el worker. En producción los dos corren en el mismo host de Hestia y comparten reloj, así que se anota como riesgo y no como gap. Grid.vue usa `new Date("…+0000")`, que no se ha podido verificar en WebKit: se normaliza en el servidor dentro del gap 5.

Medición del tiempo de esta corrección: ESTIMADA (no se abrieron marcadores `usage-meter` por tarea `metricool-parity-2/T-XX-fix1`; el trabajo fue una sola pasada transversal a T-19..T-30).
E2E de Playwright no ejecutado en esta corrección (MySQL del servidor de pruebas): cambios de UI cubiertos con Pest (props de Inertia); los E2E de campañas, cuadrícula y compositor quedan pendientes de lanzar.
