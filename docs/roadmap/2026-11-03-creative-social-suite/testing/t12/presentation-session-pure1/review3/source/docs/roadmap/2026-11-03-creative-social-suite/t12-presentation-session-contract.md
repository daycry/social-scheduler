# T12: sesión de presentación nativa

Contrato P1 previo al código, 2026-11-07. Primer componente de presentaciones de T12, sin reducir el alcance posterior de vista de audiencia/presentador/notas, pantalla completa, autoplay/grabación y Office editable. El módulo no se anuncia como presentación usable hasta integrar UI, persistencia de notas y QA real. No modifica ahora el documento v1/v2 ni el build7 congelado para gráficos.

## Módulo y estado

`resources/js/Studio/presentation.js` exporta `createPresentationSession(document, options={})`. Usa el validador real `validateDocument` y toma una copia propia de páginas (id/nombre/duración); un cambio posterior del documento no altera una sesión en curso. Conserva orden seleccionado mediante `pageIds` opcional (IDs existentes únicos, lista no vacía, máximo100); por defecto todas las páginas. Opciones `pageIds`, `startPageId`, `loop` únicamente, propias de datos sin getters; loop booleano por defecto false y startPageId debe pertenecer a la selección. Rechaza opciones desconocidas o inválidas antes de iniciar.

La sesión devuelve `snapshot()` con estado propio profundamente congelado: `pageIds`, `index`, `pageId`, `pageName`, `nextPageId` (null al final sin loop), `elapsedMs`, `durationMs`, `playing`, `ended`, `blackout`, `disposed`. Estado inicial: primera página ostartPageId,elapsed0,playingfalse,endedfalse,blackoutfalse,disposedfalse. Duración usa page.duration del documento validado, segundos a milisegundos, sin alterar timeline/captions.

Comandos `play()`, `pause()`, `next()`, `previous()`, `first()`, `last()`, `select(pageId)`, `advance(deltaMs)`, `setBlackout(boolean)`, `dispose()` devuelven snapshot actual. Selección/navegación reinician elapsed0/endedfalse conservando playing, salvo siguiente al final sinloop: índicefinal/elapsed=duration/playingfalse/endedtrue. Anterior al principio sinloop conserva primera yreinicia elapsed; loop envuelve next/previous. Seleccionar ID desconocido falla sin cambiar estado. play después de ended reinicia primera/elapsed0/endedfalse; pause conserva posición y fracción.

`advance` admite número finito no negativo <=Number.MAX_SAFE_INTEGER; valores inválidos fallan sin cambiar estado incluso pausado. Mientras pausado no consume tiempo. Durante play suma elapsed y avanza con límites exactos de duración; al límite de una diapositiva entra en siguiente conelapsed0. Al completar última sinloop conserva última conelapsed=duration/endedtrue/playingfalse. Loop descuenta ciclos completos sin bucles proporcionales al delta; como máximo recorre100páginas para localizar posición final, incluso un delta enorme. Conserva la fracción sobrante. Blackout oculta sólo la salida visual futura; no pausa ni modifica orden/duración. No se implementan relojes, timers, DOM, fetch, sonido ni acceso a navegador en este módulo.

dispose es idempotente y fija playingfalse/disposedtrue; snapshot sigue disponible. Todos los otros comandos tras dispose fallan sin mutar estado, con mensaje explícito de sesión cerrada. No se permiten getters/prototipos ajenos como entradas de opciones; no se publican objetos del documento ni arreglos mutables de estado.

## Pruebas y límites

TDD en `tests/frontend/studio-presentation.test.mjs`: test primero con RED por módulo ausente, luego implementación. Verificar selección y orden/no duplicados, duración subsegundo, fronteras exactas/múltiples, pausa/reanudar, fin/reinicio, loop con delta enorme limitado, navegación, selección inválida atómica, blackout, aislamiento frente a mutación del documento/estado, dispose y entradas inválidas. Usar documentos v1 yv2 reales con timeline preservado en origen. Node512, sin navegador/build/modelos/DB/red y sin timers/esperas artificiales.

Ownership implementación: sólo esos dos archivos nuevos y evidencia privada `storage/framework/testing/t12-presentation-session1/**`; root mantiene docs/ledger. No importar el módulo en Editor ni modificar archivos existentes durante la QA de gráficos. Tras TDD y revisión A/B segúnselector, root integrará UI/persistencia y encargará QA nativa. Ningún PASS puro acredita pantalla completa, presenter/notes/Office/grabación ni cierra T12.
