# Auditoría de unidad: estudio y parte social (2026-11-12)

Requisito del usuario: Social Scheduler tiene que ser **una sola aplicación**, no dos apps pegadas: el planificador social y el estudio creativo. Esta auditoría, de solo lectura, localiza qué rompe esa unidad. Sus hallazgos (U-xx) se incorporan al plan como tareas nuevas o como ampliaciones de tareas existentes.

**Lo que ya está bien.** El estudio usa `AuthenticatedLayout`, el menú de `navigation.js` y el selector de marca. La marca se conserva por sesión (`CurrentBrand.php:29-54`) y el editor tiene botón de volver. La ruptura no está en el shell, sino en cuatro sitios: el sistema visual, los nombres, los puentes de datos y los permisos.

## Hallazgos

| ID | Área | Evidencia | Qué rompe la unidad | Propuesta | Imp. | Esf. |
|---|---|---|---|---|---|---|
| U-01 | Navegación | `navigation.js:14-24` | «Estudio» es el primer módulo, delante de Analítica y Planificación | Situarlo después de Planificación, o dentro de ella como «Crear» | M | S |
| U-02 | Navegación | `navigation.js:21-23` | «Plantillas», «Identidad» y «Papelera de diseños» tienen `match: []` y nunca se marcan como activas | `isActive` según la query (`templates`, `tab`, `archived`) | M | S |
| U-03 | Navegación | `Brands/Show.vue:23`, `Connections.vue:60`, `navigation.js:119` | Canva no está en el menú; al abrirlo cae en «Cuenta» | Añadir la entrada «Canva» en el lateral | A | S |
| U-04 | Layout | `Studio/Index.vue:127-139`, `Editor.vue:678-681` | Son las únicas páginas sin `#header`: tienen un hero propio (`bg-[#ede8ff]`) | Usar `#header` con el mismo h1 | M | S |
| U-05 | Layout | `AuthenticatedLayout.vue:202`, `Editor.vue:679` | El editor se compensa con `-m-3` dentro de `max-w-7xl`, y su `max-w-[1500px]` no tiene efecto | Añadir la prop `fullBleed` al layout | M | S |
| U-06 | Marca | `StudioController.php:249`, `CurrentBrand.php:33-41` | `studio/designs/{design}/edit` no fija la marca: el selector puede mostrar otra | Alinear la marca de la sesión con la del diseño | M | S |
| U-07 | Diseño | 196 `violet-*` en el estudio frente a 64 `indigo` en la parte social; `app.css:16,88`; `Index.vue:205,227` | Dos colores de acento; `btn-primary bg-violet-700` sobrescribe el token | Token `accent` en Tailwind común; prohibir sobrescribir `btn-*` | A | M |
| U-08 | Diseño | `Editor.vue:769-772`, `Inspector.vue:95`, `PresentationDialog.vue:250`, `StudioDatasetsDialog.vue:437`, `TimelinePanel.vue:54` | Los 8 únicos bloques `<style>` de la app están en el estudio, con 5 estilos de botón propios | `.btn-tool` y `.btn-toggle` en `app.css`; quitar los `<style>` (salvo `.studio-stage`) | A | M |
| U-09 | Diseño | `AudioTrackPanel.vue:49`, `CaptionsPanel.vue:113`, `StudioChartsDialog.vue:127` | Etiquetas e inputs propios en lugar de `.field` | `.field` y `FormField` | M | S |
| U-10 | Diseño | `StudioDialog.vue:14` (7 usos), `Modal.vue` (6), `fixed inset-0` a mano en 4 ficheros | Tres patrones de diálogo | Un solo `BaseDialog` que parta de `StudioDialog` (`<dialog>` nativo) | A | M |
| U-11 | Diseño | `Index.vue:189,192`, `Editor.vue:697` | El estudio no usa `EmptyState`, `PrimaryButton`, el paginador ni el banner comunes | Usar los componentes comunes | M | S |
| U-12 | Diseño | 88 colores hex en `.vue`, el 52 % en el estudio | Colores fuera de los tokens | Tokens o clases para la UI; los colores por defecto del documento, a `Studio/document.js` | B | S |
| U-13 | Textos | `navigation.js:47`, `LibraryController.php:18` frente a `ExportDialog.vue:87`, `Canva/Index.vue:225` | «Biblioteca» significa dos cosas (plantillas de publicación / archivos) | «Plantillas de publicación»; reservar «Biblioteca» para los archivos | A | S |
| U-14 | Textos | `Editor.vue` («recursos», *assets*) frente a «Media» en el compositor; dos «Plantillas» y dos «Papelera»; `brand-kit` frente a «Identidad de marca» | Mismos conceptos con nombres distintos | Glosario único (`docs/knowledge/glossary.md`) y renombrado | M | S |
| U-15 | Flujo | `ExportDialog.vue:83-86` | Se puede pasar de diseño a publicación, pero el post no guarda su diseño ni su exportación de origen | `post_target_media.design_export_id` con FK; botón «Editar en el Estudio» | A | M |
| U-16 | Flujo | `Composer/MediaSection.vue:75-180`, `Library/Index.vue` | El compositor no puede elegir archivos del estudio ni abrir una imagen en el estudio | Selector «Desde la biblioteca» y «Abrir en el Estudio» | A | M |
| U-17 | Flujo | `CanvaJobs.php` frente a `StudioAssets.php:34` | Lo importado de Canva no aparece en «Recursos» del estudio | Enlazarlo como `StudioAsset` al importar | A | S |
| U-18 | Flujo | `Studio/api.js:22-40` frente a `MediaSection.vue` y `MediaUploader.vue` | Dos pipelines de subida | Un único `uploadMedia()` en `http.js` | M | M |
| U-19 | Kit | `Index.vue:206-228`, `Brands/Show.vue:21-24` | La identidad de marca solo existe dentro del estudio | Pestaña «Identidad» en la ficha de la marca | M | M |
| U-20 | IA | `AiAssistant.php:62` frente a `config/studio-ai.php` (`AiProviders`); `StudioService.php:220,230` | Dos pilas de IA con estado y cuota separados; la «Voz de la marca» no se usa en ninguna | Inyectar `guidelines` y `voice` en ambos prompts; estado y cuota de IA comunes | M | M |
| U-21 | Avisos | `StudioDesignActivity.php:21`, `Notifications/Index.vue:20,46-49` | Los avisos `studio_design_*` salen como «Aviso» genérico y sin enlace | Título por tipo y enlace genérico `n.url` | M | S |
| U-22 | Permisos | `BrandCollaborator.php:15` frente a `StudioCollab.php:24-28`; `StudioAssets.php:142` | Dos modelos de permisos; el colaborador de la marca no ve sus diseños; roles con claves en idiomas mezclados | Roles de marca con acceso al estudio; aprobación de diseños vía `approvals`; claves en un solo idioma | M | L |
| U-23 | API/MCP | `ListDesigns.php:15`, `ListMedia.php:16`, `creative-suite.openapi.yaml:19` | Tres estilos de paginación | Contrato de paginación por cursor único | M | M |
| U-24 | API/MCP | `Abilities.php:19-39`, `ListMedia.php:20`, `ImportMediaFromUrl` | Prefijos de abilities mezclados (`designs:`/`studio:`); los archivos no tienen ability propia | `media:read`/`media:write`; `studio:ai` pasa a `designs:ai` (migración de datos, sin alias) | B | S |
| U-25 | API/MCP | 80 rutas de diseño o marca frente a 10 de posts en `api/v1`; OpenAPI solo de la suite creativa; marca a veces en la query y a veces en la ruta | El estudio está más expuesto que el núcleo social | Un OpenAPI único; la marca siempre en la ruta | B | M |

## Cómo entra en el plan

| Grupo | Hallazgos | Destino en `tasks.md` |
|---|---|---|
| Puentes de datos | U-15, U-16, U-17, U-18 | Tarea nueva **T-101** (y U-18 amplía T-53) |
| Sistema de diseño único | U-07, U-08, U-09, U-10, U-11, U-12 | Tarea nueva **T-102**; amplía T-56, T-57, T-60 y T-62/63 |
| Glosario y renombrado | U-13, U-14 | Amplía **T-54** |
| Shell y navegación | U-01, U-02, U-03, U-04, U-05, U-06 | Tarea nueva **T-103**; amplía T-62/63 |
| Kit de marca e IA compartidos | U-19, U-20 | Tarea nueva **T-104** |
| Avisos comunes | U-21 | Amplía **T-23** y **T-58** |
| Permisos y abilities | U-22, U-24 | Tarea nueva **T-105**; amplía T-17 |
| Contratos de API/MCP | U-23, U-25 | Amplía **T-28/T-29** y **T-59** |

**Criterio transversal** (se añade a los criterios globales de `spec.md`): ninguna pantalla nueva o tocada puede introducir componentes, estilos, diálogos ni términos propios del estudio o de la parte social si existe uno común; la revisión adversarial lo trata como gap.
