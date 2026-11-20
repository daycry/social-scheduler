Modelo confirmado por modelUsage: claude-opus-5-5.

Recibo original: metricool-t68-main-pages-r4-opus55.json

SHA256 del recibo: c76d7f0f142ef38eb60b9dd107df0855fda3c75fd3ee6ab8c5e9dfd72c20f292

# Revisión R4 · T-68 (incremento parcial)

Revisión estática del diff. No he ejecutado pruebas ni typecheck. Las puertas que indicas como pendientes siguen abiertas: la variante candidata de Playwright, la réplica de CI sin `.env` y GitHub Actions.

## Correcciones de R3

**I1 (`hidden`): cerrado.**
- `app/Http/Controllers/InboxController.php` normaliza `$hidden` una sola vez. Ese valor alimenta tanto la query como `filters.hidden`, así que ya no puede haber divergencia entre `'1'` y `true`.
- En `resources/js/Pages/Inbox/Index.vue`, `setFilter`:
  - conserva `hidden=false`;
  - descarta `unread` y `has_notes` cuando son `false`;
  - serializa los booleanos como `0`/`1`, compatible con la regla `boolean` de Laravel.
- `filterByCheckbox('hidden')` envía `null` al desmarcar, que es lo que describe el CHANGELOG.
- La paginación conserva `hidden=0` gracias a `withQueryString()`.
- Pest cubre `1`, `0` y la ausencia del filtro. La e2e cubre la carga con `hidden=1` y el desmarcado.

**I2 (contratos con datos y sin ellos): cerrado.**
- `InboxNotesPageTest` cubre la forma del comentario y la bandeja sin marca.
- `DashboardTest` cubre Network sin marca.
- `AllBrandsPageTest` cubre `values` y `previous` con `null`, y `series.values = []`.
- `seriesByNetwork` trata `[]` con `Array.isArray`.
- `MetricValues | []` refleja la serialización real de PHP.
- `InboxComment.account: string | null` es conservador pero coherente con el acceso null-safe del controlador.

**I3 (typecheck en CI): aceptado como fuera del diff.** Queda pendiente de que el job `typecheck` salga verde en GitHub. No lo considero verificado.

## Hallazgos nuevos o pendientes

**Critical:** ninguno.

**Important:** ninguno.

**Minor**

1. **`tests/Feature/Dashboard/AllBrandsPageTest.php`, caso de validación y marca sin redes.**
   - `summary.brands.0` y `summary.cards.1` dependen del orden: de marcas y del catálogo de métricas.
   - La aserción no identifica la marca recién creada, así que no prueba de forma explícita el caso «marca sin redes».
   - Propuesta: guardar `$empty = Brand::factory()...` y localizar su fila por `id`, comprobando `networks = 0`. Para la tarjeta, buscarla por `key` (p. ej. `views`) en lugar del índice 1.

2. **`resources/js/Pages/Inbox/Index.vue`, URL con `hidden=0`.**
   - La casilla aparece desmarcada, pero la lista excluye los comentarios ocultos y nada en la UI lo indica.
   - Para volver a verlos todos hay que marcar y desmarcar la casilla.
   - La UI nunca genera ese estado, así que solo llega por un enlace externo. No bloquea, pero conviene un indicador o documentarlo en `docs/modulos/inbox.md`.

3. **`router.reload` sin `preserveScroll`** en `Calendar/Index.vue`, `BatchBar.vue`, `useCalendarPublishNow.js` y `ReportsRecent.vue`.
   - Es correcto según el `doReload` de Inertia 3 que comprobaste.
   - Sin embargo, el comportamiento depende de un detalle interno de la librería. Un comentario breve en uno de los puntos, o en la guía de desarrollo, evitaría que alguien lo «restaure» o lo interprete como una regresión.

4. **`InboxNotesPageTest` y `CalendarTest`, uso de `hasAll`.** Solo verifican claves, no tipos ni nulabilidad. Es suficiente para detectar deriva de forma, pero no valida, por ejemplo, `hidden: bool` frente a `int`. Aceptable para este incremento.

5. **`Inbox/Index.vue`, `useForm({ text, account?: never })`.** Es un truco tipográfico para poder leer `errors.account`. Funciona, pero un tipo de errores explícito sería más claro. Es cosmético.

6. **`resources/js/types/routing.d.ts`.** La ampliación de `ComponentCustomProperties` usa `ziggyRoute`, que debe estar importado en la parte del fichero que el diff no muestra. Lo confirmará el typecheck en CI.

## Calidad general

- La deduplicación de series en `Partials/series.js` y el `BrandRef` compartido son correctos.
- El cambio de `when` a `href: null` en `navigation.js` es equivalente en comportamiento y elimina el `c.brand.id` no seguro.
- Las guardas `v-else-if="summary"` y `v-else-if="detail"` evitan errores de render con props `null`.
- `BaseDialog`: el refactor de radios y de `canFocus` mantiene la semántica. `handleNativeClose` sigue comparando con `currentTarget`.
- Que `ReportsRecent.pagination` sea ahora obligatoria es coherente con el test del usuario sin datos.
- No veo duplicación ni complejidad añadida relevante.

## Veredicto

**El incremento es apto para integrar tras QA**, con dos condiciones:
- Que Playwright de la variante candidata y el job `typecheck` de CI salgan verdes.
- Que se corrija el Minor 1 (aserción por `id` y `key`) antes o en el siguiente incremento, porque es la evidencia del caso «marca sin redes» que R3 pedía.

T-68 sigue en progreso.
