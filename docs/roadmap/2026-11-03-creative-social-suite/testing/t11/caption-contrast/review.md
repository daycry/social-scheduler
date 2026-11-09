# T-07/T-11: legibilidad de subtítulos, QA2-V01

2026-11-05. **A3/B3 sin gaps; fix nativo6/6; QA3 pendiente.** T7/T11 abiertas, total6/23. QA2 conserva72/72 y42/42 checks correctos junto a aceptación visual NO: sus artefactos no se reescriben.

## Causa probada y cambio

En Arial22normal blanco/contorno negro2 sobre negro, Fabric pintaba primero relleno y después contorno. La prueba real sobre el mismo canvas/objeto mide0 píxeles blancos frente a1007 del controlstroke0. Cambiar solamente paintFirst a stroke recupera1006; energía24,39→1545,67 frente al control1545,53. Esto aísla el orden de pintura de CSS, fuente y márgenes.

El fix añade `paintFirst:'stroke'` únicamente a objetos temporales de subtítulos compartidos por preview y exportaciones. No modifica styleJSON, tamaño admitido, defaults globales de Fabric, codec/importación ni datos del proyecto. RED permanente real antes de editar producto, cinco vecinos ya correctos; después **6/6 Chromium**, sin skips, documento intacto, preview/PNG/SVGpaint-order comprobados. Job3GiBCOMMIT, pico1.011.265.536B; procesos terminales.

Tres fuentes congeladas: adaptador de subtítulos, browser test y E2Ecaptions. Bases exactas tomadas de Freeze3 anterior;76 generados del nuevo build. `storage/framework/testing/t11-caption-contrast/final-freeze/manifest.json` y tres diffs contienen identidad y cambios. TypeScript, sintaxis y diff-check correctos. Build UI y ambos bundles correcto una sola vez.

## Verificación pública y errores de fixture preservados

Se amplían E2E23/24 sin quitar pruebas de fuente privada, selección o revisión: Arial22normal sin emoji, controlstroke0 positivo ycontorno2; fondo negro, preview y PNG/MP4/GIF, ventana y revisión fijada. El caso UI desktop **pasa9,6s** con texto blanco medido.

El primer focal render falla por fixture que cerraba un timeline ya abierto: espera de cue-row agotada. Se conserva como fallo, sin llamarlo RED de producto. Se corrige leyendo aria-pressed, sin cambiar producto. El segundo focal ejecuta y mide todos los nuevos archivos: PNGcontrol/contorno1000 blancos con comparación estricta>200;MP4control998/contorno999;GIF y energía comparables, ventana/revisión/documento correctos. Falla únicamente una expectativa absoluta control>1000 que se había calibrado con>=200.

Un recálculo independiente del PNG demuestra siete píxeles exactamente200: con el comparador coherente>=200 se obtienen **1007/1007,ratio1,0**. Se calibra la métrica con199/200/201 yrechazo de canales púrpura; no se reduce el umbral absoluto>1000 ni la razón50%. Los dos focales fallidos y sus JSON permanecen íntegros. **No se declara PASS del nuevo renderE2E propio**: la próxima QA independiente ejecutará el caso completo con el comparador corregido. No se repite un tercer export pesado para corregir un comparador de test cuando no cambió producto.

## Puerta de revisión

La tabla completa A2/B2/C2 y D1 del [bloque previo](../captions/review.md) se transmitió a A3/B3, que la devolvieron íntegra sin reabrir ✓, salvo legibilidad con evidencia nueva de QA2. Solo tres fuentes cambian;36 previas están intactas. El único cambio productivo es el orden de pintura. Selector C/D sobre delta3: ambosfalse; C2/D1 se preservan. El [test-plan](../../../test-plan.md) y [contrato](../../../t11-captions-contract.md) incluyen el criterio sin limitar documentos válidos.

| Criterio nuevo QA2-V01 | A3/B3 | Evidencia |
|---|---|---|
| Contorno detrás del relleno en objetos temporales | ✓ | captions-renderer.js:27; ningún default global de Fabric cambia. |
| Estilo/tamaño/documento intactos | ✓ | Browser test:66–73 y pruebas de objeto, cache y SVG. |
| RED real anterior al fix y GREEN | ✓ | Nuevo caso falla, cinco vecinos correctos; después6/6 sin omitir. |
| Preview/PNG/SVG y control sin emoji | ✓ | Arial22normal/white/black2 sobre negro; control positivo ypaint-orderSVG. |
| Cobertura/energía y calibración reproducible | ✓ | Recálculo independiente1007/1007, siete píxeles exactamente200, energía1545,533; valores199/200/201/púrpura comprobados. |
| Pruebas públicas de ventana, revisión y archivos | ✓ código, ejecución completa pendiente | E2E conserva estilos privados/selecciones anteriores; no se convierte ningún focal fallido a PASS. |
|39fuentes/76generados/siete descriptores al día | ✓ | SHA comprobados por ambas lentes; siete descriptores comprobados por A3. |
| Criterios anteriores de API/codec/IDs/temporalidad/fuentes/ZIP | ✓ preservados | Tabla completa del bloque previo conservada; no se reejecuta C2/D1 sin delta que lo justifique. |

**Cero Critical/Important/Minor de revisión3.** B3 ejecutó11/11 probes puros de orden/caché/SVG/defaults/umbrales y recalculó ambos PNG mediante PIL, sin nativos. A3 verificó unión y descriptores y recálculo en lectura. Un addendum de prosa corrige la frase histórica «todavía no ejecutados» del test-plan: QA2 ejecutó la versión anterior; los criterios actuales reforzados esperan QA3. A3 revalidó ese único párrafo,39+76+7 iguales; TDDn/a: prosa.

QA3 tendrá observación independiente de preview375 y DEFAULT/CONTROL PNG/MP4/GIF, manteniendo los42 checks previos y una sola pasada íntegra con fuentes congeladas. No hay cierre, commits ni push antes del resultado.


## Resultado posterior — 2026-11-06

[QA3 final](../final-qa3/report.md) ejecutada con las fuentes corregidas:72/72,42/42medios y24/24contraste, tres viewports legibles, PDF9páginas inspeccionado y842SHA sin deriva. Root acepta localmenteT-07/T-11. Las tablas y focales anteriores conservan sus resultados históricos; esta nota no convierte sus fallos enPASS. VPS/R2/social/T-10/ASR permanecen fuera de esa aceptación y el roadmap sigue abierto.
