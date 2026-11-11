# T-11: fotogramas estáticos por página

2026-11-05. Subbloque verificado en el árbol local de `feature/creative-social-suite`, sobre `94e17d7`, sin commit ni despliegue. T-11 sigue en progreso y el total del roadmap permanece en7/23. No acredita exportación MP4/GIF ni aceptación audiovisual completa.

## Resultado

El diálogo de exportación de un documento v2 permite elegir segundos locales por página e inicializa las páginas donde está el cabezal con ese instante. `frame_times` es una lista estricta de objetos `{page_id,time}`: página seleccionada sin repetir, número finito desde0 y menor que su duración sin redondear. Las páginas omitidas usan0. Los documentos v1 conservan su póster anterior y rechazan una lista no vacía.

Web, API y `export_design` MCP usan las mismas validaciones y guardan la selección en `options.frame_times`. El registro de archivos del navegador y el trabajo del servidor conservan revisión, marca y permisos existentes. La validación del JSON original distingue listas de objetos vacíos o indexados que Laravel convierte a arrays PHP.

PNG/JPG/WebP/SVG/PDF respetan las ventanas semiabiertas, visibilidad de grupos y fase de vídeo con trim/rate/offset/loop. Se preparan solo los recursos y fuentes presentes en el fotograma; el documento original no cambia. La reconstrucción de grupos conserva las posiciones locales de los hijos y evita recentrar el conjunto al excluir hermanos inactivos. El vídeo agotado retiene un instante dentro del tramo recortado y del origen; nunca toma el primer fotograma posterior al trimEnd.

Son fotogramas locales de páginas. Transiciones entre páginas y mezcla de sonido corresponden al render audiovisual del proyecto completo, todavía pendiente.

## Verificación

| Comprobación | Resultado | Evidencia |
|---|---|---|
| PHP completo después de revisión |2740/2740,16149aserciones;0fallos/errores/skips |[JUnit](raw/phpunit-full.xml) |
| Contrato y regresiones focales |26/26,49aserciones; incluye seis casos HTTP de objetos JSON |[JUnit](raw/phpunit-frames.xml), [RED JSON6/6](raw/phpunit-json-red.xml), [RED inicial16/16](raw/phpunit-initial-red.xml) |
| Frontend final |20archivos,95/95;0fallos/cancelados/skips |[TAP real](raw/frontend.tap) |
| E2E integrado |54/54 en desktop/375/360;0fallos/flaky/skipped/interrupted/erroresrunner y0reintentos |[JSON](raw/playwright-integrated.json), [gateVERDE](raw/gate-integrated.json) |
| Diálogo suplementario |3/3 en los mismos viewports; título y Descargar alcanzables tras scroll, píxeles/metadata intactos |[JSON](raw/playwright-dialog-supplement.json), [gateVERDE](raw/gate-dialog-supplement.json) |
| OpenAPI |0errores/0rompedores frente a baseline;3avisos informativos previos |[Lint](raw/openapi-lint.json), [diff](raw/openapi-diff.json), [baseline](raw/openapi-before.yaml) |
| Integridad |18fuentes y24artefactos con SHA256 |[Manifiesto](manifest.json), [diff revisado](raw/reviewed.diff) |

La pasada54 usa la fuente de test de [integrated-sources](raw/integrated-sources.json). Después se añadió únicamente scroll, dos assertions de viewport y otra captura en `studio-frame-export.spec.ts`; ningún código del producto cambió. Los tres recorridos suplementarios usan ese test actualizado, descrito en [reviewed-sources](raw/reviewed-sources.json) y [suplemento](raw/review-supplement.diff). No se presentan como57casos únicos ni se atribuyen las nuevas assertions a la pasada anterior.

El test E2E exporta instantes0/1,5, descarga los bytes privados registrados y mide PNG blanco/rojo, además de metadata y documento intacto. Las pruebas Chromium añaden vídeo propio rojo/azul, fase/loop, agotamiento del tramo, visibilidad heredada y páginas con tiempos distintos en orden elegido. Cuatro formatos se comprueban por píxeles; PDF se verifica como PDF real y reutiliza la misma exportación PNG temporal. El proceso aislado Node/Chromium también produjo blanco/rojo/blanco sin cookies ni red externa; no es un mock del renderer. Las pruebas de preparación PHP sí simulan el proceso para aislar qué recursos se empaquetan; no se usan como prueba del píxel nativo.

## Revisión y correcciones

Baseline preservada antes de editar diez piezas compartidas; dos controladores se añadieron antes del fix HTTP. Primer manifiesto16fuentes, actual18. Scope sin archivos fuera, exclusiones ni avisos; C=false/D=true. Sin Constitución, interop ni Jira configurados.

| Intento | Hallazgo y resultado |
|---|---|
|1|A1 Important: objetos JSON `{}`/indexados aceptados como listas; B1 Important: vídeo agotado muestra frame posterior al recorte. RED16b308 y6d0da2 reproducen ambos. D sin hallazgos; geometría y demás criterios aprobados. |
|2|A2/B2 confirman correcciones: guard de tipo JSON en las tres entradas reales y límite dentro del trim/origen.26/26PHP y7/7Chromium; B añade ocho sondas, incluidas ventanas estrechas. D2 sin hallazgos;18SHA coinciden antes/después. |
|3|A3/B3 leen exclusivamente el suplemento del test: no relaja píxeles/metadata/documento/limpieza; título y Descargar se comprueban tras scroll y con capturas separadas. B3 lee el JSON3/3; root confirma terminal85941 y revisa las seis capturas. A3 emitió su lectura cuando el resultado suplementario aún le constaba pendiente; no se le atribuye una ejecución ni comprobación que no hizo. |

El ledger recoge los fallos de fixtures sin atribuirlos al producto: posiciones por defecto fuera del píxel medido y dos intentos sobre una ruta web render inexistente. Los RED funcionales se repitieron tras corregir esas preparaciones. El recentrado sí era un fallo real: se confirmó con matriz y píxeles que local−50 pasaba a−30 y la forma empezaba20px después; conservar posiciones locales cierra ambos.

D midió una fixture legal de100páginas/3000capas: selección/filtrado mediana0,049ms y p950,119ms. El guard JSON de100entradas añadió una mediana0,0456ms web/0,0407ms MCP, sin consultas nuevas. En100exportaciones locales el clamp corregido mantuvo100seeks/100decodificadores/0fuentes retenidas; mediana2,6ms antes/después. Son medidas con medios sintéticos pequeños y no acreditan latencia remota ni rendimiento HD.

La ejecución integrada fue de root después de revisión independiente; no se presenta como QA independiente. Los procesos de PHP41784, frontend71869, E2E82456 y suplemento85941 son terminales. No repetir estas suites sin un cambio o fallo nuevo.

## Inspección visual y pendientes

Se inspeccionaron las seis capturas: [desktop título](screenshots/desktop-heading.png), [desktop acciones](screenshots/desktop-actions.png), [375px título](screenshots/mobile-heading.png), [375px acciones](screenshots/mobile-actions.png), [360px título](screenshots/mobile-360-heading.png), [360px acciones](screenshots/mobile-360-actions.png). El diálogo móvil usa desplazamiento interno: no se exige que ambas zonas estén simultáneamente visibles. Los recorridos comprueban acceso al título y Descargar con intersección>=99%, además de cierre, cero desbordamiento horizontal y consola limpia.

Sigue render MP4/GIF reanudable por tramos, mezcla/transiciones, duración/fps/frames/espectro medidos, cancelación/recuperación y registro/borrador compartidos web/API/MCP. T-10 conserva la aceptación de memoria pendiente; no se ejecutaron ONNX ni E2E de quitar fondo. No se modificaron `.env`, conexiones reales, cron, workers, VPS ni bucketR2 del usuario.
