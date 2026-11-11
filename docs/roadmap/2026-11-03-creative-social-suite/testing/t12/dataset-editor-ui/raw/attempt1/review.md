# Revisión UI1 — T12/E5–E7

Diff completo de siete fuentes,1137líneas. A+B frescas, C=false/D=false. Fuente congelada y descriptores en manifest/source/descriptors. Build3 y77assets verificados. T12abierta8/23; no ejecución de navegador ni aprobación global.

## A1: tabla completa

D=StudioDatasetsDialog.vue; C=dataset-client.js; TD=studio-datasets-dialog.test.mjs; TC=studio-dataset-client.test.mjs. Rutas completas de cada uno en manifest.json.

| Criterio | Estado | Evidencia |
|---|---|---|
| E5 entradas Index/Editor y marca | ✓ | Index145/193; Editor558/620 |
| E5 búsqueda/paginación/revisiones/dimensiones/activos y archivados | ✓ | D52/370–376,C76,TC13,TD40 |
| E5 nueva/importCSVTSVJSON/nombre/header/límites | ✓ | D323/378–386,C42,TD34/165,TC47 |
| E5 historial paginado/revisión fija/archivo-restaurar | ✓ | D135/197/282/416–417,C27,TD107/175 |
| E5 exportseguro/fiel/typed | ✓ | D72–74/417,C35,TC58 |
| E6 tabla semántica/tipos/ventanas20×8 | ✓ | D184–186/400–405,TD223 |
| E6 contenido completo/estructura/conversión/undo | ✓ | D239–318/407–414,TD72/121/136/146/156 |
| E6 errores/estados/foco/controles | ✓ | D300–307/358–366/407/434,TD261; DOMpendiente |
| E6 contraste legible | ✗ | D403,tipo text9px slate400 activo;2,56blanco/2,34violet50<4,5 |
| E6 optimista409/borrador/recarga | ✓ | D146/161–180,TD86,TC36 |
| E6 cancelarlecturas/latewrites/contexto | ✓ | D36–50/159–160/190–212/334–349,TD40/62/95 |
| E6 aviso descarte+campospendientes | ✓ | D14–29/225–236/290–294/344–348,TD51/193/203/213/233/243 |
| E7 originalmultipart/límites/sintextarea gigante | ✓ | C42–48,TC47,D86–98 |
| E7 typedchunks/páginasfijas/SHA | ✓ | C50–73,TC22/36/69/79,D202–209; núcleo aprobado |
| E7 no tabla incrustada en documento | ✓ | Sólo import/ref/botón/componente en páginas; estado propioD |
| TDD | ✓ |28RAWRED/GREENleídos; TypeErrorstub aceptados, sinatribuir asserts posteriores; combinado14noextra |
| Verificación | ✓ |37/37Node, caracterizaciones/alcance declarados |
| Alcance | ✓ |Siete rutas en tasks314,scope0sinavisos/exclusiones |
| Descripciones | ✓ |Guía518–529,contratoE5–E7/testplan115–125; aceptaciónpendiente |
| Generados | ✓ |Build3exit0,77/77SHAintactos |
| Constitución/ADR/interop | n/a |Ausente/sincoincidencias/no superficiesgeneradas |
| QAreal3anchos/DOM/axe/HTTP | pendiente |Spec5escenarios presentes, ejecución posterior; no gapcircular |

A leyó todo el diff,28RAWRED/28GREEN y138artefactos; verificó7fuentes+3descriptores+2dependencias+77generados intactos. Cálculo estático con colores Tailwind instalados: slate400 #94a3b8 vswhite2.5640413905 yviolet50#f5f3ff2.3377625764. Noesobservaciónaxe/browser; pieSHAcorregidoya7.58 noimplicado.

## B1: corrección

| Criterio | Estado | Evidencia |
|---|---|---|
| E5 biblioteca/importación/historial | ✓ |Lectura completa y sonda historial tardío descartado PASS |
| E6 estado/carreras/guardado | ✗ |Sonda realSFC+Vue+core:dirtytrue tras guardar |
| E7 transporte tipado | ✓ |Blobtyped y páginaspinned; sinotrodefecto |

Sondas Node512M reproducen B1; también PASS metadata tardía tras nuevaselección y recuperación encabezado inválido.7SHA antes/después iguales. ADRsincoincidencias. Sin escrituras ni navegador.

## Fusión

| # | Grado | Gap | Tarea | Ubicación | Veredicto |
|---|---|---|---|---|---|
| A1 | Important | Etiquetas de tipo9px/slate400 sincontraste suficiente | T12/E6 | D403 | pendiente |
| B1 | Important | savedTable no reactivo; dirtycacheado no se invalida tras guardar sin renombrar | T12/E6 | D173/324 | pendiente |

B1resultado exacto: saveCalls1,persistedRevision2,tableCellB,dirtyAfterSuccessfulSavetrue,statusRevisión2guardada. Cerrar pide descartar datos persistidos, guardar sigue disponible y E2EesperandoSinCambiosPendientes no concluye. Root comprobó la referencia savedTable no reactiva y dependenciahistoryVersion sin cambiar en save. No rebates. Fix2 autorizado sólo D/TDconTDD para estado y estilo declarativo para contraste; preservar el resto de✓yE1–E4/API aprobados. Se revisará sólodelta/correcciones, no reabrir aprobados sin evidencia nueva.
