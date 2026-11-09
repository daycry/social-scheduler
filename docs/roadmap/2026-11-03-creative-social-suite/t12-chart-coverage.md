# T-12: familias de gráficos y cobertura exigida

2026-11-06. Inventario documental revalidado en fuentes oficiales; no observación autenticada del editor ni afirmación de acceso premium. Todas las filas siguen pendientes de gráfico usable. La numeración siguiente enumera configuraciones propias derivadas de las familias descritas, no pretende reproducir un menú exacto observado de32 entradas.

[Canva Charts](https://www.canva.com/en_gb/help/charts/) describe más de30 opciones, selección de columnas y personalización por familia. Los gráficos deben conservar datos y semántica, permitir edición y usar el mismo adaptador en preview/export. Un nombre en un selector o dibujar un PNG no acredita cobertura.

## Catálogo por familia

| IDs propios previstos | Configuraciones | Datos y controles que se comprobarán | Fuente primaria |
|---|---|---|---|
| bar,column | Barras simples horizontales y columnas verticales | Etiquetas y valores, negativos con cero visible; rango, pasos, títulos de ejes. | [Barras](https://www.canva.com/en_gb/help/bar-charts/) |
| bar-grouped,column-grouped | Agrupadas en ambas orientaciones | Varias series sin acumular; colores y leyenda. | [Barras](https://www.canva.com/en_gb/help/bar-charts/) |
| bar-stacked,column-stacked | Apiladas en ambas orientaciones | Segmentos y totales; acumulación positiva/negativa coherente. | [Barras](https://www.canva.com/en_gb/help/bar-charts/) |
| bar-proportional,column-proportional | Proporcionales en ambas orientaciones | Escala100%, suma explícita, cero y datos no aptos validados. | [Barras](https://www.canva.com/en_gb/help/bar-charts/) |
| bar-multicolor,column-multicolor | Multicolor en ambas orientaciones | Asignación de categoría a color; alineación/posición de etiquetas. | [Barras](https://www.canva.com/en_gb/help/bar-charts/) |
| line,multiline | Línea y multilínea | Categorías/tiempo con series; grosor, marcadores y huecos por valores ausentes. | [Líneas](https://www.canva.com/en_gb/help/line-charts/) |
| pie,donut | Circular y anillo | Etiqueta/valor; sectores proporcionales, colores, números/porcentajes. | [Circular/anillo](https://www.canva.com/en_gb/help/pie-and-donut-charts/) |
| scatter,bubble,dot | Dispersión, burbujas y puntos por categoría | X/Y, tamaño cuando aplique, color/etiquetas; forma/tamaño/transparencia de marcadores. | [Puntos](https://www.canva.com/en_gb/help/dot-charts/) |
| radar | Radar | Variables y series, ejes, valores y relleno; leyenda y rejilla. | [Radar](https://www.canva.com/en_gb/help/radar-charts/) |
| treemap,packed-circles | Jerarquía rectangular y círculos agrupados | Categorías anidadas, tamaño por valor, profundidad, colores y navegación de niveles. | [Jerarquía](https://www.canva.com/en_gb/help/hierarchy-charts/) |
| bar-race | Carrera de barras | Etiquetas, periodos y valores; barras visibles, velocidad, loop y fotograma reproducible. | [Carrera](https://www.canva.com/en_gb/help/bar-race-charts/) |
| histogram | Histograma | Campo numérico y bins; frecuencias y límites reproducibles, estilo/ejes. | [Histograma](https://www.canva.com/en_gb/help/histogram-chart/) |
| funnel | Embudo | Fases y magnitudes; colores, números/porcentajes y etiquetas. | [Embudo](https://www.canva.com/en_gb/help/funnel-chart/) |
| area,area-stacked,area-proportional,streamgraph | Área simple, apilada, proporcional y centrada | Una o varias series; relleno/opacidad, grosor y escala; stream centrado con bandas. | [Áreas](https://www.canva.com/en_gb/help/area-charts/) |
| radial-progress,progress-bar,progress-ring,progress-dial,pictogram | Progreso radial, barra, anillo, indicador y pictograma | Porcentaje/grosor/extremos/etiqueta según tipo; icono, total, rellenos y espaciado en pictograma. | [Infografía](https://www.canva.com/en_gb/help/infographic-charts/) |

Son32 configuraciones de11 familias documentales. Compartir algoritmo entre variantes no permite sustituir sus controles ni usar categorías incompatibles sin error visible.

## Datos, estilo e interacción compartidos

La [importación oficial](https://www.canva.com/en_gb/help/import-to-charts/) documenta CSV/TSV/XLSX,1000×100 y25MB, limpieza con preview, reemplazo exacto, completar vacíos y transponer. También fuentes conectadas y sincronización; algunas conexiones son de planes Business/Enterprise. Se conservan como requisitos abiertos y no se simulan con un import local. Cambiar la copia importada no modifica el origen externo.

[Personalización de barras](https://www.canva.com/en_gb/help/bar-charts/) y [puntos](https://www.canva.com/en_gb/help/dot-charts/) incluyen filtros interactivos; se documentan opciones por categorías o rango y hasta tres filtros. Son requisitos pendientes de planes de pago solicitados por el usuario. Captura estática no prueba interactividad; el preview/presentador debe probar controles y tooltips, y el export estático debe respetar el estado fijado sin fingir botones funcionales en PNG.

Títulos/subtítulos/fuentes del kit, paleta, leyendas, etiquetas y ejes se preservarán como configuración tipada. Los valores numéricos se convierten de forma explícita con preview: blanco/null no es cero; fechas y separadores decimales no se adivinan. Se distingue formato visual del valor original. Un gráfico queda fijado a dataset+revisión+SHA; actualizar desde otra revisión requiere acción explícita y entra en undo/redo. No se anuncian datos live mientras no exista conexión comprobada.

## Puertas de aceptación

Cada configuración necesita fixture de datos y salida geométrica verificable, pruebas de negativos/ceros/nulos cuando aplique, texto Unicode y contraste legible. Editor por marca con tabla accesible, selección de campos, cambio de tipo/estilo, guardado/reapertura/undo y export real PNG/SVG/PDF. Las configuraciones animadas también se medirán en fotogramas y MP4/GIF con reloj y revisión fijos. Se comprobarán límites1000×100, memoria y tiempos sin repetir trabajo por frame cuando los datos no cambien.

Pruebas de recurso/codec aisladas no cierran estas filas. Se añadirán evidencias por familia al implementar. No se reduce el alcance a seis tipos ni se deriva paridad completa de un catálogo.
