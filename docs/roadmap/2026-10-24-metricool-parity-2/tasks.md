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
| Fase 1 — C-01a · Textos guardados | 0 | 6 | 0% | 0 / 16,5h | 0 / 2,2h | 0 / 0,6h | 0 / 668k |
| Fase 2 — C-01b · Primer comentario (IG, FB y YT) | 0 | 12 | 0% | 0 / 35,5h | 0 / 4,8h | 0 / 1,2h | 0 / 1,44M |
| Fase 3 — C-02 · Cuadrícula de Instagram | 0 | 5 | 0% | 0 / 23,5h | 0 / 3,2h | 0 / 0,8h | 0 / 952k |
| Fase 4 — C-03 · Campañas | 0 | 9 | 0% | 0 / 36,5h | 0 / 4,9h | 0 / 1,2h | 0 / 1,48M |
| Fase 5 — C-04a · Media desde URL | 0 | 9 | 0% | 0 / 24,5h | 0 / 3,3h | 0 / 0,8h | 0 / 992k |
| Fase 6 — C-04b · Media desde Google Drive | 0 | 6 | 0% | 0 / 14,5h | 0 / 2,0h | 0 / 0,5h | 0 / 587k |
| Fase 7 — Transversal y cierre | 0 | 3 | 0% | 0 / 3,5h | 0 / 0,5h | 0 / 0,1h | 0 / 142k |
| **TOTAL** | **0** | **50** | **0%** | **0 / 154,5h** | **0 / 20,9h** | **0 / 5,2h** | **0 / 6,26M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real). No hay `.claude/jira.json`: no aplica.

> **Diferencias con la evaluación (declaradas).** Las horas por entrega son las de la evaluación (C-01a 17h, C-01b 36h, C-02 24h, C-03 37h, C-04a 25h, C-04b 15,5h = 154,5h base). Las tareas transversales **no suman horas nuevas**: salen de las partidas de documentación y revisión de cada entrega. T-48 (guía de Google Cloud, 1h) sale de C-04b; T-49 (0,5h) de C-04a; T-50 (regresión, 2h) de C-01a, C-01b, C-02 y C-03 (0,5h cada una). Las revisiones de robustez (T-18, T-41, T-47) también están dentro de las partidas de la evaluación. Total: **154,5h base = 185,4h con margen**.

## Conocimiento pendiente del Curator

Candidatos del `architect` pendientes de decisión. Se conservan la ruta y el hash de la propuesta original; el Curator devolverá el `operation_id` y el recibo al aplicarlos.

```curation-refs-v1
[{"candidate":"docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md","sha256":"1cfc13515922274563a93d43123a0d0fea46a6b6f989ee457cc0fb22d44bfa90"},{"candidate":"docs/knowledge/candidates/pending/2026-10-24-importacion-media-pipeline-unico-por-pasadas.md","sha256":"183d95537cc91beaf5fbb2c922598c08b989c1e557967e5029ad445f07be2df1"}]
```

---

## Fase 1 — C-01a · Textos guardados

**Estado**: borrador · **Estimado**: 16,5h base (19,8h con margen) · **Real**: — · **Coste est.**: 990 € + tokens (⚠️ verificar) · **Tokens est.**: 668k

### T-01 — Migración, modelo y factoría de `SavedText`

- **Descripción**: Crear la tabla `saved_texts` (`user_id`, `brand_id` nulo, `kind` `fragmento`/`hashtags`, `title`, `body`) y el modelo con `UserScope`. Requisito: D1 (por usuario con marca opcional; nula = «Todas las marcas»), CA-01 y CA-03.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_saved_texts_table.php`, `app/Models/SavedText.php`, `database/factories/SavedTextFactory.php`, `tests/Feature/SavedTexts/SavedTextModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/SavedTexts/SavedTextModelTest.php` → todos en verde (aislamiento por usuario y `brand_id` nulo)

**Criterios de aceptación**
- [ ] La migración es aditiva y reversible (`down()` borra solo `saved_texts`).
- [ ] El modelo usa `UserScope`: otro usuario no ve filas ajenas.
- [ ] `brand_id` nulo es válido; al borrar la marca se borran sus textos (`cascadeOnDelete`).

**Subtareas**
- [ ] Escribir el test RED de aislamiento
- [ ] Migración, modelo y factoría

**Notas**: Patrón: `app/Models/ComposerPreset.php` y el trait `app/Models/Concerns/BelongsToUser.php` (`UserScope`). Migraciones solo aditivas.

### T-02 — `SavedTextService`, request, controlador, política y rutas CRUD

- **Descripción**: CRUD de textos guardados con propiedad (otro usuario recibe 403) y validación de `kind`, título y cuerpo. Requisito: CA-03.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real —
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `app/Services/SavedTextService.php`, `app/Http/Controllers/SavedTextController.php`, `app/Http/Requests/SavedTextRequest.php`, `app/Policies/SavedTextPolicy.php`, `routes/web.php`, `tests/Feature/SavedTexts/SavedTextCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/SavedTexts/SavedTextCrudTest.php` → todos en verde (alta, edición, borrado y 403 de otro usuario)

**Criterios de aceptación**
- [ ] Alta, edición y borrado devuelven los códigos esperados y persisten.
- [ ] Un usuario ajeno recibe 403 al editar o borrar (CA-03).
- [ ] La marca, si se informa, debe ser del usuario.

**Subtareas**
- [ ] Test RED del 403
- [ ] Servicio, request y política
- [ ] Rutas `settings/saved-texts*`

**Notas**: Patrón: `app/Services/ComposerPresetService.php` y `app/Policies/PostTemplatePolicy.php`. Rutas junto a las de presets en `routes/web.php`.

### T-03 — Página de ajustes «Textos guardados» con entrada de navegación

- **Descripción**: Página `SavedTexts/Index.vue`: lista, búsqueda, alta, edición y borrado, con filtro por marca o «Todas las marcas». UI en español. Requisito: CA-03.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
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
- [ ] La página lista los textos del usuario y filtra por marca.
- [ ] Alta, edición y borrado funcionan sin recargar la app.
- [ ] Hay entrada de navegación hacia la página.

**Subtareas**
- [ ] Test RED de la página Inertia
- [ ] Componente Vue
- [ ] Entrada de menú

**Notas**: Patrón de páginas de ajustes: `resources/js/Pages/Profile` y `resources/js/Pages/Library`. El E2E con axe va en T-05.

### T-04 — Menú «Textos guardados» en el compositor e inserción en el cursor

- **Descripción**: Menú en `EditorToolbar` con los textos de la marca más los de «Todas las marcas»; inserción en el cursor del campo activo (plantilla, texto propio del destino o primer comentario) con «campo activo» en `useComposer.js`. Requisito: CA-01, CA-02.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real —
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/EditorToolbar.vue`, `resources/js/Components/Composer/TextEditor.vue`, `resources/js/Components/Composer/SavedTextsMenu.vue`, `resources/js/Composables/useComposer.js`, `app/Http/Controllers/PostController.php`, `tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php`
- **Cubre (tests)**: E2E-02
- **Verificación**:
  - `php artisan test --compact tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php` → verde (props del compositor: la marca A ve «Todas» y A, no B)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] El compositor de la marca A recibe los textos de «Todas las marcas» y los de A, y no los de B (CA-01).
- [ ] Insertar en mitad del texto deja el contenido en el cursor y actualiza el contador de hashtags de Instagram (CA-02).
- [ ] El «campo activo» se registra en `useComposer.js`, no por componente.

**Subtareas**
- [ ] Test RED de las props por marca
- [ ] `SavedTextsMenu.vue` y campo activo
- [ ] Inserción en el cursor

**Notas**: Patrón: `resources/js/Composables/useComposer.js` y `textCount.js`; menú hermano `resources/js/Components/Composer/EmojiPicker.vue`. El campo del primer comentario (T-14) reutiliza el campo activo.

### T-05 — E2E de textos guardados con axe y sin desbordamiento

- **Descripción**: Playwright de la página de ajustes y del menú del compositor en desktop, 375 y 360 px, con axe y `expectNoOverflow`. Requisito: CA-01..CA-03.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
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
- [ ] 0 violaciones `serious`/`critical` de axe.
- [ ] Sin desbordamiento horizontal a 375 y 360 px.
- [ ] Solo usuarios `e2e-sec-*` y marcas `e2e-`.

**Subtareas**
- [ ] Spec del CRUD
- [ ] Spec de inserción en el cursor

**Notas**: Patrón: `tests/e2e/composer.spec.ts` y los helpers de `tests/e2e/helpers.ts` (`loginAs`, `expectNoOverflow`, `borrarMarcasE2e`).

### T-06 — Documentación y revisión de C-01a

- **Descripción**: Documentar los textos guardados (guía de usuario y módulo de publicación) y revisar propiedad y aislamiento antes de cerrar la entrega.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-05
- **Tipo**: docs
- **Archivos**: `docs/usuario/guia-de-usuario.md`, `docs/modulos/publicacion.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "Textos guardados" docs/usuario/guia-de-usuario.md docs/modulos/publicacion.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [ ] La guía explica cómo crear, filtrar por marca e insertar un texto.
- [ ] El módulo describe la tabla y la política.
- [ ] Revisión de propiedad hecha: ningún endpoint devuelve textos ajenos.

**Subtareas**
- [ ] Redactar documentación
- [ ] Revisión de robustez de propiedad

**Notas**: lectura: contrastar con CA-01..CA-03.

---

## Fase 2 — C-01b · Primer comentario (IG, FB y YT)

**Estado**: borrador · **Estimado**: 35,5h base (42,6h con margen) · **Real**: — · **Coste est.**: 2.130 € + tokens (⚠️ verificar) · **Tokens est.**: 1,44M

### T-07 — Scope `youtube.force-ssl` y detección de reconexión

- **Descripción**: Añadir `youtube.force-ssl` a los scopes de YouTube y detectar las cuentas conectadas sin él: el primer comentario de YouTube se desactiva con «Reconecta el canal para comentar». Requisito: D2 (el usuario reconectará) y diseño §6.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `config/social.php`, `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeConnector.php`, `tests/Feature/Connections/YouTubeCommentScopeTest.php`
- **Cubre (tests)**: M-02
- **Verificación**: `php artisan test --compact tests/Feature/Connections/YouTubeCommentScopeTest.php` → verde (scope pedido y aviso sin scope)

**Criterios de aceptación**
- [ ] El conjunto de scopes de YouTube incluye `youtube.force-ssl` y conserva los anteriores.
- [ ] Una cuenta sin el scope se marca como «no puede comentar» sin romper la publicación.
- [ ] Los tests de conexiones existentes siguen en verde.

**Subtareas**
- [ ] Caracterización de los scopes actuales
- [ ] Cambio de scopes y comprobación al estilo `hasScopes`

**Notas**: `config/social.php:60-62` (scopes de YouTube). Patrón de comprobación: `Comments::hasScopes` en `app/Connectors/Meta/Comments.php:32`. La reconexión invalida los tokens actuales (riesgo aceptado en D2).

### T-08 — Migración aditiva del estado del comentario en `post_targets` y enum `FirstCommentStatus`

- **Descripción**: Seis columnas nulas en `post_targets` (`first_comment`, `first_comment_status`, `first_comment_attempts`, `first_comment_external_id`, `first_comment_error`, `first_comment_at`) con índice `(first_comment_status, first_comment_at)` y el enum `FirstCommentStatus` (pendiente · comentando · publicado · fallido · no_admitido). Requisito: diseño, opción A1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_add_first_comment_to_post_targets.php`, `app/Models/PostTarget.php`, `app/Enums/FirstCommentStatus.php`, `tests/Feature/FirstComment/FirstCommentColumnsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment/FirstCommentColumnsTest.php` → verde (columnas, casts y valores del enum)

**Criterios de aceptación**
- [ ] La migración solo añade columnas nulas y se revierte sin perder datos.
- [ ] `PostTarget` castea el estado al enum.
- [ ] El estado del comentario es independiente de `PostStatus`.

**Subtareas**
- [ ] Test RED de columnas y casts
- [ ] Migración, enum y modelo

**Notas**: Patrón de enums: `app/Enums/`. Modelo: `app/Models/PostTarget.php`. Diseño §5 (A1: columnas en `post_targets`, no tabla aparte).

### T-09 — Contrato `FirstCommenter` y `comment()`/`findOwnComment()` en Instagram y Facebook

- **Descripción**: Interfaz `App\Connectors\Contracts\FirstCommenter`, `ConnectorRegistry::commenterFor()` y su implementación en `InstagramPublisher` y `FacebookPublisher` sobre `Meta/Comments` (buscar con `fetch()` + `is_own` + texto normalizado). Requisito: CA-04, diseño A1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Connectors/Contracts/FirstCommenter.php`, `app/Connectors/ConnectorRegistry.php`, `app/Connectors/Meta/Comments.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php`, `app/Connectors/Meta/Facebook/FacebookPublisher.php`, `tests/Feature/Connectors/FirstCommenterMetaTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/FirstCommenterMetaTest.php` → verde con `Http::fake` (comenta, encuentra el propio, mapea errores)

**Criterios de aceptación**
- [ ] `comment()` devuelve el id del comentario y `findOwnComment()` lo encuentra por `is_own` y texto.
- [ ] Los errores de Graph se traducen al español; «comentarios cerrados» se mapea a `no_admitido`.
- [ ] `Publisher` no cambia y TikTok no implementa el contrato.

**Subtareas**
- [ ] Test RED con `Http::fake`
- [ ] Interfaz y registro
- [ ] Instagram y Facebook

**Notas**: `app/Connectors/Meta/Comments.php:50` (`fetch`), `:133` (`reply`), `:151` (`normalize` con `is_own`). Pendiente de verificar en la documentación de Meta: el comentario de Reels de Facebook va sobre el id del vídeo.

### T-10 — `comment()` y `findOwnComment()` en YouTube con cuota

- **Descripción**: `commentThreads.insert` y `commentThreads.list?videoId=…` filtrado por `authorChannelId` en `YouTubePublisher`, con las 50 unidades de cuota en `YouTubeQuota`. Requisito: CA-04, D2.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-07, T-09
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeQuota.php`, `tests/Feature/Connectors/FirstCommenterYouTubeTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/FirstCommenterYouTubeTest.php` → verde con `Http::fake` (inserta, encuentra el propio; privado o «para niños» → `no_admitido`)

**Criterios de aceptación**
- [ ] Comentar descuenta 50 unidades de cuota.
- [ ] Vídeo privado o «hecho para niños» da `no_admitido`.
- [ ] Sin el scope no se llama a la API.

**Subtareas**
- [ ] Test RED con `Http::fake`
- [ ] Implementación y cuota

**Notas**: Patrón de llamadas y cuota: `app/Connectors/Google/YouTubePublisher.php` (`beginPass` en `:413`). Verificado en la documentación oficial: `commentThreads.insert` exige `youtube.force-ssl` y cuesta 50 unidades.

### T-11 — Job `PostFirstComment`, enganche en `published()` y recuperación

- **Descripción**: Job `PostFirstComment` (`ShouldBeUnique`, cola `poll`, reclamo con `lockForUpdate`) encolado desde `PublishOutcomes::published()` con `afterCommit`; idempotencia en tres capas, backoff `[60, 300]`, tope de 3 intentos, espera a que el vídeo de YouTube esté publicado y paso 6 de `PublishRecovery`. Un fallo no degrada el destino. Requisito: CA-04, CA-05; diseño A1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-08, T-09, T-10
- **Tipo**: backend
- **Archivos**: `app/Jobs/PostFirstComment.php`, `app/Services/PublishOutcomes.php`, `app/Services/PublishRecovery.php`, `config/publishing.php`, `tests/Feature/FirstComment/PostFirstCommentJobTest.php`, `tests/Feature/FirstComment/FirstCommentRecoveryTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/FirstComment/PostFirstCommentJobTest.php` → verde (repetir el job no crea un segundo comentario; un fallo deja el destino `Published`)
  - `php artisan test --compact tests/Feature/Publishing/PublishingTest.php` → verde (el camino de publicación no cambia)
  - `php artisan test --compact tests/Feature/FirstComment/FirstCommentRecoveryTest.php` → verde

**Criterios de aceptación**
- [ ] Si ya hay `first_comment_external_id`, el job no hace nada; siempre busca el comentario propio antes de crear uno.
- [ ] El destino sigue `Published` ante cualquier fallo del comentario; el comentario queda `fallido` con el motivo en español.
- [ ] `PublishPostTarget` no se modifica y `published()` es el único punto de entrada.

**Subtareas**
- [ ] Caracterización de `PublishingTest` y `PublishNowDoubleDispatchTest`
- [ ] Test RED de idempotencia
- [ ] Job, enganche y recuperación

**Notas**: `app/Services/PublishOutcomes.php:38` (`published`). Reclamo: copiar el `claim()` de `app/Jobs/PublishPostTarget.php`. Idempotencia: `tests/Feature/Publishing/TargetTextIdempotencyInstagramTest.php`. Recuperación: `app/Services/PublishRecovery.php:32` (`run`).

### T-12 — `PostRequest`, `PostService::plan()`/`fill()` y máximos en `media_specs`

- **Descripción**: Regla `targets.*.first_comment`, máximo por tipo en `config/media_specs.php` (IG 2.200, FB 8.000, YT 10.000) y `fill()` sin reiniciar el intento de publicación cuando solo cambia el comentario; no se ofrece en TikTok ni en Stories. Requisito: CA-06.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
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
- [ ] Se rechaza un comentario por encima del máximo de su red.
- [ ] TikTok y Stories no admiten `first_comment`.
- [ ] Cambiar solo `first_comment` no llama a `freshAttempt()`.

**Subtareas**
- [ ] Caracterización de `TargetTextPlanFillTest`
- [ ] Test RED de máximos
- [ ] Regla, `plan()` y `fill()`

**Notas**: Patrón: validación de texto por destino en `app/Services/PostService.php` (`plan()` en `:460`) y límites en `config/media_specs.php`. Comparte ficheros con C-03: va antes (en serie).

### T-13 — `FirstCommentService`: editar, reintentar y aviso in-app

- **Descripción**: Servicio con `update()` (solo si el comentario está `fallido`; no toca `text` ni el intento) y `retry()`, rutas `PATCH posts/{post}/targets/{target}/first-comment` y `POST …/first-comment/retry` con `throttle` y política del post, y la notificación `FirstCommentFailed` (canal `database`). Requisito: CA-05 y la decisión del usuario de poder editar el comentario fallido antes de reintentar (A1).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-12
- **Tipo**: backend
- **Archivos**: `app/Services/FirstCommentService.php`, `app/Http/Controllers/FirstCommentController.php`, `app/Notifications/FirstCommentFailed.php`, `routes/web.php`, `tests/Feature/FirstComment/FirstCommentRetryTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment/FirstCommentRetryTest.php` → verde (edita solo en `fallido`, reintenta con el texto vigente, 403 ajeno, aviso creado)

**Criterios de aceptación**
- [ ] `update()` rechaza si el comentario no está `fallido`.
- [ ] `retry()` reinicia intentos, despacha el job y usa el texto vigente.
- [ ] Un fallo genera una notificación in-app.

**Subtareas**
- [ ] Test RED del flujo editar y reintentar
- [ ] Servicio, rutas y política
- [ ] Notificación

**Notas**: Patrón de notificaciones: `app/Notifications/PublishFailed.php`. Política: `app/Policies/PostPolicy.php`. Es la única edición admitida en un destino `Published` y no pasa por `lockEditableTargets()`.

### T-14 — UI del primer comentario: campo por destino, contador, estado, editar y Reintentar

- **Descripción**: Campo `textarea` de primer comentario por destino con contador por red, oculto en TikTok y Stories, con inserción de textos guardados, estado y botones Editar/Reintentar en `TargetStatusPanel`. Requisito: CA-05, CA-06.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-04, T-12, T-13
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/NetworkTabs.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/TargetStatusPanel.vue`, `resources/js/Composables/useComposer.js`, `tests/Feature/Posts/Compose/ComposeTest.php`
- **Cubre (tests)**: E2E-03, E2E-04, M-01
- **Verificación**:
  - `php artisan test --compact tests/Feature/Posts/Compose/ComposeTest.php` → verde (props del destino con `first_comment` y su estado)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] El campo aparece en IG, FB y YT, y no en TikTok ni en Stories.
- [ ] El estado del comentario se ve en `TargetStatusPanel`, con Reintentar y edición del texto cuando está `fallido`.
- [ ] El contador respeta el máximo de la red.

**Subtareas**
- [ ] Test RED de props
- [ ] Campo y contador
- [ ] Estado, editar y Reintentar

**Notas**: Patrón: `resources/js/Components/Composer/TargetStatusPanel.vue` y `TargetOptions.vue`. La inserción de textos guardados reutiliza el campo activo de T-04.

### T-15 — Paridad MCP del primer comentario (`PostPayload`, `create_post`, `update_post`, `get_post`)

- **Descripción**: Esquema `targets.*.first_comment` en `PostPayload` y estado del comentario en `GetPost`. `update_post` sobre un post publicado sigue rechazando `first_comment`. Requisito: CA-07.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/GetPost.php`, `app/Mcp/Tools/CreatePost.php`, `app/Mcp/Tools/UpdatePost.php`, `tests/Feature/Mcp/Tools/McpFirstCommentTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpFirstCommentTest.php` → verde (crea con `first_comment` y `get_post` lo devuelve con estado)
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpTargetTextTest.php` → verde (la paridad de texto por destino no cambia)

**Criterios de aceptación**
- [ ] Las reglas de validación son las de `PostRequest::rules()`.
- [ ] `get_post` devuelve `first_comment`, `first_comment_status` y `first_comment_error`.
- [ ] Editar el comentario fallido por MCP queda fuera de esta iteración.

**Subtareas**
- [ ] Caracterización de `McpTargetTextTest`
- [ ] Test RED de paridad
- [ ] Esquema y salida

**Notas**: `app/Mcp/Tools/PostPayload.php:35` (`validatedPayload` ya reutiliza `PostRequest::rules()`). Patrón de test: `tests/Feature/Mcp/Tools/McpTargetTextTest.php`. Tarea transversal de paridad MCP.

### T-16 — Pest de integración del primer comentario con `Http::fake`

- **Descripción**: Casos CA-04..CA-07 de extremo a extremo con redes simuladas: «creado pero no listado», doble despacho, error de red, reconexión y reintento.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-13, T-15
- **Tipo**: test
- **Archivos**: `tests/Feature/FirstComment/FirstCommentFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment` → todos en verde

**Criterios de aceptación**
- [ ] Nunca se llama a una red real.
- [ ] El caso «la lista de Meta aún no muestra el comentario» no duplica.
- [ ] Cubre IG, FB y YT.

**Subtareas**
- [ ] Escenarios de flujo
- [ ] Casos de borde de idempotencia

**Notas**: Patrón: `tests/Feature/Publishing/TargetTextIdempotencyFacebookTest.php`.

### T-17 — E2E y documentación del primer comentario

- **Descripción**: Playwright con axe del campo, el estado y Reintentar (destino sembrado con un helper), a 375 y 360 px, más la documentación del módulo y de la reconexión de YouTube. Requisito: CA-05, CA-06.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
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
- [ ] 0 violaciones `serious`/`critical` de axe.
- [ ] Sin desbordamiento a 375 y 360 px.
- [ ] La documentación explica estados, edición y reconexión de YouTube.

**Subtareas**
- [ ] Helper de siembra de destino publicado
- [ ] Spec E2E
- [ ] Documentación

**Notas**: Patrón: `tests/e2e/composer.spec.ts`. Solo `e2e-sec-*`; nunca comenta en redes reales.

### T-18 — Revisión de robustez del primer comentario

- **Descripción**: Revisión específica de doble comentario y de estados colgados (LES-001, LES-009): carreras entre el job y la recuperación, edición concurrente y comentarios en `comentando` tras un `queue:restart`.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17
- **Tipo**: docs
- **Archivos**: `tests/Feature/FirstComment/FirstCommentFlowTest.php`, `docs/roadmap/2026-10-24-metricool-parity-2/tasks.md`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/FirstComment tests/Feature/Publishing` → todos en verde

**Criterios de aceptación**
- [ ] Sin gaps Critical/Important abiertos.
- [ ] Cada hallazgo corregido tiene test.
- [ ] Sección «Revisión de dos lentes» anotada en la tarea.

**Subtareas**
- [ ] Revisar carreras
- [ ] Anotar hallazgos

**Notas**: lectura: confirmar que no hay camino a un duplicado fuera de la ventana «la red aceptó, el worker murió antes de guardar el id».

---

## Fase 3 — C-02 · Cuadrícula de Instagram

**Estado**: borrador · **Estimado**: 23,5h base (28,2h con margen) · **Real**: — · **Coste est.**: 1.410 € + tokens (⚠️ verificar) · **Tokens est.**: 952k

### T-19 — `InstagramGrid`: lectura paginada de `/{ig}/media`

- **Descripción**: Lector nuevo (sin tocar `InstagramPublisher`) con `media_type`, `media_url`, `thumbnail_url`, `permalink` y `timestamp`, primera pieza del carrusel y errores. Requisito: CA-08.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Instagram/InstagramGrid.php`, `tests/Feature/Instagram/InstagramGridReaderTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Instagram/InstagramGridReaderTest.php` → verde con `Http::fake` (paginación, carrusel, error de Graph)

**Criterios de aceptación**
- [ ] Lee y normaliza las publicaciones con los campos pedidos.
- [ ] Los errores de Graph se traducen sin lanzar excepción hacia la página.
- [ ] No necesita permisos nuevos (`instagram_basic`).

**Subtareas**
- [ ] Test RED con `Http::fake`
- [ ] Lector paginado

**Notas**: Ya se lee `/{ig}/media` para la idempotencia en `app/Connectors/Meta/Instagram/InstagramPublisher.php:402`; llamada vía `app/Connectors/Meta/GraphClient.php`.

### T-20 — `GridService`: mezcla con lo programado, orden, caché y selector de cuenta

- **Descripción**: Servicio que pone arriba los destinos programados (la más tardía primero; sin Stories; Reel solo con «compartir en el feed»; URL de `MediaAsset::publicUrl()`) y debajo lo publicado, con caché de 10 min y selector de cuenta. Requisito: CA-08, CA-09.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: backend
- **Archivos**: `app/Services/GridService.php`, `tests/Feature/Instagram/GridServiceTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Instagram/GridServiceTest.php` → verde (orden, Story excluida, caché de 10 min)

**Criterios de aceptación**
- [ ] Las programadas van antes que las publicadas, la más tardía primero (CA-08).
- [ ] Una Story programada no aparece (CA-09).
- [ ] La segunda lectura dentro de 10 min sale de caché.

**Subtareas**
- [ ] Test RED de orden y Stories
- [ ] Servicio y caché

**Notas**: Patrón de servicios de lectura y caché: `app/Services/CalendarService.php`.

### T-21 — Ruta, controlador y `Pages/Instagram/Grid.vue` con navegación y miniatura de vídeo

- **Descripción**: Página «Cuadrícula» por cuenta de Instagram de la marca: tres columnas, insignias, enlaces al compositor e Instagram, estados vacío y de error, aviso de posts fijados y 360 px; vídeo sin miniatura con `<video preload="metadata">`. Requisito: CA-08..CA-10.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 9,0h · real —
- **Tiempo IA (ejec.)**: est. 1,22h · real —
- **Supervisión**: est. 0,30h (≈25 % IA) · real —
- **Previsión IA**: 328k in / 36k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: frontend
- **Archivos**: `routes/web.php`, `app/Http/Controllers/InstagramGridController.php`, `resources/js/Pages/Instagram/Grid.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Feature/Ui/InstagramGridPageTest.php`
- **Cubre (tests)**: E2E-05, M-03, M-05
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/InstagramGridPageTest.php` → verde (componente Inertia y propiedad de la cuenta)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] Tres columnas con el orden del perfil y enlace a cada pieza.
- [ ] Un usuario ajeno no accede a la cuadrícula de otra marca.
- [ ] Se avisa de que los fijados no se reflejan.

**Subtareas**
- [ ] Test RED de la página
- [ ] Controlador y ruta
- [ ] Componente y navegación

**Notas**: Patrón de página y controlador: `resources/js/Pages/Calendar` y `app/Http/Controllers/CalendarController.php`.

### T-22 — E2E de la cuadrícula con axe y sin desbordamiento

- **Descripción**: Playwright de la cuadrícula en desktop, 375 y 360 px, con axe. Requisito: CA-10.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-21
- **Tipo**: test
- **Archivos**: `tests/e2e/instagram-grid.spec.ts`
- **Cubre (tests)**: E2E-05
- **Verificación**:
  - `npx playwright test tests/e2e/instagram-grid.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/instagram-grid.spec.ts --project=mobile` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [ ] 0 violaciones `serious`/`critical` de axe.
- [ ] Sin desbordamiento a 375 y 360 px.
- [ ] Datos solo con marcas `e2e-`.

**Subtareas**
- [ ] Spec de cuadrícula

**Notas**: Patrón: `tests/e2e/pages.spec.ts`.

### T-23 — Documentación y revisión de C-02

- **Descripción**: Documentar la cuadrícula y sus límites (URLs del CDN que caducan, fijados, recorte de teselas) y revisar el consumo de Graph (una lectura cada 10 min por cuenta).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-22
- **Tipo**: docs
- **Archivos**: `docs/modulos/calendario.md`, `docs/usuario/guia-de-usuario.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "Cuadrícula" docs/modulos/calendario.md docs/usuario/guia-de-usuario.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [ ] Documentados los límites de la cuadrícula.
- [ ] Revisadas la caché y los errores de Graph.

**Subtareas**
- [ ] Redactar documentación
- [ ] Revisión

**Notas**: lectura: contrastar con CA-08..CA-10.

---

## Fase 4 — C-03 · Campañas

**Estado**: borrador · **Estimado**: 36,5h base (43,8h con margen) · **Real**: — · **Coste est.**: 2.190 € + tokens (⚠️ verificar) · **Tokens est.**: 1,48M

### T-24 — Migración, modelo `Campaign`, `UserScope` y regla «misma marca»

- **Descripción**: Tabla `campaigns` por marca (nombre, color, fechas, archivo) y `posts.campaign_id` (`nullOnDelete`). Requisito: D3 (por marca y asignada al post entero), CA-12.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_campaigns_table.php`, `database/migrations/*_add_campaign_id_to_posts.php`, `app/Models/Campaign.php`, `app/Models/Post.php`, `database/factories/CampaignFactory.php`, `tests/Feature/Campaigns/CampaignModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Campaigns/CampaignModelTest.php` → verde (aislamiento y regla de misma marca)

**Criterios de aceptación**
- [ ] Migraciones aditivas.
- [ ] Borrar la campaña deja el post sin campaña.
- [ ] Un post solo admite campañas de su marca.

**Subtareas**
- [ ] Test RED
- [ ] Migraciones y modelo

**Notas**: Patrón: `app/Models/ComposerPreset.php`; trait `app/Models/Concerns/BelongsToUser.php`.

### T-25 — `CampaignService`, controlador y validación

- **Descripción**: CRUD de campañas con archivo, propiedad y validación. Requisito: CA-12, CA-13.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real —
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-24
- **Tipo**: backend
- **Archivos**: `app/Services/CampaignService.php`, `app/Http/Controllers/CampaignController.php`, `app/Http/Requests/CampaignRequest.php`, `app/Policies/CampaignPolicy.php`, `routes/web.php`, `tests/Feature/Campaigns/CampaignCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Campaigns/CampaignCrudTest.php` → verde (CRUD, archivo y 403 ajeno)

**Criterios de aceptación**
- [ ] Alta, edición, archivo y borrado funcionan.
- [ ] Otro usuario recibe 403.
- [ ] Las fechas validan que fin ≥ inicio.

**Subtareas**
- [ ] Test RED
- [ ] Servicio, controlador y política

**Notas**: Patrón: `app/Services/ComposerPresetService.php` y `app/Policies/PostTemplatePolicy.php`.

### T-26 — Página de campañas: lista, alta, edición, archivo y color

- **Descripción**: `Pages/Campaigns/Index.vue` con la gestión completa y entrada de navegación. Requisito: CA-13.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-25
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Campaigns/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Feature/Ui/CampaignsPageTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/CampaignsPageTest.php` → verde (componente Inertia y props)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] Lista, crea, edita y archiva campañas de la marca.
- [ ] El color se ve con contraste suficiente.
- [ ] Entrada de navegación disponible.

**Subtareas**
- [ ] Test RED
- [ ] Componente

**Notas**: Patrón de páginas de gestión: `resources/js/Pages/Autolists`.

### T-27 — Selector de campaña en el compositor, `PostRequest` y `PostService::fill()`

- **Descripción**: Selector en `ComposerHeader` con alta en línea; `campaign_id` en `PostRequest` y `fill()`; las copias desde biblioteca y plantillas conservan la campaña. Requisito: CA-12.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 4,5h · real —
- **Tiempo IA (ejec.)**: est. 0,61h · real —
- **Supervisión**: est. 0,15h (≈25 % IA) · real —
- **Previsión IA**: 164k in / 18k out tok · ⚠️ verificar €
- **Dependencias**: T-25, T-12
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/ComposerHeader.vue`, `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Services/LibraryService.php`, `tests/Feature/Campaigns/CampaignAssignTest.php`
- **Cubre (tests)**: E2E-06
- **Verificación**:
  - `php artisan test --compact tests/Feature/Campaigns/CampaignAssignTest.php` → verde (rechaza campaña de otra marca; las copias conservan la campaña)
  - `php artisan test --compact tests/Feature/Library` → verde (biblioteca sin regresión)

**Criterios de aceptación**
- [ ] Asignar una campaña de la marca A a un post de la marca B se rechaza (CA-12).
- [ ] Duplicar o guardar desde biblioteca conserva la campaña.
- [ ] Alta en línea desde el compositor.

**Subtareas**
- [ ] Caracterización de `tests/Feature/Library` y `PostsTest`
- [ ] Test RED
- [ ] Selector y servicio

**Notas**: Comparte `PostRequest`, `PostService` y `PostPayload` con C-01b: va después (T-12, T-15), en serie. `app/Services/LibraryService.php:68` (`store` copia destinos).

### T-28 — Paridad MCP de campañas: `campaign_id` y `list_campaigns`

- **Descripción**: `campaign_id` en `create_post`, `update_post` y `get_post`, y herramienta `list_campaigns` registrada en el servidor. Requisito: CA-13. Tarea transversal de paridad MCP.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-15
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/GetPost.php`, `app/Mcp/Tools/ListCampaigns.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpCampaignsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpCampaignsTest.php` → verde (paridad de validación y `list_campaigns`)
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpFirstCommentTest.php` → verde (sin regresión)

**Criterios de aceptación**
- [ ] `campaign_id` valida igual que en la web.
- [ ] `list_campaigns` devuelve solo las campañas de la marca.
- [ ] `get_post` expone la campaña.

**Subtareas**
- [ ] Caracterización del payload actual
- [ ] Test RED
- [ ] Herramienta y registro

**Notas**: `app/Mcp/Servers/SchedulerServer.php:42` (`$tools`). Patrón de herramienta: `app/Mcp/Tools/ListBrands.php`.

### T-29 — `CampaignAnalytics`: extraer la consulta del último snapshot y agregar

- **Descripción**: Extraer de `AnalyticsService::postStats` la consulta del último snapshot sin cambiar el dashboard, y agregar por red, totales y serie diaria; una métrica sin datos es `null`, no 0. Requisito: CA-11.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 8,5h · real —
- **Tiempo IA (ejec.)**: est. 1,15h · real —
- **Supervisión**: est. 0,29h (≈25 % IA) · real —
- **Previsión IA**: 310k in / 34k out tok · ⚠️ verificar €
- **Dependencias**: T-24
- **Tipo**: backend
- **Archivos**: `app/Services/CampaignAnalytics.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Campaigns/CampaignAnalyticsTest.php`, `tests/Feature/Dashboard/DashboardTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Dashboard/DashboardTest.php` → verde antes y después de extraer
  - `php artisan test --compact tests/Feature/Campaigns/CampaignAnalyticsTest.php` → verde (suma de últimos snapshots; `null` y sin snapshots)

**Criterios de aceptación**
- [ ] Los KPIs suman los últimos snapshots de cada destino (CA-11).
- [ ] «Sin datos» se distingue de 0.
- [ ] El dashboard conserva su comportamiento.

**Subtareas**
- [ ] Caracterización del dashboard
- [ ] Test RED de agregación
- [ ] Extracción y servicio

**Notas**: `app/Services/AnalyticsService.php:228` (`postStats`). Las métricas se congelan a los 30 días (`recent_post_days`, `config/social.php:158`): el panel lo dice.

### T-30 — Panel `Pages/Campaigns/Show.vue`: KPIs, tabla por red, gráfica y posts

- **Descripción**: Panel de campaña con KPIs (posts, vistas, alcance acumulado, interacciones, likes, comentarios, compartidos, guardados, engagement), tabla por red, serie diaria con `LineChart` y lista de posts. Requisito: CA-11.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,5h · real —
- **Tiempo IA (ejec.)**: est. 0,88h · real —
- **Supervisión**: est. 0,22h (≈25 % IA) · real —
- **Previsión IA**: 237k in / 26k out tok · ⚠️ verificar €
- **Dependencias**: T-26, T-29
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Campaigns/Show.vue`, `app/Http/Controllers/CampaignController.php`, `tests/Feature/Ui/CampaignShowPageTest.php`
- **Cubre (tests)**: E2E-07, M-05
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/CampaignShowPageTest.php` → verde (props del panel)
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [ ] Muestra «sin datos» donde no hay métrica.
- [ ] Rotula «alcance acumulado» y avisa del congelado a 30 días.
- [ ] Funciona a 360 px.

**Subtareas**
- [ ] Test RED
- [ ] Componente y gráfica

**Notas**: Patrón de gráficas: `resources/js/Pages/Dashboard` (`LineChart`).

### T-31 — E2E de campañas con axe y sin desbordamiento

- **Descripción**: Playwright de la gestión, el selector y el panel en desktop, 375 y 360 px. Requisito: CA-11..CA-13.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-30
- **Tipo**: test
- **Archivos**: `tests/e2e/campaigns.spec.ts`
- **Cubre (tests)**: E2E-06, E2E-07
- **Verificación**:
  - `npx playwright test tests/e2e/campaigns.spec.ts --project=desktop` → todos pasan
  - `npx playwright test tests/e2e/campaigns.spec.ts --project=mobile-360` → todos pasan, sin desbordamiento

**Criterios de aceptación**
- [ ] 0 violaciones `serious`/`critical` de axe.
- [ ] Sin desbordamiento a 375 y 360 px.
- [ ] Datos solo con marcas `e2e-`.

**Subtareas**
- [ ] Spec de gestión
- [ ] Spec de panel

**Notas**: Patrón: `tests/e2e/flows.spec.ts`.

### T-32 — Documentación y revisión de C-03

- **Descripción**: Documentar campañas, el congelado de métricas y el MCP, y revisar la regresión del dashboard.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-28, T-31
- **Tipo**: docs
- **Archivos**: `docs/modulos/analitica.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "campaña" docs/modulos/analitica.md docs/modulos/mcp.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [ ] `list_campaigns` y `campaign_id` documentados en el módulo MCP.
- [ ] Documentado el límite de 30 días.
- [ ] Dashboard sin regresión.

**Subtareas**
- [ ] Documentación
- [ ] Revisión

**Notas**: lectura: contrastar con CA-11..CA-13.

---

## Fase 5 — C-04a · Media desde URL

**Estado**: borrador · **Estimado**: 24,5h base (29,4h con margen) · **Real**: — · **Coste est.**: 1.470 € + tokens (⚠️ verificar) · **Tokens est.**: 992k

### T-33 — `UrlGuard`: guardia SSRF con DNS, IPs, redirecciones y puertos

- **Descripción**: Guardia contra SSRF (CWE-918): solo `http`/`https` en 80/443, sin credenciales, IDN, resolución A/AAAA con todas las IPs públicas, bloqueo explícito de rangos privados, reservados y link-local (IPv4, IPv6 e incrustadas), literales decimales u octales rechazados, y salto manual de hasta 5 redirecciones revalidadas, con la IP fijada. Requisito: CA-15.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/Media/UrlGuard.php`, `tests/Unit/UrlGuardTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/UrlGuardTest.php` → verde (tabla de IPs y redirecciones de CA-15)

**Criterios de aceptación**
- [ ] `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254` y `::1` se rechazan sin ninguna petición (CA-15).
- [ ] Una redirección a una IP interna se rechaza.
- [ ] La IP validada se fija con `CURLOPT_RESOLVE` contra el DNS rebinding.

**Subtareas**
- [ ] Test RED con la tabla de IPs
- [ ] Resolución y rangos
- [ ] Redirecciones e IP fijada

**Notas**: No hay ningún guardia SSRF en el repo (lo verificó el diseño); sin patrón previo. Revisión de seguridad en T-41.

### T-34 — Migración `source`/`source_ref`/`import_state` y configuración de la cola `media`

- **Descripción**: Columnas aditivas en `media_assets` (`source` `upload|url|drive`, `source_ref`, `import_state` JSON), progreso en `toPublicArray()` y claves `media.import_budget_seconds` (80) e `import_queue`; `media` en `QUEUE_NAMES`. Requisito: diseño B1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_add_source_to_media_assets.php`, `app/Models/MediaAsset.php`, `config/media.php`, `config/queue.php`, `tests/Feature/Media/MediaAssetSourceTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/MediaAssetSourceTest.php` → verde (columnas, valor por defecto `upload`, progreso en el array público)

**Criterios de aceptación**
- [ ] Los assets existentes quedan con `source = upload`.
- [ ] `toPublicArray()` expone `bytes` y `total` sin filtrar la URL sensible.
- [ ] `media` figura en las colas por defecto.

**Subtareas**
- [ ] Test RED
- [ ] Migración, modelo y configuración

**Notas**: `config/queue.php:35` (`names`). Migraciones solo aditivas.

### T-35 — `RemoteMediaImporter` y job `ImportRemoteMedia` por tramos con `Range`

- **Descripción**: Interfaz `RemoteSource` con `UrlSource` y job en la cola `media` (`ShouldBeUnique`, `ShouldBeEncrypted`, timeout 110) que descarga por pasadas de 80 s con `Range`/`If-Range` y punto de control en el `.part`, con tope de bytes, un único reinicio si el remoto cambia y, al final, `finalize()`. Requisito: CA-14, CA-16, CA-17; diseño B1.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 8,5h · real —
- **Tiempo IA (ejec.)**: est. 1,15h · real —
- **Supervisión**: est. 0,29h (≈25 % IA) · real —
- **Previsión IA**: 310k in / 34k out tok · ⚠️ verificar €
- **Dependencias**: T-33, T-34
- **Tipo**: backend
- **Archivos**: `app/Services/Media/RemoteSource.php`, `app/Services/Media/UrlSource.php`, `app/Services/Media/RemoteMediaImporter.php`, `app/Jobs/ImportRemoteMedia.php`, `app/Services/MediaUploadService.php`, `tests/Feature/Media/ImportRemoteMediaTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/ImportRemoteMediaTest.php` → verde (JPEG válido listo; tope y tipo → `invalido`; vídeo en varias pasadas; sin `Range` → error claro)
  - `php artisan test --compact tests/Feature/Media/MediaUploadTest.php` → verde (la subida directa no cambia)

**Criterios de aceptación**
- [ ] Una URL JPEG válida acaba en un asset `listo` (CA-14).
- [ ] Superar `max_bytes` o un tipo real distinto corta la descarga y deja el asset `invalido` (CA-16).
- [ ] Un vídeo grande se importa en varias pasadas, cada una bajo 120 s (CA-17); con `MEDIA_DISK=s3` falla con un error claro.

**Subtareas**
- [ ] Test RED con `Http::fake`
- [ ] Fuente y pasada
- [ ] Job, reencolado y `finalize()`

**Notas**: Patrón de pasadas con presupuesto: `app/Connectors/Google/YouTubePublisher.php:413` (`beginPass`) y `upload_budget_seconds` (80). `app/Services/MediaUploadService.php:119` (`finalize`): solo se fija `declared_size` antes de llamarlo.

### T-36 — Endpoint de importación por URL con `throttle`, límite por usuario y limpieza de `.part`

- **Descripción**: `MediaController::importUrl` con `throttle` y máximo de 3 importaciones vivas por usuario, estado sondeable y limpieza de `.part` huérfanos en `app:media-prune`. Requisito: CA-14, CA-15.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,08h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/MediaController.php`, `routes/web.php`, `app/Console/Commands/PruneMedia.php`, `tests/Feature/Media/ImportUrlEndpointTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/ImportUrlEndpointTest.php` → verde (alta, límite de 3, URL interna rechazada)
  - `php artisan test --compact tests/Feature/Media/MediaUploadTest.php` → verde (sin regresión)

**Criterios de aceptación**
- [ ] Una URL interna se rechaza con 422 y mensaje en español.
- [ ] La cuarta importación viva se rechaza.
- [ ] `app:media-prune` borra los `.part` abandonados.

**Subtareas**
- [ ] Caracterización de `MediaUploadTest`
- [ ] Test RED
- [ ] Endpoint y poda

**Notas**: Rutas de media en `routes/web.php:94-98`. `app/Console/Commands/PruneMedia.php`.

### T-37 — UI «Desde URL» en `MediaSection` con sondeo y errores

- **Descripción**: Campo «Desde URL» con progreso (sondeo del estado) y errores en español en `MediaSection.vue`. Requisito: CA-14.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
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
- [ ] Se ve el progreso hasta `listo` y el motivo si queda `invalido`.
- [ ] Accesible por teclado.
- [ ] Sin desbordamiento a 360 px.

**Subtareas**
- [ ] Test RED
- [ ] Componente y sondeo

**Notas**: Patrón: subida y sondeo en `resources/js/Components/Composer/MediaSection.vue`.

### T-38 — MCP `import_media_from_url`

- **Descripción**: Herramienta que llama al mismo servicio y devuelve el asset `pendiente`; `list_media` ya lista el estado. Requisito: CA-14, CA-15. Tarea transversal de paridad MCP.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-36
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/ImportMediaFromUrl.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpImportMediaTest.php` → verde (acepta URL pública, rechaza interna)

**Criterios de aceptación**
- [ ] La misma `UrlGuard` protege la herramienta.
- [ ] Devuelve el asset `pendiente`.
- [ ] Registrada en el servidor.

**Subtareas**
- [ ] Test RED
- [ ] Herramienta

**Notas**: `app/Mcp/Servers/SchedulerServer.php:42`; herramienta hermana `app/Mcp/Tools/ListMedia.php`.

### T-39 — E2E de importación por URL con axe

- **Descripción**: Playwright: el flujo «Desde URL» con una URL rechazada por `UrlGuard` (la importación real se prueba en Pest), a 375 y 360 px. Requisito: CA-15.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
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
- [ ] Se muestra el error en español para una IP interna.
- [ ] 0 violaciones `serious`/`critical` de axe.
- [ ] Sin desbordamiento a 375 y 360 px.

**Subtareas**
- [ ] Spec

**Notas**: Patrón: `tests/e2e/composer.spec.ts`.

### T-40 — Despliegue Hestia: cola `media` con su worker y cron

- **Descripción**: Cuarta línea de cron `ss-media` (`flock -n`, `--queue=media --stop-when-empty --max-time=280 --timeout=120 --tries=3`), `media` en `QUEUE_NAMES` y documentación del despliegue. Requisito: D5 (cola nueva con su worker).
- **Changelog**: 
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en VERDE del comportamiento actual, ejecutado antes de tocar el código; después se añade el RED del cambio.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,03h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-35
- **Tipo**: devops
- **Archivos**: `deploy/hestia/README.md`, `deploy/hestia/deploy.sh`, `docs/modulos/despliegue.md`, `docs/modulos/media.md`
- **Cubre (tests)**: —
- **Verificación**: `grep -n "ss-media" deploy/hestia/README.md docs/modulos/despliegue.md` → al menos una coincidencia en cada fichero

**Criterios de aceptación**
- [ ] El cron nuevo sigue el patrón de `ss-publish` y `ss-metrics`.
- [ ] `--timeout` < `retry_after` (180 s).
- [ ] Documentado cómo procesar la cola a mano.

**Subtareas**
- [ ] Línea de cron
- [ ] Documentación y solución de problemas

**Notas**: `deploy/hestia/README.md:84-87` (líneas `ss-publish` y `ss-metrics`); `docs/modulos/despliegue.md:112`.

### T-41 — Revisión de seguridad SSRF de la importación

- **Descripción**: Revisión específica: DNS rebinding, redirecciones, literales numéricos, IPv6 incrustado, límites de tamaño y token de Drive fuera de logs y de `media_assets`.
- **Changelog**: 
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: el propio criterio verificable (comando de Verificación) ejecutado y visto fallar o ausente antes de dar la tarea por hecha.
- **Tiempo humano**: est. 0,5h · real —
- **Tiempo IA (ejec.)**: est. 0,07h · real —
- **Supervisión**: est. 0,02h (≈25 % IA) · real —
- **Previsión IA**: 18k in / 2k out tok · ⚠️ verificar €
- **Dependencias**: T-33, T-36, T-38
- **Tipo**: docs
- **Archivos**: `tests/Unit/UrlGuardTest.php`, `tests/Feature/Media/ImportUrlEndpointTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/UrlGuardTest.php tests/Feature/Media/ImportUrlEndpointTest.php` → verde

**Criterios de aceptación**
- [ ] Sin gaps Critical/Important abiertos.
- [ ] Cada hallazgo tiene test.
- [ ] Sección de revisión anotada en la tarea.

**Subtareas**
- [ ] Revisar cada vector
- [ ] Anotar hallazgos

**Notas**: lectura: verificar que ninguna petición sale a una IP no validada, incluida cada redirección.

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
- **Archivos**: `docs/roadmap/2026-10-24-metricool-parity-2/tasks.md`, `docs/roadmap/2026-10-24-metricool-parity-2/test-plan.md`
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
