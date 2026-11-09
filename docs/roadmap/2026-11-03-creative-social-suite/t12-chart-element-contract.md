# T12 — capa semántica de gráficos y recursos privados

Contrato previo de integración C1–C3. Conserva las32configuraciones/11familias del inventario; esta primera integración aplica a las diez barras/columnas ya geométricamente verificadas. T12 sigue abierta. Ni el contrato ni la preparaciónG5 acreditan controles, render o exportación.

## C1. Documento público y validación

Nuevo tipo `chart` con las propiedades comunes existentes y una propiedad propia `chart:{binding,options?,style?}`. Se rechazan campos desconocidos. Se guarda la referencia/configuración, nunca tabla, geometría, HTML, SVG, URL, archivo, callback, clase Fabric ni handle preparado. El documento mantiene2MiB,100páginas/500capas y profundidad8. Width/height del chart:160–8000; el área interior de trazado debe seguir siendo≥40px al integrar anotaciones. Opacidad/transformaciones de capa afectan al conjunto. Es una capa atómica; sus hijos gráficos no se desagrupan ni serializan como grupos corrientes.

`binding` conserva B1–B3: `{dataset:{id,revision,sha256},category_column,series_columns,precision?}`. Enteros positivos seguros≤9007199254740991; SHAhex minúsculo exactamente64; IDs1–64ASCII alfanumérico/guion/guionbajo sin caracteres finales adicionales. Series1–12, distintas entre sí y categoría. Precision safe por defecto, approximate sólo explícito. No se actualiza a latest automáticamente. Cambiar revisión es una acción completa con undo/redo y consentimiento en la UI.

`options` admite únicamente `kind`, `band_gap`, `series_gap`, `palette`, `domain_min`, `domain_max`, `tick_count`. Kind obligatorio si options existe; column por defecto si se omite options completo. Tipos/dominios/defaults son G1; width/height se calculan desde el trazado y opacity se aplica en la capa, sin fuentes duplicadas. Proporcionales sólo dominio0–100 y datos no negativos; simples/multicolor una serie. No se cambian datos para encajar el tipo.

`style` opcional, campos propios:

| Campo | Tipo, límite y default |
|---|---|
| title, subtitle, source, x_title, y_title | TextoUnicode≤256cadauno; vacío. Sin NUL/controles C0, salvo LF/TAB. |
| fontFamily | Misma familia segura que texto de documento, sin familia interna StudioFont_; Arial |
| font_id | IDpositivo seguro de fuente ready propia de marca; omitido si local, null no permitido |
| fontSize | Finito8–72;16 |
| fontWeight / fontStyle | normal o bold / normal o italic; normal |
| text_color / axis_color / grid_color / background | Hex3/4/6/8 completo; #111827/#64748b/#e2e8f0/#ffffff |
| legend / show_grid / show_axis / category_labels | Booleano; true |
| legend_position | bottom o right; bottom |
| value_labels | none, raw, percentage; none. Percentage sólo variantes proporcionales. |
| label_position | inside u outside; outside |
| category_label_limit | Entero1–80;24. Sólo acorta texto visual, no dato original. |

JS `normalizeChartSpec(value)` devuelve configuración propia normalizada/congelada, sin invocar getters ni aceptar arrays dispersos/propiedades heredadas. PHP `StudioChartSpec::normalize(mixed $value, string $path='chart'):array` valida y devuelve defaults equivalentes; StudioDocument conserva el documento exacto recibido tras validar. La fuente chart_style.font_id entra en todos los walkers y protecciones de borrado existentes, con los mismos permisos que texto.

## C2. Datos fijados, permisos y presupuesto

Antes de guardar/reabrir/renderizar, resolver con usuario+marca explícitos. Referencia ajena, dataset o revisión ausentes:404; SHA incoherente o configuración/columnas/tipos/dominio incompatibles:422; archivo corrupto/ausente:503. Históricos archivados continúan legibles; no habilita editar/descubrir datasets archivados desde el listado activo. Copiar dentro de marca conserva referencia; copiar a otra exige recursos de esa marca y no copia datos implícitamente.

Validación de guardado comprueba semántica de columnas/celdas contra revisión completa. Se deduplica por referencia y selección, nunca por etiqueta. Se resuelven primero metadatos scoped y presupuesto antes de leer tablas. Máximo32referencias únicas y64.000.000bytes canónicos únicos por documento/trabajo; cuenta una sola vez cada id/revisión/SHA. No conservar tablas PHP entre peticiones. Búsquedas/lecturas se agrupan o deduplican; ninguna lectura por capa repetida ni por fotograma. Máximo50.000primitivas estimadas por página y200.000por documento, contando capas ordinarias y para cada chart una cota `100+filas+2*filas*series`. Se rechaza exceso; no se omiten barras/series para pasar presupuesto.

Reutilizar lectura verificada StudioDatasets y autorización de revisión exacta. El nuevo servicio StudioCharts agrupa referencias del documento, comprueba presupuesto/SHA y valida selecciones una vez por revisión. La API/MCP de guardar usa este mismo servicio. No confiar en un cliente que diga que una tabla fue validada o que está congelada.

Browser: recurso JSON completo mediante export existente de revisión fija, con bytes/SHA verificados y parser lossless, sin response.json ni paginar52MB diez veces. Registry pertenece a un contexto de marca/editor/export; deduplica promesas de recurso y proyecciones G5. Fallos se expulsan sólo si corresponden a su propia entrada; cierre/cambio de marca invalida el contexto. Abort de un consumidor no cancela trabajo compartido de otro. La escena prepara una sola vez y no descarga, parsea ni proyecta por fotograma o al cambiar palette.

## C3. Escena compartida, UI y exportaciones

FabricGroup semántico chart conserva chart entero en serializer. Geometría, ejes/cero/rejilla, leyenda, títulos, categorías, valores y fuente se generan como objetos propios desde datos tipados. No cargar FabricJSON ajeno. Texto visual puede acortarse explícitamente manteniendo categorías/valores completos en tabla accesible/tooltips. Nulos siguen siendo huecos, cero y duplicados conservan identidad. Si etiquetas no caben se avisa o muestra una selección legible; no se cambian datos. No se anuncian filtros interactivos o nuevas familias todavía.

UI permite elegir dataset guardado/revisión/SHA, columnas y precisión explícita; preview/errores antes de insertar. Nunca vincular borrador sucio sin revisión. Cambios de configuración reconstruyen atómicamente el chart en su posición/selección, manteniendo transformaciones. Un epoch por operación evita que respuestas tardías reviertan la edición más nueva o reintroduzcan capas borradas. Guardado/reapertura/undo conservan configuración. El chart no se desagrupa; grupos ordinarios siguen funcionando.

Preview/editor/SVG/PNG/PDF usan la misma escena y registry. El render aislado recibe descriptores de archivos privados separados del documento; Node verifica raíz propia/ruta/tamaño/SHA antes de servirlos desde mapa de origen sintético cerrado. Nunca interpolar JSON de tabla en HTML/script. AV necesita contrato ampliado de snapshots/journal/limpieza/retry y propagación hasta project-render-entry; hasta implementarlo y verificarlo no se acredita export AV de charts ni se cierra C3. No se incrustan52MB en su manifiesto8MiB.

## Puertas

TDD genuino por criterios y caracterizaciones declaradas para casos ya cubiertos. Fixtures compartidas JS/PHP, campos hostiles/getters/sparse/trailingLF, defaults, límites, permisos entre marcas, revisión histórica/archivo/corrupción/SHA, texto numérico sin conversión, negativos/cero/null y presupuesto agregado. G5 conserva78pruebas previas. Después revisión A/B y C/D según selector; integración necesita E2E en375/768/1440, accesibilidad, guardado/reapertura/undo, fuente real y SVG/PNG/PDF con oráculos independientes. AV requiere su propia evidencia. No declarar10configuraciones usables sólo por tests puros de contrato.
