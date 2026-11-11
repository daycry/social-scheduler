# T12 — preparación reutilizable G5 aceptada

Se acepta sólo G5, no UI, configuración pública, exportaciones ni T12 completa. API anterior y algoritmo geométrico conservados; prepareBarChart captura/proyecta una vez, createPreparedBarChartGeometry usa un handle propio opaco congelado y WeakMap. No reconoce objetos externos congelados como preparados.

Ocho RED focales genuinos anteriores a implementación (cada uno1test/1AssertionError, sin errores de importación), seguidos de8/8 y regresión78/78. A1 y B1 frescas ejecutaron la regresión Node cap512MiB con --expose-gc;0fallos/skips/cancelaciones. La tabla externa puede recolectarse con handle vivo, y el handle al liberarlo. B1 comparó3.200resultados/errores contra baseline:160fixtures×10variantes×dos caminos, sin diferencias. Root verificó hashes READY y snapshots antes de esta aceptación.

S=resources/js/Studio/bar-geometry.js; T=tests/frontend/studio-bar-prepared.test.mjs.

| Criterio G5 | Veredicto A1 | Evidencia |
|---|---|---|
| Captura/proyección una vez | ✓ | S6–10 |
| Handle propio opaco/frozen/WeakMap | ✓ | S5–15;T41–45 |
| Rechazo ajenos/falsos/clonados/serializados sin getters | ✓ | S13–14;T60–67 |
| Revisión/tipos/precisión y frozen externo | ✓ | S7;T70–78;binding96–131 |
| Geometría sin releer entradas | ✓ | S12–15;T46–54,114–122;getters0 |
| Equivalencia10variantes/negativos/null/ceros/proporciones | ✓ | T28–38 comparación profunda |
| Avisos/dominio/ticks/extremos/subnormales/errores | ✓ | T81–97 |
| Opciones cerradas/paleta propia | ✓ | S44–72;T55–57,100–106 |
| API anterior orden/errores/algoritmo | ✓ | S74–80;T106; normalizador/cuerpo byteidénticos |
| Derivados congelados sin persistir tabla/handle | ✓ | S8,158–160;T21–24,36 |
|1000×12/cuatro layouts sin leer tabla | ✓ | T109–123;12kbarras/layout |
| GC real sin retener tabla/handles | ✓ | T126–141;--expose-gc |
| TDD/verificación genuina | ✓ | red1–8;green8/78 |
| Alcance/estado honestos | ✓ | scope0;fuentesdeclaradas;T12abierta |
| G1–G4+B1–B3 anteriores | ✓ preservado | Algoritmo intacto/regresión78 |
| Constitución/cobertura/OpenAPI/interop | No aplica | Ausentes/no declarada/sin cambios en G5 |

B1: captura/handles, equivalencia/regresión e inmutabilidad/reutilización/GC correctos. Seis handles hostiles adicionales rechazados sin traps. C/D=false por selector sobre177líneas/2fuentes; scope0 sinavisos/info/exclusiones. Cero gaps Critical/Important/Minor en ambas lentes. No nuevas decisiones ADR relevantes.

Observación limitada con12kbarras/cuatro layouts: prepare26,907ms, layouts53,052ms, heapUsed36.839.536B y0lecturas externas. No es pico de memoria, benchmark52MB ni RSS/Job. A1 regresión1.035,2505ms. Sin PHP/BD/env/HTTP/Chromium/build/ONNX/nativos. La integración C1–C3 requiere sus propias revisiones y E2E/exportaciones; no se deduce un gráfico usable de esta pieza pura.
