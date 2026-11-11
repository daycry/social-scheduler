# T12 C1/C2 — revisión1 A/B/D con tres correcciones

Paquete16fuentes/1683líneas leído completo por bloques; scope0 sin avisos/info/exclusiones, C=false/D=true (await stream en bucle). T12 abierta8/23. Snapshot inmutable source/baseline y fuentes/evidencias verificadas. UI/escena/export/AV C3 no implementados todavía.

| Criterio T12 C1–C2 | Veredicto fusionado | Evidencia A1 salvo indicación |
|---|---|---|
| Alcance/snapshot íntegro | ✓ | tasks314;16/16SHA;scope0 |
| Tipochart/camposcerrados sin tabla/geometría ejecutable | ✓ | StudioDocument101;chart-spec46;docJS20 |
| Dimensiones160–8000/límites anteriores | ✓ | PHPDoc117;JSDoc150;JSdoc57/PHPCharts94 |
| Bindingfijo/identidadsegura/SHA/columnascompletas distintas | ✓ | PHPSpec17;JSSpec47;fixture2válidos18inválidos |
| DefaultsJS/PHP equivalentes | ✓ | PHPSpec35,66;JSSpec5,51;testsPHP62/JS8 |
| Sin getters/heredadas/sparse | ✓ | JSSpec12,24;JSspec35;getters0 |
| Opciones/serieúnica/forma de dominio/porcentajes | ✓ | PHPSpec42;JSSpec58;JS58/PHPCharts106,198 |
| Dominios de datos finitos opuestos compatibles conG2 | ✗ nueva evidenciaB1 | StudioCharts117;serie[1],[-PHP_FLOAT_MAX,+PHP_FLOAT_MAX] |
| TextoUnicode válido/controles/límites | ✗ A1Minor | JSSpec69surrogateaisladoaceptado;PHPSpec72lo rechaza |
| Fuentes seguras/tipos/hexcompleto | ✓ | PHPSpec74;JSSpec71;JS76 |
| RetornoPHPexacto/v2timing/captions | ✓ | PHPDoc85;PHPCharts94,245;JSdoc31 |
| Walkersfont/permisosreadyusuario-marca | ✓ | PHPFonts121;PHPDoc79;PHPCharts170/JS44 |
| Servicio común/validación semántica | ✓ salvo B1 | PHPDoc78;PHPCharts73;tests106,198 |
| Referencias scoped404/422/503/archivados | ✓ lectura/evidencia implementador | PHPCharts14,45,65;tests118,186;BnoejecutaLaravel |
|32refs/64M antesleer | ✓ | PHPCharts40,51,63;tests147,224 |
|50kprimitives/página200k/documento | ✓ | PHPCharts52;tests147,224 |
| Dedupref/selección/tablaslocales | ✓ | PHPCharts24,27,63;test147;DsinN+1porcaparepetida |
| Registrymarca/pin/bytes/SHA/lossless | ✓ | resources40,81,111;tests16,26,33 |
| Offlinecerrado/sinfallbackajeno | ✓ | resources53,130;test41 |
| Promesascompartidas/G5/palette | ✓ | resources128,142;test48 |
| Abortindividual/dispose/expulsión propia | ✓ salvo ciclo pre-reader D1 | resources28,73,171;tests55,61 |
| Cierre de respuestas rechazadas antesgetReader | ✗ D1 | resources123;probe11requests/10rechazosquedanactivos |
| Presupuesto concurrente/LRUcompletadas | ✓ salvo limpieza D1 | resources68,131,156;tests67,86,106 |
| TDD/evidencia/caracterizaciones distinguidas | ✓ | PHP13/74+19/169,JS159/159,resources14;rawprevios |
| OpenAPIválido/cero rompedores detectados | ✓ | Lint0errores/3avisospreexistentes;diff0 |
| C3UI/render/export/AV/totalT12 | Pendiente declarado | Contrato39–51;tasks311–313;noaceptaciónintegrada |
| Constitución/cobertura/interop/ADR | No aplica | Ausente/no declarada/sinchangescommands-agents-hooks/sinADRrelevantes |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | JS acepta surrogate aislado que PHP rechaza | T12/C1 | Pendiente | chart-spec69;title=\\ud800→JSacepta/PHPmb_check_encodingrechaza |
| B1 | Important | PHP exige amplitud finita y rechaza extremos finitos válidos | T12/C1 | Pendiente | StudioCharts117;column[1]con±PHP_FLOAT_MAX→422,mientrasG5interpola yproduce ticksfinitos |
| D1 | Important | RechazoantesgetReader dejaHTTPbody/controlador activos sin deadline | T12/C2 | Pendiente | chart-resources123;1descargapendiente33M+10rechazos33M→330MBpotencialesfuerareserva |

A1 ejecutó43/43JS puros exit0/212,7869ms;openapi-lint/diff0 y16SHA. ProbeJSsurrogate yPHPclasesfrozen+excepciónmínima, sinKernel/autoload/DB, confirmódiferencia. Import directo inicial falló por dependencias omitidas del snapshot, corregido con fuentesbyteidénticas; no es REDdeproducto. Tabla Aoriginal preserva sus✓, fusión añade reabertura concreta de dominios B1 y limpieza D1.

B1 ejecutó43/43 mediante hooks en memoria y dependenciasG5/bindingfrozen, dataset/timelinepreviosSHAantes/después;0writes. PHPpuroReflection(selection) con excepciónValidationException produce «El intervalo del dominio debe ser finito». No ejecutaLaravel/DB y no acredita por sí respuestasHTTP; su lectura+pruebasimplementador se conservan con ese límite.

D1 probeNode512sin red ni writes: requests11,reads1,cancelled0,aborted0,resources1,bytes33000000. Trasdispose cancelled1,aborted1,rejectedSignalsStillActive10. No se transfirieron330MB ni se midióred/RSS/latencia. Resto de dedup/parseo/proyección/G5/LRU sin hallazgos de rendimiento.

Root confirma B1 por comparación conG2 y D1 por flujo finally→evict sin reader niabort. Tres fixes disjuntos encargados con REDantesdelcódigo y evidencias correction2/: JSnormalizadorUnicode, PHPdominioopuesto, registrycancelaciónpre-reader. No cambiar contratos para aceptar defectos ni snapshots1. Revisión2 reevalúa sólo deltas y gaps; conserva todo✓ anterior salvo evidencia nueva. Sin nativos/UI/export, sin Jira habilitado ni publicación externa.
