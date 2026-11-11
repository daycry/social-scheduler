# T10 — revisión2 A2/B2, B1 corregido

Dos lentes frescas sobre delta11archivos/553líneas lógicas y unión37fuentes. Scope0 sin avisos/exclusiones; C/D=false para delta. Tabla1 completa preservada en attempt1/verdict.md, D1 sin hallazgos conservado. Q=storage/framework/testing/t10-final-qa1/, S=tests/e2e/studio-imaging.spec.ts.

| Criterio T10 | Veredicto A2 | Evidencia |
|---|---|---|
| Alcance preparación/tarea abierta | ✓ conservado | tasks137–142 |
| Integridad y diff completo | ✓ | SHA37/37;11/11secciones baseline/source normalizandoCR |
|30aserciones originales | ✓ conservado | Tabla1; Ssin delta |
| Fuente upload/preview/búsqueda/archivo/restauración/kit/reapertura | ✓ preparación conservada | S95–134 |
| WOFF2 status/MIME/firma/tamaño/SHA | ✓ preparación conservada | S19–37,145–147; Qpng-observer81–84 |
| Oráculo independiente sin contaminación | ✓ preparación conservada | S46–55,139–144; Qpng-observer63–65 |
| Inferencia real prevista/original/alpha/sujeto | ✓ preparación conservada | S169–207; Qpng-observer87 |
| Elipse/sepia/crop por píxeles independientes | ✓ preparación conservada | Qpng-observer33–53 |
| Decoder y controles negativos | ✓ conservado |26/26probes anteriores, sin delta |
| Seis casos/tres anchos/cero retries/failfast | ✓ preparación conservada | Qconfig10–20; Qrecount10–17,26 |
| Seis axe sin exclusiones | ✓ preparación conservada | S39–43,117,218; Qrecount24–28 |
| Errores visibles/DELETE409 exacto afirmado | ✓ preparación conservada | S17–36,150–155 |
| PHPCLI+servidor512M/post192/upload64 | ✓ preparación conservada | Qphp-cli-profile4–9; Qserve4–6; VM5/5 anterior |
| Job7/preflight11/floor4GiB/100ms/660s/worker6 | ✓ preparación conservada | Qresource-guard3,14–28; Qrun60–71 |
| Aprobación/manifiesto antes ejecución/UUID | ✓ preparación conservada | Qrun50–69; Qcorrection2/HANDOFF9 |
| Aborto prevalece, incluso recibo vacío | ✓ corregido | Qpostprocess16–17,30–35; regresiones42,52 |
| Exit no0/resource.failure prevalecen | ✓ | Qpostprocess32–35; regresiones48,50 |
| Motivo explícito/gate-recount byteidénticos subordinados | ✓ | Qpostprocess20–21,36–46; regresiones37–39 |
| PASS válido conservado/aborto sin recount no inventa casos | ✓ | Regresiones44,46; Qpostprocess35,39 |
| RED/GREEN auténtico | ✓ | Antes6tests/5fallos/0errores; después6/6 |
| Aceptación integrada final | Pendiente/no verificable | No E2E/nativos durante revisión |
| Constitución/cobertura/interop/OpenAPI | No aplica conservado | Sin cambios correspondientes |
| ADR relevantes | ✓ conservado | Intento1 exit0,total0; sin cambio de decisión |

| Criterio B2 | Veredicto | Evidencia |
|---|---|---|
| Integridad/baseline | ✓ |37SHA y delta completo |
| Oráculos/asserções/DELETE409/seis casos/axe | ✓ conservado | Tabla1, sin cambios |
| PHP512M/recursos7/11/4/660 | ✓ conservado | Productor congelado contrastado |
| B1 aborto prevalece sobrePASS | ✓ corregido | Qpostprocess16,30–40; main real |
| Exit/resourcefailure prevalecen | ✓ | Qpostprocess32–35; regresiones |
| PASS válido conservado | ✓ | Regresión43–44 |
| Gate/recount intactos subordinados | ✓ | Regresión38–39, comparación literal |
| Ejecución/cierre integrado | Pendiente | No autorizado durante revisión |

| # | Grado anterior | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1=A1 | Important | InformePASS tras aborto invalidante | T10 | Corregido | Precedencia abort_present/exit_code/resource.failure, NO-VERDE con motivo; histórico intacto |

A2 y B2 ejecutaron main real congelado con seis regresiones, sin cola que escribe recibos: baseline6tests/5fallos/0errores; corregido6/6. B2 añadió seis probes de recibos null/[]/{}/completo, ausencia de recount y rechazo prematuro: correctos. Ambos37SHA/11delta intactos; sin escrituras/nativos/UUID. Cero gaps Critical/Important/Minor nuevos. Root6/6 independiente registrado. T10 sigue abierta; revisión limpia habilita preparar manifiesto actual y una única ejecución separada, no acredita PASS integrado.
