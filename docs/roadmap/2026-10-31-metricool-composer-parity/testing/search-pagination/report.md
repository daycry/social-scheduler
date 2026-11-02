# Corrección del buscador y paginación de audio — T-10

Fecha: 2026-11-01. Resultado: **VERDE**. Búsqueda y paginación comprobadas en el proveedor real; regresión local y revisión A+B+D cerradas. Rama `feature/metricool-composer-parity`, cambios locales. La publicación real sigue a cargo del usuario, T-06/CA-10.

## Causa y corrección

El servicio solo devolvía `results` y descartaba `paging.cursors.after`. Meta puede entregar ese cursor sin `paging.next`: la consulta real `Dhruv Goel` devolvió 25 pistas, y al pedir `after` llegaron otras 25 distintas. El defecto impedía acceder a páginas posteriores desde web y MCP. Las consultas previas de Aipocalipsis/Libre no devolvían cursor y no bastaban para acreditar el buscador completo.

Ahora cada petición devuelve `results`, `next_cursor` y `has_more`. La continuación llama al mismo `ig_audio` con el cursor de cada tipo; no sigue URLs externas ni reinicia un tipo ya agotado. El cursor de aplicación está cifrado, ligado a cuenta/ID externo/texto/filtro y caduca a los 30 minutos. Máximo dos consultas a Meta por página solicitada, sin descargar todas las páginas automáticamente.

El compositor busca al escribir tras 350 ms de pausa y permite Enter/Buscar. Descarta inmediatamente la consulta anterior, aborta su transporte y no deja que una respuesta tardía reemplace la nueva. **Cargar más audios** añade resultados, deduplica por ID y conserva lista/cursor si falla para poder reintentar. Texto/filtro nuevo reinicia la paginación; cerrar cancela el temporizador.

## Evidencia real sin publicaciones

- [Búsquedas de control y limit 10/50](raw/audio-search-probe.json): las búsquedas con más resultados devuelven cursor; aumentar limit no amplió los 10 resultados de `Libre`.
- [Dos páginas del proveedor](raw/audio-pages-probe.json): 25+25 pistas diferentes con `paging.cursors.after`, sin `paging.next`.
- [Servicio corregido contra Meta](raw/audio-fixed-search-probe.json): 25+25=**50 pistas distintas** usando nuestro `next_cursor`; la primera de la segunda página es «Leviathan» de Dhruv Goel.

La cuenta probada es la conexión local `marblepartyrace`. No se comparó con otra conexión de Metricool ni se identificó un ID de «Libre». La corrección de paginación no acredita que esa canción concreta esté disponible. Las evidencias no incluyen tokens, URLs temporales ni cursores en bruto.

## Pruebas y revisión

- RED backend: `cursor-only pagination keeps both catalog positions and never follows provider URLs` → next_cursor null, esperado string.
- RED web: escribir `Artista` sin Enter/Buscar no mostraba su resultado; [run original](../../../../../storage/framework/testing/t43/run-general-1ea65c4a-a754-4826-8058-d00c1990a220/results.json).
- GREEN focal PHP: **14 tests, 122 aserciones**, incluidos rechazo de cursor alterado, de otra cuenta/consulta/filtro, caducado o demasiado largo; repetición/vacío sin continuación; transporte MCP y esquema.
- GREEN focal Playwright escritorio: **2/2**, paginación/reintento/deduplicación/filtro y descarte de respuestas tardías/cierre.
- Regresión PHP completa: **2.495 tests, 14.715 aserciones**, 0 fallos/errores/skips. [JUnit](raw/phpunit.xml).
- Node: **12/12** de creator_info para comprobar la regresión vecina; Vite y `npx tsc --noEmit` correctos.
- Lentes B/D independientes: sin defectos/hallazgos; ejecutaron cada una **4 tests/44 aserciones** y arneses JS del componente actual (conservación/reintento, debounce, respuesta tardía y cancelación).
- Lente A independiente: sin gaps; ejecutó **14 tests/122 aserciones**, scope-check exit 0 y ledger-lint sin incoherencias/avisos. CA-13 y T-10 conformes; el cierre de QA pendiente al emitir su revisión queda acreditado por la ejecución completa siguiente.
- E2E completo de audio: **21/21** en escritorio 1440×900, móvil 375×812 y móvil 360×740; 0 fallos/flaky/skips/errores globales. Run `run-general-5ebc9825-0bf3-4a19-917e-be3757eb16ef`, [JSON oficial](raw/playwright.json), [qa-gate VERDE](raw/qa-gate.json).

Capturas de las dos páginas reunidas y deduplicadas: [escritorio](screenshots/audio-search-pagination-desktop.png), [móvil 375](screenshots/audio-search-pagination-mobile.png), [móvil 360](screenshots/audio-search-pagination-mobile-360.png). Inspección visual directa en escritorio y móvil 360, junto con las comprobaciones de desbordamiento del E2E. Los fixtures muestran primera pista, segunda página y contador de dos pistas; el botón de continuación desaparece al agotarse el cursor. [Manifiesto de fuentes, build y artefactos con SHA256](validation-summary.json).

Los fallos de bundle antiguo y selectores del test se conservaron en el ledger; no se cuentan como defectos nuevos de producto. [T-10 y traza de revisión](../../tasks.md), [guía web/MCP](../../../../guias/audio-instagram.md). La propuesta de conocimiento queda en candidates/pending para su curación.

Nota de seguimiento: este informe y sus hashes son históricos de T-10. La ampliación posterior T-11/T-12 y su estado actual están en [QA de presets](../presets-ui/report.md).
