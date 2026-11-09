# T12 — geometría de barras y columnas

Contrato interno previo al siguiente subbloque. Utiliza la referencia y proyección B1–B3 ya aceptadas. No añade campos al documento público ni anuncia gráficos usables antes de integrar controles, render, reapertura y exportaciones. El [inventario completo](t12-chart-coverage.md) conserva las 32 configuraciones y 11 familias.

## G1. API y configuración cerrada

Módulo puro `resources/js/Studio/bar-geometry.js` y test `tests/frontend/studio-bar-geometry.test.mjs`. API: `normalizeBarChartOptions(value = {})` y `createBarChartGeometry(binding, snapshot, options = {})`. La segunda llama a la proyección B1–B3 existente, sin modificarla, para comprobar revisión, identidad, tipos y precisión antes de dibujar. No hay DOM, Fabric, HTTP, archivos, dependencias nuevas o callbacks del consumidor.

Opciones propias cerradas: `kind`, `width`, `height`, `band_gap`, `series_gap`, `palette`, `opacity`, `domain_min`, `domain_max`, `tick_count`. Rechazar campos desconocidos, getters sin invocarlos, propiedades heredadas, arrays dispersos y tipos ambiguos. Devolver datos propios congelados.

| Opción | Valores / predeterminado |
|---|---|
| kind | bar, column, bar-grouped, column-grouped, bar-stacked, column-stacked, bar-proportional, column-proportional, bar-multicolor, column-multicolor; column por defecto |
| width / height | Número finito entre 40 y 8000; 640 / 360 por defecto. Son dimensiones del área de trazado, sin títulos o leyenda. |
| band_gap / series_gap | Fracción finita entre 0 y 0,9; 0,2 / 0,1. Hueco entre categorías y entre series agrupadas. |
| palette | Array denso de 1 a 24 colores hexadecimales de 3, 4, 6 u 8 dígitos; paleta fija propia si se omite. Sin CSS/URLs. |
| opacity | Número finito entre 0 y 1; 1 por defecto. |
| domain_min / domain_max | null automático o Number finito. La escala debe contener cero y todos los extremos representados, sin recortar datos silenciosamente. |
| tick_count | Entero entre 2 y 21; 5 por defecto. Objetivo de graduaciones, deduplicando valores no representables distintos e incluyendo extremos y cero. |

Las variantes simples y multicolor exigen exactamente una serie. Agrupadas y apiladas admiten las 1–12 series seleccionadas por B1–B3. Multicolor asigna color por categoría; las demás por serie. No se ofrece una variante incompatible con los datos como si fuera equivalente.

## G2. Geometría y semántica

Bar es horizontal; column vertical. Se conserva el orden de filas y series. Cada categoría ocupa una banda igual, con medio band_gap a cada lado. Las agrupadas dividen su espacio entre series y aplican series_gap dentro de cada ranura. Apiladas y proporcionales comparten el espesor de la banda. Ninguna barra sale del área 0…width / 0…height y ningún tamaño es negativo.

Cada celda numérica produce una barra identificada por fila y columna, incluso si su tamaño es cero. Una celda null no produce barra ni se transforma en cero. Su hueco permanece reservado en las agrupadas. La línea de cero queda explícita en el resultado. En apiladas se acumulan positivos y negativos por separado, desde cero, sin cancelar series opuestas ni mezclar categorías.

Proporcionales sólo admiten valores no negativos. Comprobar también el signo del lexema cuando un negativo no nulo se haya redondeado a -0; un -0.0 auténtico sigue siendo cero permitido. Rechazar con fila/columna cualquier valor original no nulo que la proyección approximate haya redondeado a cero: sin coordenada representable no se calculan porcentajes ni se declara ZERO_TOTAL. Las demás variantes conservan approximate y sus avisos. Su dominio es 0…100; rechazar overrides distintos. El porcentaje es por categoría, sin cambiar el valor/raw originales. Calcular proporciones mediante escalado para que dos valores finitos grandes no produzcan Infinity al sumar. Una categoría con ceros numéricos y total cero conserva barras degeneradas y emite ZERO_TOTAL. Una categoría sin valores numéricos se distingue de la anterior; no inventar porcentajes.

En las demás variantes, el dominio automático incluye cero y todos los extremos, incluidos los acumulados. Sin valores o con todos los valores cero se elige un intervalo no degenerado conservando cualquier extremo explícito válido. Si ambos extremos explícitos coinciden o no contienen los datos, fallar con diagnóstico; no ampliarlos ni truncar valores silenciosamente. Rechazar sumas acumuladas no finitas con fila y columna identificadas. Interpolar coordenadas sin calcular una resta infinita de extremos finitos opuestos.

Las graduaciones son finitas, únicas, ordenadas e incluyen min, max y cero. El número solicitado es un objetivo: puede reducirse por precisión de Number o añadirse cero si no estaba en la malla. No fabricar números distintos donde sólo existen dos valores representables. No se promete precisión geométrica ilimitada; una barra no nula que se reduzca a tamaño cero por precisión de la escala genera aviso identificando fila/columna, sin cambiar el valor.

## G3. Resultado propio y trazable

Resultado congelado con `kind`, referencia `dataset`, `orientation`, `width`, `height`, `domain:{min,max}`, `baseline` en píxeles, `ticks:[{value,position}]`, categorías con row_id/tipo/etiqueta/centro/banda, series con column_id/etiqueta y `bars`.

Cada barra contiene clave determinista única por fila/columna, row_id, column_id, x, y, width, height, fill, opacity, value, raw, start, end y percentage (null fuera de proporcionales). start/end están en unidades del dominio; value/raw siguen siendo los valores originales. Una clave puede usar `row_id:column_id`, ya que los IDs de entrada no admiten dos puntos. Los avisos de precisión de B1–B3 se conservan junto a los propios del layout.

No devolver tablas completas ni objetos externos mutables. Las etiquetas largas siguen siendo datos; su ajuste visual, fuentes, títulos, leyendas, tooltips, filtros, controles y capas son trabajo posterior. No serializar este resultado derivado dentro del documento de 2 MiB: allí se persistirán referencias/configuración tipadas mediante un contrato público futuro.

## G4. Presupuestos y aceptación interna

Hasta 1000 categorías por 12 series y 12.000 barras. Recorrido acotado por la validación existente y las celdas seleccionadas; sin trabajo cuadrático, I/O o serializaciones completas por barra. Los fotogramas futuros no deben reconstruir una tabla de 52 MB repetidamente: la integración tendrá que cachear la revisión inmutable. Este módulo estático no implementa bar-race.

TDD genuino por criterio: esquema hostil sin getters, las diez variantes y orientaciones, identidad/orden/color, negativos y cero/null, agrupación con huecos, acumulación positiva/negativa, proporciones y total cero, extremos finitos grandes/overflow, dominio explícito, subnormales y ticks deduplicados, avisos por desaparición geométrica, inmutabilidad y fixture de 12.000 barras. Las caracterizaciones ya verdes se declaran. Después revisión A/B y C/D según selector y regresión pura. Las pruebas de este módulo no aceptan controles, SVG/PNG/PDF, animación, UI ni cierre de T12.

## G5. Preparación reutilizable para integrar editor y exportaciones

La siguiente ampliación conserva G1–G4 y su API existente. `prepareBarChart(binding, snapshot)` ejecuta la captura y proyección B1–B3 una sola vez y devuelve un handle propio opaco, congelado y reconocido por una WeakMap interna. `createPreparedBarChartGeometry(handle, options = {})` genera G2–G3 desde esa proyección propia y no vuelve a leer la tabla. Rechaza handles ajenos, falsificados, clonados o serializados; un objeto externo congelado no acredita preparación. No se persiste el handle ni la proyección dentro del documento.

`createBarChartGeometry(binding, snapshot, options)` sigue disponible y conserva validación, errores y geometría del contrato anterior. El camino preparado se comprueba equivalente para las diez variantes, negativos/null/ceros/proporciones, avisos, dominio/ticks y límites12.000barras. Modificar o poner getters en la tabla externa después de preparar no cambia resultados ni ejecuta esos getters. Cambiar opciones sólo recorre proyección seleccionada y geometría, nunca el JSON52MB de origen. No hay caché global de tablas, I/O, DOM o dependencia nueva. La caché y vida del editor/export serán responsabilidad del adaptador posterior. Esta ampliación habilita integración; no cierra configuración usable por sí sola.
