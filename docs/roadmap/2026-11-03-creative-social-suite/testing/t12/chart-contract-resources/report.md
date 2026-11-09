# T12 — configuración y recursos de gráficos aceptados internamente

Delta6archivos/189líneas, candidato16fuentes1788líneas, paqueteinmutable. Scope actual0 sinavisos/info/exclusiones; Cfalse/Dtruefull porawaitstream98/113; deltaC-Dfalse. A2/B2/D2 frescas leyeron tabla1 completa y preservan todos✓/n/a salvo reevaluación explícita de tres correcciones. El contrato, OpenAPI y fixture no cambian en este delta.

| Criterio T12 C1–C2 | Veredicto fusionado | Evidencia A1 salvo indicación |
|---|---|---|
| Alcance/snapshot íntegro | ✓ | tasks314;16/16SHA;scope0 |
| Tipochart/camposcerrados sin tabla/geometría ejecutable | ✓ | StudioDocument101;chart-spec46;docJS20 |
| Dimensiones160–8000/límites anteriores | ✓ | PHPDoc117;JSDoc150;JSdoc57/PHPCharts94 |
| Bindingfijo/identidadsegura/SHA/columnascompletas distintas | ✓ | PHPSpec17;JSSpec47;fixture2válidos18inválidos |
| DefaultsJS/PHP equivalentes | ✓ | PHPSpec35,66;JSSpec5,51;testsPHP62/JS8 |
| Sin getters/heredadas/sparse | ✓ | JSSpec12,24;JSspec35;getters0 |
| Opciones/serieúnica/forma de dominio/porcentajes | ✓ | PHPSpec42;JSSpec58;JS58/PHPCharts106,198 |
| Dominios de datos finitos opuestos compatibles conG2 | ✓ corregido B1 | StudioCharts114; PHPpuroacepta±MAX/±1e308;G2cuatrocasosfinitos |
| TextoUnicode válido/controles/límites | ✓ corregido A1 | JSSpec69;10surrogatesrechazados/5paresválidos |
| Fuentes seguras/tipos/hexcompleto | ✓ | PHPSpec74;JSSpec71;JS76 |
| RetornoPHPexacto/v2timing/captions | ✓ | PHPDoc85;PHPCharts94,245;JSdoc31 |
| Walkersfont/permisosreadyusuario-marca | ✓ | PHPFonts121;PHPDoc79;PHPCharts170/JS44 |
| Servicio común/validación semántica | ✓ corregido B1 | PHPDoc78;PHPCharts73;tests106,198 |
| Referencias scoped404/422/503/archivados | ✓ lectura/evidencia implementador | PHPCharts14,45,65;tests118,186;BnoejecutaLaravel |
|32refs/64M antesleer | ✓ | PHPCharts40,51,63;tests147,224 |
|50kprimitives/página200k/documento | ✓ | PHPCharts52;tests147,224 |
| Dedupref/selección/tablaslocales | ✓ | PHPCharts24,27,63;test147;DsinN+1porcaparepetida |
| Registrymarca/pin/bytes/SHA/lossless | ✓ | resources40,81,111;tests16,26,33 |
| Offlinecerrado/sinfallbackajeno | ✓ | resources53,130;test41 |
| Promesascompartidas/G5/palette | ✓ | resources128,142;test48 |
| Abortindividual/dispose/expulsión propia | ✓ corregido D1 | resources28,73,171;tests55,61 |
| Cierre de respuestas rechazadas antesgetReader | ✓ corregido D1 | resources121–132;10rechazosabort/cancel,disposecierra11ybytes0 |
| Presupuesto concurrente/LRUcompletadas | ✓ corregido D1 | resources68,131,156;tests67,86,106 |
| TDD/evidencia/caracterizaciones distinguidas | ✓ | PHP13/74+19/169,JS159/159,resources14;rawprevios |
| OpenAPIválido/cero rompedores detectados | ✓ | Lint0errores/3avisospreexistentes;diff0 |
| C3UI/render/export/AV/totalT12 | Pendiente declarado | Contrato39–51;tasks311–313;noaceptaciónintegrada |
| Constitución/cobertura/interop/ADR | No aplica | Ausente/no declarada/sinchangescommands-agents-hooks/sinADRrelevantes |

| # | Grado anterior | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | Surrogate aislado JSaceptado/PHPno | T12/C1 | Corregido | RED10fallos→15/15focal/174/174regresión; pares válidos intactos |
| B1 | Important | Amplitud infinita entre extremos finitos rechazada | T12/C1 | Corregido | DosREDguardado±MAX/±1e308; eliminar3líneas;16/81contrato+19/169datasets |
| D1 | Important | Respuestas pre-reader rechazadas quedan activas | T12/C2 | Corregido | RED14PASS3FAIL→18/18; abort/cancelantesevict, sinawaitindefinido |

A2 ejecutó62/62JScongelados,0fallos/skips/cancelaciones,exit0,174,4074ms;16SHAy5dependencias intactos. PHPpuroconReflection/exceptionmínima aceptó dos dominiosamplios yrechazó tresincompatibles+overflowreal. B2ejecutó57/57spec/resources,3dependenciasSHAiguales; PHPpuro7casos yG2cuatrocombinaciones conextremos/ticks/coordenadasfinitos. SinPHPKernel/autoload/DBenlentes.

D2 ejecutó18/18resources yprobeindependiente enmemoria:11requests/10rechazos→10abort/cancel,1reader33Mreservados;trasdispose11abort/cancel,0deadlines,recursos/bytes0. Lecturatardía no promueve ycancelque nuncaresuelve no bloquea. Sintransferir330MB,HTTPreal,RSSni52MBstress. Resto de dedup/G5/LRU preservado. Cero gaps Critical/Important/Minor nuevos en las treslentes.

Root ejecutó PHPaislado256M en SQLite/storage ficticios, sin.envreal:35/35tests/250assert,exit0,1.018ms. JUnit contrastado35casos/0failure/0error/0skip,SHAe6321bf424c90f5ce9cc5b94c90e321295855a67d0fb4d9edb097c33b64b532f;16fuentesdelcandidatointactas. Esa ejecución cubre16contrato+19datasets, no HTTP/editor/render. Resultadopropio en t12-chart-c1-root-verification/.

Se acepta únicamente C1 yregistryC2. C3escena/Fabric/UI/exportaciones/AV yT12completa siguen pendientes; 8/23. Builds anteriores son históricos al cambiar document/fontsJS; no invocar T10 integrado sobre BUILD6 como si fuese actual. Se reconstruirá con entorno vacío/guard tras integración. SinJira/Confluence habilitados, sincommits/push/PRnuevos; TDDn/a para registro administrativo.
