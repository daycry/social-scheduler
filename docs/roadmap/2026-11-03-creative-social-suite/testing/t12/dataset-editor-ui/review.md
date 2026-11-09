# Biblioteca y editor de datos: revisión de código aprobada

2026-11-06. Se aprueba la implementación E5–E7 tras dos intentos de revisión independiente. **La aceptación en navegador sigue pendiente.** No cierra T12 ni acredita gráficos, XLSX, conexiones, documentos, presentaciones o pizarras. El total sigue en8/23.

La biblioteca se abre desde el estudio o un diseño y conserva la marca. Permite crear/importar tablas, editar tipos y estructura, convertir con previsualización, deshacer/rehacer, guardar revisiones, consultar historial, archivar/restaurar y descargar. Usa el núcleo E1–E4 ya aprobado y las rutas existentes; no incrusta tablas grandes en el documento del diseño.

## Evidencia disponible

La candidata inicial pasó37 pruebas Node:31propias y6vecinas. Después del fix funcional pasó38:32propias y6vecinas, cero fallos/skips/cancelaciones. Son comprobaciones de cliente y SFC con Vue real; no se presentan como pruebas del navegador. Se conservan28 RED iniciales y sus GREEN y un RED nuevo de guardado. Las primeras pruebas que ya pasaban son caracterizaciones. No se cuentan aserciones posteriores al primer fallo como RED independientes.

Los138artefactos originales y20de corrección están íntegros. Originales PowerShellUTF16LE y vistasUTF8 estrictas conservan hashes. El cambio raíz de clase del SHA y la restauración LF de Index/Editor están separados: la candidata había convertido las dos páginas aCRLF pese describirlo como preservado; se restablecieron sus LF originales sin cambiar líneas lógicas ni retocar la evidencia histórica.

## Intento1: dos hallazgos Important

[Tabla completa del intento1](raw/attempt1/review.md). A+B leyeron las siete fuentes del diff1137líneas; C/D no se activaron. A comprobó28RED/GREEN,138artefactos y77generados. B usó el SFC, Vue y núcleo reales para reproducir el guardado.

| Hallazgo | Escenario | Resultado |
|---|---|---|
| A1: contraste de tipos | Texto/Número/Vacío activos a9px/slate400:2,56:1 blanco y2,34:1 violeta claro. | Corregido |
| B1: dirty tras guardar | Baseline no reactiva dejaba dirty cacheado true tras guardar una celda sin renombrar; pedía descartar trabajo ya persistido. | Corregido con RED/GREEN |

No hubo rebates ni deuda aceptada. Las aprobaciones restantes se preservaron.

## Intento2: delta49líneas, dos fuentes

D=StudioDatasetsDialog.vue; TD=studio-datasets-dialog.test.mjs; C=dataset-client.js; TC=studio-dataset-client.test.mjs. Las rutas completas y SHA están en manifest.json.

| Criterio | A2 | B2 | Evidencia nueva o preservada |
|---|---|---|---|
| E5 entradas y marca | ✓ | ✓ | Index145/193; Editor558/620 |
| E5 biblioteca/búsqueda/paginación | ✓ | ✓ | D52/370–376; C76; TC13; TD40 |
| E5 crear/importar/nombre/header/límites | ✓ | ✓ | D323/378–386; C42; TD34/165; TC47 |
| E5 historial/versiones/archivo | ✓ | ✓ | D135/197/282/416–417; C27; TD107/175 |
| E5 exportseguro/fiel/tipado | ✓ | ✓ | D72–74/417; C35; TC58 |
| E6 tabla/tipos/ventanas20×8 | ✓ | ✓ | D184–186/400–405; TD223 |
| E6 estructura/conversión/undo | ✓ | ✓ | D239–318/407–414; TD72/121/136/146/156 |
| E6 errores/foco/controles | ✓ | ✓ | D300–307/358–366/407/434; TD261; DOMpendiente |
| E6 contraste: A1 | ✓ corregido | sin defecto nuevo | D403 text-xs/slate600;7,5777blanco/6,9089violet50 |
| E6 optimista409/borrador/recarga | ✓ | ✓ | D146/161–180; TD86; TC36 |
| E6 cancelación/contexto/respuestas tardías | ✓ | ✓ | D36–50/159–160/190–212/334–349; TD40/62/95 |
| E6 descarte y campos pendientes | ✓ | ✓ | D14–29/225–236/290–294/344–348; TD51/193/203/213/233/243 |
| E6 guardado limpio: B1 | ✓ corregido | ✓ corregido | D173/324; TD281–300; sonda independiente loaded/new |
| E7 originalmultipart/límites | ✓ | ✓ | C42–48; TC47; D86–98 |
| E7 chunks/páginas fijas/SHA | ✓ | ✓ | C50–73; TC22/36/69/79; D202–209 |
| E7 no tabla en documento | ✓ | ✓ | Integraciones de páginas y estado propio intactos |
| TDD y verificación | ✓ | ✓ | REDtruevsfalse→GREEN;38/38actuales, sin repetir por impresión |
| Alcance/descripciones | ✓ | ✓ | Scope0; contrato/guía/testplan actuales |
| Generados | ✓ | sin cambio fuera del delta | BUILD4exit0,77/77assets y10/10fuentes comprobados |
| Constitución/ADR/interop/OpenAPI | n/a | n/a | Sin esas superficies ni principios escritos adicionales |
| QA real a375/768/1440 | pendiente | pendiente | Preparación completa, runtime=null, cero recorridos |

El fix de estado invalida historyVersion al actualizar la baseline tras éxito. El nuevo test lee dirty antes del guardado, conserva nombre y confirma limpio después; undo vuelve a sucio, redo a limpio y cerrar no pide descartar. La sonda independiente B2 confirma tablas cargadas y nuevas, revisión3/1 y una escritura, y una nueva edición vuelve a pedir confirmación. El snapshot persistido conserva -0.0 aunque el borrador cambie a B.

El ajuste de contraste es declarativo (TDD n/a): cálculos estáticos con colores Tailwind, no una aceptación axe renderizada. Una primera calculadora auxiliar falló al expandir #fff; se conserva como error de harness, nunca como RED de producto.

## Build y límites

BUILD4 es el vigente: exit0,77artefactos,10SHAfuente iguales antes/después. JobWindows3GiB COMMIT agregado, killOnClose/noBreakaway, pico1.191.055.360bytes; no RSS. Entorno permitido y envDir vacío, sin `.env` real. Los builds1–3 son históricos; no se reutilizan sus hashes para la nueva fuente.

Cero gaps de código pendientes tras A2/B2. E1–E4 y API conservan sus aceptaciones. Cinco escenarios E2E27 completos están preparados para tres anchuras, con observadores de JSON/CSV independientes, descargas HTTP reales, conflictos, historial, respuestas retenidas de requests reales, teclado/foco yaxe sin exclusiones.25probes puros y sintaxis no son recorridos del producto. Sólo JSONreal, informe, capturas inspeccionadas y SHA finales podrán aceptar la interfaz.
