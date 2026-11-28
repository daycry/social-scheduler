# Revisión T-77 · intento 1 de 3 · revisión estática efectiva

**Veredicto:** aceptable. No encuentro defectos Critical ni Important confirmados contra el contrato aprobado. Hay un dato que falta (§B-1). Si ese dato resultara desfavorable, abriría un Important antes del cierre. Los demás hallazgos son Minor o sugerencias de política.

No he ejecutado nada. La evidencia de QA (PHP 4334, MySQL/SQLite 53, navegador 90) se toma como aportada y no como verificada por mí.

---

## A. Aceptación

Cada punto del contrato y el elemento del parche que lo cubre:

| Contrato | Estado | Evidencia en el parche |
|---|---|---|
| Borrador independiente, sin fecha ni estado | ✔ | `PostDuplicator.php:28` fuerza `schedule/publish_now=false` y `scheduled_at=null`. `plan()` devuelve `Draft`. |
| Mismos textos y media | ✔ | `dataFromPost` (allowlist) en `:28`. Tests en `DuplicatePostTest.php:53-85` y `:217-240`. |
| Sin métricas ni historial | ✔ | `fill()` crea un `PostTarget` nuevo solo con campos de contenido. Tests en `:68-78` y `:231-236`. |
| Sin aprobación, campaña, notas ni Studio | ✔ | `dataFromPost` no incluye `notes`, `campaign_id` ni `requires_approval`. `create()` se llama sin `sourceExport`, así que `commonLinks=[]` y la procedencia de destino es null. |
| Misma marca u otra propia | ✔ | `PostController.php:93` usa `Brand::ownedBy(...)`. Test en `:109-124`. |
| Colaborador no concede permiso | ✔ | La Policy `owns` y el `UserScope` producen un 404. Test en `:116-120`. |
| No asignar arbitrariamente | ✔ | `:32-43` exige una cuenta única en origen y en destino. Tests en `:149-176`. |
| Pinterest y TikTok | ✔ | `:37` y `:48-50`. Tests en `:178-215`. |
| Error de media común aborta sin copia parcial | ✔ | `:57-60` lanza ante cualquier clave que no sea `targets.N`. Test en `:242-252`. |
| UI: guardados y publicados, no compositor nuevo | ✔ | `Compose.vue` usa `v-if="post"`. |
| Bloqueo dirty, guardado y doble envío | ✔ | `DuplicatePost.vue:19,30,83`. |
| preserveState | ✔ | `:34-37`: conserva el estado ante un rechazo sobre el mismo id y remonta con otro id. |
| TS estricto | ✔ | Se incluye en `tsconfig.frontend.json`. |
| Documentación | ✔ | `duplicar.md`, la guía, el índice y los dos CHANGELOG. |

Sobre la doble validación por destino: `validate()` y después `create()` ejecutan `plan()` dos veces. Con borrador (`schedule=false`), los errores de `plan()` por destino son independientes entre sí. La única interacción cruzada es `$seen` (`PostPlanner.php:322-327`). Esa colisión no puede darse:

- en la misma marca, porque los pares cuenta+tipo de origen ya son únicos;
- entre marcas, porque se exige una sola cuenta de origen por red y los tipos siguen siendo distintos.

Por eso eliminar `targets.N` no hace aparecer errores nuevos en la segunda pasada, salvo una carrera real entre ambas llamadas. En ese caso `create()` lanza la excepción y el controlador la devuelve como error, sin copia parcial. Es un comportamiento correcto.

## B. Corrección

### B-1 · Dato faltante (Important condicional) — opciones ligadas a la cuenta distintas de Pinterest

**Ubicación:** `app/Services/Posts/PostDuplicator.php:37-51`

**Escenario:** entre marcas se copian íntegras las `options` del destino, salvo en Pinterest (se omite) y TikTok (se quita `privacy_level`). El ciclo TDD de Pinterest demostró que `optionErrors()` no comprobó que el tablero perteneciera a la cuenta destino. Si otra red tiene opciones con identificadores de la cuenta, esos identificadores viajarían a la cuenta de la otra marca sin que el validador lo detecte. Ejemplos posibles: lista de reproducción de YouTube, ubicación de Google Business, o ubicación o colaboradores de Instagram resueltos por cuenta.

**Impacto:** incumpliría «solo contenido admitido por cuentas destino». Además, se publicaría contra un recurso ajeno o fallaría al programar.

**Lo que falta saber:** la lista de claves de `options` por red y si `optionErrors($type, $account, $options)` valida cada identificador contra `$account->meta`.

**Corrección si existe alguna:** generalizar la regla de Pinterest con una lista explícita de claves ligadas a la cuenta (por ejemplo, una constante por `Network`) y aplicar `Arr::except` al cambiar de marca. Añadir un test por clave.

Si no existe ninguna opción de este tipo, no hay hallazgo.

### B-2 · Minor — el aviso atribuye mal la causa de la omisión

**Ubicación:** `PostDuplicator.php:66`

**Escenario:** en la misma marca, un destino publicado tiene texto propio que ya supera el límite vigente de `media_specs`, o media propia borrada. `plan()` devuelve `targets.N` y el destino se omite. El aviso dice «no hay una cuenta inequívoca compatible o sus opciones ya no se admiten». Pinterest entre marcas también se cuenta con ese mismo texto.

**Impacto:** el usuario pierde el texto o la media propios del destino y el aviso no le orienta. El contrato sí aprueba omitir esos destinos.

**Corrección:** usar un texto neutro, por ejemplo «sin cuenta compatible inequívoca o con contenido u opciones que ya no se admiten». Mejor aún: indicar la red de cada destino omitido.

### B-3 · Dato faltante (Minor) — `form.isDirty` tras guardar

**Ubicación:** `Compose.vue` (`:dirty="form.isDirty"`)

**Escenario:** si `useComposer` no reinicia los valores por defecto del formulario después de un `PUT` con `preserveState`, «Duplicar» quedaría deshabilitado tras guardar, hasta recargar la página. El E2E (`duplicate-post.spec.ts:62-71`) guarda la copia pero no comprueba que el botón vuelva a estar habilitado.

**Corrección:** añadir al E2E, después del guardado, `await expect(button).toBeEnabled()`. Probablemente ya funcione, porque `UnsavedGuard` depende de lo mismo, pero no está demostrado.

### Observaciones sin hallazgo

- Los índices de `$targets` y `$content` coinciden: `dataFromPost` usa `->values()` y `get()` devuelve índices 0..n-1.
- `Collection::where('network', enum)` compara instancias del mismo caso. Es correcto.
- La aprobación no se hereda. Que la copia nazca sin aprobación pendiente equivale a crear un post nuevo, porque `requires_approval` es una opción por post (`PostService.php:106`). Es política, no defecto. Sí falta confirmar si existe alguna obligación de aprobación por marca, en cuyo caso aplicaría lo mismo que a un post nuevo.

## C. Seguridad introducida

No hay hallazgos accionables.

- **Origen:** comprobación en dos capas. La Policy y `UserScope` actúan en el controlador (404). `Ownership::assertAll` actúa en el servicio (`:22,27`), con test directo en `:254-265`.
- **Destino:** se filtra con `ownedBy` antes de `authorize`. La cuenta destino se filtra en `:24-25` y el planner la vuelve a comprobar en `:297-299`.
- **Media:** el planner revalida la propiedad de la media común y de la media de cada destino. Un recurso ajeno aborta la operación.
- **Entrada:** `brand_id` se valida como `integer|min:1` (tests en `:267-274`). No hay asignación masiva: el servicio construye `$data` y no lo toma de la petición.
- **Política opcional, no defecto:** la ruta `posts/{post}/duplicate` (`routes/web.php:105`) no tiene `throttle`. Solo permite crear borradores propios, igual que el alta normal. Si `posts.store` tiene limitador, conviene igualarlo.

## D. Rendimiento

No hay hallazgos accionables.

- Por destino, `plan()` se ejecuta dos veces, con un `find` de cuenta y la validación de media en cada pasada. Para el tamaño habitual de una publicación (menos de 20 destinos) es despreciable.
- `$accounts` se precarga pero el planner vuelve a consultar las cuentas. Es una duplicación menor y aceptable.
- No se encolan trabajos (`publish_now=false`, verificado con `Queue::assertNothingPushed`).
- No se copian archivos físicos: solo filas pivote.

## E. Calidad del código

### E-1 · Minor — parámetro de salida por referencia

**Ubicación:** `PostDuplicator.php:19,21` y `PostController.php:97`

**Escenario:** `?string &$notice` obliga al llamador (incluido un futuro adaptador API o MCP) a conocer ese canal implícito. El nombre `$notice` aparece en el controlador sin haberse declarado antes.

**Corrección:** devolver un objeto de resultado, por ejemplo `DuplicateResult(Post $post, int $omitted)`, y que el texto lo componga el adaptador. Así el servicio deja de producir texto de presentación y se resuelve también parte de B-2.

### E-2 · Minor — acoplamiento al formato de las claves de error

**Ubicación:** `PostDuplicator.php:57-61`

**Escenario:** la regex `^targets\.(\d+)$` depende de la convención interna de `PostPlanner.php:296`. Si el planner pasara a usar `targets.N.media`, todos los errores de destino abortarían el duplicado. El fallo sería visible, no silencioso, pero rompería la funcionalidad.

**Corrección:** que el planner o `PostService` exponga `targetErrorIndex(string $key): ?int`, o una constante compartida, y cubrirlo con un test de contrato.

### E-3 · Minor — prefiltro redundante con el validador

**Ubicación:** `PostDuplicator.php:44`

**Detalle:** `content_type->network() !== $account->network` y `isOffered()` repiten `PostPlanner.php:307-317`. Son inocuos, pero reglas duplicadas tienden a divergir.

**Corrección:** dejar que esos casos lleguen al validador como `targets.N`, o comentar por qué se prefiltran.

### E-4 · Minor — claves duplicadas en Vue

**Ubicación:** `DuplicatePost.vue:74`

**Escenario:** dos campos con el mismo mensaje (por ejemplo, el mismo texto para dos archivos) generan `:key="error"` repetido. Vue muestra un aviso y puede reutilizar mal los nodos.

**Corrección:** iterar `Object.entries(form.errors)` y usar como clave el nombre del campo.

### E-5 · Minor — errores en el ámbito global de la página

**Ubicación:** `DuplicatePost.vue:31` y `PostController.php:99`

**Escenario:** `withErrors` sin error bag rellena `page.props.errors.media`. Si algún componente del compositor lee `page.props.errors` directamente, mostraría el error del duplicado en el campo de media del original. No consta en las fuentes si algún componente lo hace.

**Corrección:** usar `errorBag: 'duplicate'` en `form.post` y `withErrors($e->errors(), 'duplicate')`.

### E-6 · Sugerencia de accesibilidad (política)

**Ubicación:** `DuplicatePost.vue:45-46`

El motivo del bloqueo («Guarda los cambios…») no está asociado al botón deshabilitado. Se puede añadir `aria-describedby`.

### E-7 · Sugerencia (política)

**Ubicación:** `PostController.php:102`

El aviso de destinos omitidos llega dentro del flash `success`. Separarlo en un flash `warning` lo haría más visible.

### E-8 · Tests

- **Sin hallazgo:** el cambio en `YouTubeClientTest` refuerza el test en vez de debilitarlo. El formato exacto, la estabilidad y la variación con la clave detectan una implementación no opaca (por ejemplo, `dechex(id)`), mientras que el antiguo `not->toContain('77')` era intrínsecamente frágil.
- **Sin hallazgo:** `ltTargetObjects` solo ordena claves de objetos y mantiene `toBe` estricto en el orden de la lista y en los tipos.
- **Minor:** los tests no comprueban que las métricas del origen sigan intactas tras duplicar (`DuplicatePostTest.php:78`, `duplicate-post.spec.ts:72-84`), aunque la evidencia lo afirma. El riesgo es bajo porque nada borra. La corrección es añadir `count === 1` sobre el snapshot del destino de origen.
- **Ruido sin defecto:** el reformateo de `tsconfig.frontend.json` es de Prettier.

---

## Resumen de hallazgos

| ID | Severidad | Ubicación | Bloquea el cierre |
|---|---|---|---|
| B-1 | Important condicional (dato faltante) | `PostDuplicator.php:37-51` | Solo si existen opciones con identificadores de cuenta además de Pinterest |
| B-2 | Minor | `PostDuplicator.php:66` | No |
| B-3 | Minor (dato faltante y test) | `Compose.vue` / `duplicate-post.spec.ts:71` | No |
| E-1…E-5 | Minor | indicadas en cada punto | No |
| E-6, E-7, throttle | Política opcional | — | No |

Para cerrar sin Important abiertos falta solo la respuesta a B-1: la lista de claves de `options` que dependen de la cuenta y si `optionErrors` las valida contra la cuenta destino.