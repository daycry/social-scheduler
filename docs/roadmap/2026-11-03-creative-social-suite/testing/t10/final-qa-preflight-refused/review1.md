# T10 — revisión A1/B1/D1 de preparación integrada

Lentes frescas sobre 27 entradas/922 líneas congeladas; scope0 sin avisos/exclusiones, C=false y D=true. No se ejecutó la aplicación integrada. T10 abierta, 8/23 cerradas.

S = source/tests/e2e/studio-imaging.spec.ts; Q = source/storage/framework/testing/t10-final-qa1/, dentro de attempt1.

| Criterio T10 | Veredicto A1 | Evidencia |
|---|---|---|
| Alcance preparación y tarea abierta | ✓ | tasks137–142; Qpreparation2–5, README3 |
| Integridad y correspondencia del diff completo | ✓ | 27SHA; diff reconstruido desde source/baseline |
| Preservar30aserciones originales | ✓ | Cero ausentes; una usa combobox con misma aserción |
| Fuente real upload/preview/búsqueda/archivo/restauración/kit/reapertura | ✓ preparación | S95–134 |
| WOFF2 status/MIME/firma/tamaño/SHA | ✓ preparación | S19–37,145–147; Qpng-observer81–84 |
| Oráculo tipográfico independiente sin contaminar exportación | ✓ preparación | Export antes de referencia S139–144; TTF y eliminaciónFontFace S46–55; IoU/margen Qpng-observer63–65 |
| Inferencia real prevista/original/alpha/sujeto | ✓ preparación | S169–207; Qpng-observer87; no mockONNX |
| Elipse/sepia/crop por píxeles independientes | ✓ preparación | Qpng-observer33–53; controles negativos |
| DecoderPNG y controles negativos | ✓ | 26/26probes en memoria; CRC/filtros0–4/RGB-RGBA/invalidPNG |
| Seis casos/tres anchos/cero retries/failfast | ✓ preparación | Qconfig10–20; Qrecount10–17,26; colección6 |
| Seis axe sin reglas/incomplete excluidos | ✓ preparación | S39–43,117,218; Qrecount24–28 |
| Errores app/HTTP visibles/DELETE409 exacto afirmado | ✓ preparación | S17–36,150–155 |
| PHPCLI+servidor512M/post192/upload64 | ✓ preparación | Qphp-cli-profile4–9; Qserve4–6; VM5/5 |
| Job7/preflight11/floor4GiB/100ms/660s; worker6 intacto | ✓ preparación | Qresource-guard3,14–28; Qrun60–71; prior-focal-integrity |
| Aprobación/manifiesto antes ejecución/UUID | ✓ preparación | Qrun50–69; execution_authorized=false |
| Aborto prevalece sobre PASS anterior | ✗ | Qpostprocess16–17,30–34 |
| Aceptación integrada final | Pendiente/no verificable | Cero casos ejecutados, sin nativos |
| Constitución/cobertura/interop/OpenAPI | No aplica | Ausente/no declarada/no cambios correspondientes |
| ADR aceptados relevantes | ✓ | knowledge-find --no-index --json exit0 total0 |

| Criterio B1 | Veredicto | Evidencia |
|---|---|---|
| Integridad/baseline | ✓ | SHA27/27 y baseline correcto |
| Oráculos fuente/fallback/PNG/elipse/crop/sepia | ✓ estático/puro | Familia independiente sin override; probes26/26 |
| Aserciones originales/DELETE409/seis casos/axe | ✓ estático | Comparación completa source/baseline/spec/config/recount |
| PHP512M y recursos7/11/4/660 | ✓ estático/puro | VM5/5 y fronteras en26probes |
| Veredicto tras aborto | ✗ | main real con E/S memoria produce PASS junto json_gate_valid=false |
| Ejecución y cierre real | Pendiente | No autorizados en revisión |

| Criterio D1 | Veredicto | Evidencia |
|---|---|---|
| E/S síncrona seleccionada | ✓ sin hallazgo | Config6 y probePHP1, lecturas pequeñas fuera de recorrido |
| Oráculos por volumen | ✓ sin hallazgo | PNG≤16Mpixels, muestreo512²/25traslaciones/seis resultados |
| Monitor de recursos | ✓ sin hallazgo | Hilo100ms y registro incremental,≤6600muestras/deadline |
| Informe | ✓ sin hallazgo | Secuencia acotada6casos/una generaciónPDF |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1=A1 | Important | Informe puede declarar PASS tras aborto invalidante | T10 | Pendiente al cerrar intento1 | postprocess.py30; watchdog tras recountPASS y antes run.py95 guard.finish; abort.json_gate_valid=false ignorado |

B reprodujo main real con E/S sólo en memoria: verdict=PASS junto abort.reason=physical_floor_below_4GiB y json_gate_valid=false. A y root confirman por lectura las ramas16–17 y30–34. Un solo defecto deduplicado. Ambos verificaron27SHA y probes26/26+VM5/5 sin archivos/nativos; D verificó27SHA y leyó diff completo. Sin Chromium/ONNX/PHP/servidor/PDF. No hay PASS integrado ni cierre de T10. No se ejecuta mientras quede Important. Revisión2 conserva todos los ✓ y reevalúa sólo corrección y nuevo delta salvo evidencia nueva.
