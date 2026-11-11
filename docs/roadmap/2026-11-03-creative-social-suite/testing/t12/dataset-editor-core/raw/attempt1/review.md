# T12 dataset editor core — revisión1

Fuentes congeladas2; diffcompleto588líneas. LentesA/B/C completas, D=false. Root único escritor del ledger. T12integral abierta.

## A1 — conformidad

Lectura completa núcleo329líneas/test249; reconstrucción diff exacta,2SHA y185artefactos verificados. Sondas Node propias historial30/31, transposición+undo y ensamblado rechazo/recuperación/lexemas PASS. Cero gaps de conformidad en su lente.

| Criterio | Veredicto | Evidencia fuente/test |
|---|---|---|
| E1 módulo puro/API | ✓ | S1/exportsyapi.md |
| E1 lexemas | ✓ | S5,40,88/T5,42,151 |
| E1 caps52M/8MiB UTF8 | ✓ | S46–59,285/T220,223,228 |
| E1 profundidad16/duplicados | ✓ | S14,29/T32,205 |
| E1 no código/fórmulas/URLs | ✓ | Parser/operaciones completos,T48 |
| E2 esquema/tipos/IDs/dimensiones | ✓ | S61–85,102–121/T14–30,188–191 |
| E2 Unicode/controles/codepoints | ✓ | S67–73/T19–25,194–203 |
| E2 canonical52M exacto/+1 | ✓ | S119–120/T237–248 |
| E2 nomutación | ✓ | S123–132/T180–185,194–201 |
| E2 tipos/conversión explícita | ✓ | S135–142,224–237/T51–59,109–116 |
| E3 chunks tipados | ✓ | S87–100,134/T42–49 |
| E3 edición por identidad | ✓ | S147–164/T61–71 |
| E3 estructura/copia | ✓ | S166–206/T73–96 |
| E3 reemplazo/vacíos | ✓ | S208–222/T98–107,211–214 |
| E3 preview/conversión atómica | ✓ | S224–237/T109–116 |
| E3 transpose/headers/IDs/99 | ✓ | S259–264/T132–141+sonda propia |
| E3 historial30/compartir | ✓ | S239–257/T118–129+sonda propia |
| E4 celdas/metadatos seguros | ✓ | S266–306/T145–149 |
| E4 revision/SHA/columnas/IDs/cursor | ✓ | S308–325/T151–172 |
| E4 rechazo atómico/recuperación | ✓ | S321–325/T167–171+sonda propia |
| Alcance | ✓ | tasks314,1437/scope0 |
| Verificación/TDD | ✓ | tasks1439;20RED/6primerPASS/26final |
| Integridad | ✓ | diff reconstruido/2SHA/185artefactos |
| Constitución/ADR | n/a | No constitución;knowledgefind0sincoincidencias |
| Interop/OpenAPI/%cobertura | n/a | No toca esas piezas;sin gateconfig |
| Descripción/límites | ✓ | Contrato/api/evidence/ledger,E5–E7pendientes |

## B1 — corrección

Lee diff completo y verifica2SHAantes/después. Probes independientes Node512M:1500checks de operaciones/roundtrip/historial; límites página6MiBexacto/+1, errores mutables de conversión, atomicidad y recuperación. No suite26repetida. E1✓, E2✗, E3✗, E4✓. No es seguridad general ni aprobaciónUI.

| # | Grado | Gap | Ubicación | Veredicto |
|---|---|---|---|---|
| B1 | Important | Huecos en values omitidos por forEach/map; tabla2cols values=['keep','lost'];delete values[1] aceptada y chunks produce ["keep",] JSONinválido. | S113 (validación)/98(serialización) | pendiente |
| B2 | Important | Columna externa Object.freeze con getterlabel conserva vínculo cambiante; snapshot e historial cambian al modificar closure, incluso a CRprohibido. | S125 | pendiente |

Root reprodujo ambos por Node512Minline: serializedlabelAfter/historyLabelAfter tras capturarBefore; sparse produjo ["keep",] y JSON.parse falló Unexpectedtoken]. No se rebatieron. Fuentes siguenintactas para C1 antesdecambios.

## C1 — seguridad

Cero hallazgos: RegExp.exec no ejecuta comandos (S40); fórmulas/HTML siguen texto (S87–102); JSONpropiedades definidas explícitamente y claves peligrosas rechazadas metadata (S31,279–280); númeroslexemas estrictos+WeakSet (S6–7,82–85); sin SQL/shell/FS/red/auth/secretos. ProbeNode512M PASS y2SHAiguales antes/después. Primerprobe falló por encoding deassertacento PowerShell, repetido conTypeError sinfuenteseditadas; noharnessRED deproducto. ReferenciasJS/taxonomía/supresión consultadas. Noauditoríaglobal.

## Fusión

0Critical/2Important/0Minor pendientesB1/B2. Rootreproduceambos,norebatidos. A✓yC✓se preservan paraintento2; sólodelta+correcciones se reevalúan, no reabriraprobadossinevidencianueva. CoreautorizadofixTDDenattempt2 privado; APIexportsin cambios,T12abierta.

