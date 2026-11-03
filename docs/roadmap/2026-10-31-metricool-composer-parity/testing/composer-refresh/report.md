# Refresco del compositor al crear — T-13

Fecha: 2026-11-03. Rama `fix/composer-refresh-after-save`, base `46c3b014bc70a8e2e69b375d1d8b2ed91d2f079e` (PR #17 integrada, CI correcta). Resultado local: **VERDE**.

## Causa y cambio

Inertia conservaba la instancia de `Posts/Compose` al pasar del formulario nuevo a la edición del borrador creado. Permanecían campos de adjuntos y previsualizaciones temporales de la creación. La creación correcta ahora reconstruye el componente desde el post guardado que devuelve el servidor. Una creación rechazada vuelve a una página sin post y conserva el formulario. Las programadas siguen al calendario. No se cambia la ruta de guardado ni el contenido persistido; los adjuntos del borrador siguen perteneciendo al borrador.

La decisión usa `page.props.post`, independiente de mensajes temporales de sesión. La alternativa inicial basada solo en `errors` se retiró al detectar que una respuesta sin esa información podía borrar trabajo rechazado. La edición, las notas independientes y Guardar en biblioteca conservan sus flujos anteriores.

## Evidencia

- RED funcional: `run-general-35f223e7-6edb-4ffc-b8e4-d32262b5d154`. Guardado correcto y URL `/posts/id/edit`, pero Desde URL conservaba la entrada pendiente. [JSON](raw/red-playwright.json).
- La prueba real crea una imagen en el disco desechable, simula su subida, provoca un rechazo de servidor, recupera el archivo y guarda. Verifica datos conservados ante error, previsualización guardada tras crear, edición y otra publicación vacía.
- La fixture espera tanto la validación con debounce como la redirección completa. Las primeras iteraciones corrigieron fixtures y sincronización; sus fallos no se presentan como RED del producto.
- Build y TypeScript: exit 0 tras la condición definitiva (`f8f605`). [Log de build](raw/build.txt).
- QA final: **21/21 escenarios pasados**, cero fallos/flaky/skips/errores, incluidos los seis nuevos de alta/edición y la regresión de presets, errores y notas en 1440×900, 375×812 y 360×740.
- No se repite PHP: el cambio de producción está limitado al envío frontend. La suite 2.511/14.763 de T-12 es evidencia histórica de esa entrega.

## Revisión

Intento 1: A/B conformes con la alternativa inicial y QA pendiente. El orquestador detectó O1 (reconstrucción de un formulario rechazado si faltaban errores en la respuesta) y retiró esa alternativa antes de cerrar. Intento 2: revisión A/B de la condición definitiva cerrada sin gaps; A ejecutó cuatro casos y B 27 combinaciones del callback y comprobaciones de callbacks/PUT, todas PASS. El selector determinista no activa C ni D. Los revisores son de solo lectura; no se publicaron posts reales.

[Ledger](../../tasks.md) · [Criterio CA-16](../../spec.md). T-06, pruebas del servidor y publicaciones reales continúan a cargo del usuario.

## Resultado final

[Playwright completo](raw/playwright.json) · [qa-gate VERDE](raw/qa-gate.json) · [manifiesto de fuentes y evidencias](validation-summary.json). Run final: `run-general-45c087f5-72cb-4dfe-9fac-463b76f76d9d`. [Extracto del trace de O1](raw/rejected-refresh-trace.json). [GREEN focal](raw/green-playwright.json). El manifiesto distingue bytes locales de fuentes y su normalización LF para contrastar tras clonar en otro sistema.

Publicado en [PR #18](https://github.com/daycry/social-scheduler/pull/18), commit `88ebdd0`. El manifiesto incluye también hashes de blobs Git de fuentes y evidencias para contrastarlos tras la normalización de saltos de línea de Windows.
