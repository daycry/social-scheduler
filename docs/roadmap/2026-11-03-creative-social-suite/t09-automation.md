# Contrato de automatización de plantillas y marca

Fecha: 2026-11-04. T-09 implementada y verificada con revisión independiente y QA del bloque. Este contrato amplía el documento v1 sin acoplarlo a Fabric; no acredita el cierre de la iniciativa completa.

## Plantillas propias y campos

Una plantilla es un diseño de la misma marca con `is_template: true`. El catálogo se obtiene con el filtro existente `templates=1`, con su paginación y búsqueda habituales. Guardar como plantilla crea una copia independiente; no convierte ni cambia el original. La edición, archivado y restauración reutilizan los permisos y servicios existentes.

Los textos pueden contener campos con la forma `{{nombre}}`. Se descubren también dentro de grupos. El detalle del diseño expone `template_fields` para construir formularios o automatizaciones. Una instancia recibe un objeto `fields` cuyos valores son texto plano: una cadena vacía y sus espacios son datos válidos. Se rechazan campos ausentes o desconocidos. La sustitución es literal y simultánea: `$&` no es una referencia de reemplazo y un valor que contiene `{{otro}}` no se interpreta otra vez.

Una instancia tiene documento, IDs de páginas/elementos e historial propios. Cambiarla no cambia la plantilla ni las demás copias. Se conservan las comprobaciones de propiedad y recursos registrados; una plantilla no concede acceso a archivos de otra marca.

## Tamaños y variantes

Las dimensiones cumplen los límites del documento. Hay tres adaptaciones explícitas:

| Modo | Comportamiento |
|---|---|
| `canvas` | Cambia el lienzo manteniendo las posiciones y escalas de los elementos. |
| `contain` | Escala el contenido uniformemente y lo centra para que el lienzo de origen quepa en el nuevo. Puede dejar espacio alrededor. |
| `cover` | Escala uniformemente y lo centra hasta cubrir el nuevo lienzo; los bordes pueden quedar fuera de él. |

La transformación uniforme de los elementos raíz conserva la geometría interna de grupos, rotaciones, recortes, trazados y texto. No se deforman los ejes por separado. Los fondos se adaptan al lienzo de destino.

Redimensionar actualiza el diseño y crea una revisión. Generar variantes crea hasta doce diseños independientes en una transacción, con el documento de origen inalterado. Se validan límites de documento y de trabajo acumulado antes de crear resultados. Los formatos verticales y apaisados se pueden abrir, editar y exportar con el renderer existente.

## Kit de marca

Aplicar la identidad usa la paleta y familias del kit actual de la marca. La referencia del kit se conserva para que el cambio de sus estilos permita una sustitución por posición sin reemplazar indiscriminadamente las fotos del diseño. Solo las imágenes identificadas como logos de la referencia se sustituyen por logos correspondientes. Cuando falta una referencia de estilos se utiliza un orden de aparición determinista de los colores y familias del documento.

Aplicar un kit no instala tipografías; la carga de fuentes pertenece a T-10. La revisión del diseño protege la operación frente a otro escritor.

## Superficies compartidas

Las cinco operaciones requieren `revision` del origen. La web guarda primero los cambios pendientes y no continúa si hay conflicto. La API exige `designs:write`; MCP utiliza la misma autorización por acción de `manage_design`.

| Operación | API después de `/api/v1/designs/{design}` | Resultado |
|---|---|---|
| Guardar como plantilla | `POST /template` | `201`, `{design}` de la copia. |
| Crear desde plantilla | `POST /instantiate` | `201`, `{design}` de la instancia. |
| Redimensionar | `POST /resize` | `200`, `{design}` actualizado. |
| Generar variantes | `POST /variants` | `201`, `{designs}` independientes. |
| Aplicar kit | `POST /apply-kit` | `200`, `{design}` actualizado. |

Los endpoints web equivalentes usan `/studio/designs/{design}`. Las respuestas existentes de API mantienen sus contratos; estas nuevas operaciones utilizan los envoltorios indicados. Un conflicto devuelve `409`, una entrada inválida `422`, y un diseño ajeno no se expone. El [OpenAPI](../../api/openapi.yaml) es la referencia de campos y límites.

## Verificación del cierre de T-09

- RED/GREEN de cada operación, aislamiento por usuario y marca, revisión obsoleta y reversión completa ante una variante inválida.
- Instancias con grupos/campos literales, estilos y referencias de recursos válidas.
- Transformaciones medidas sobre texto, objetos rotados, grupos y medios; reapertura y exportación del resultado.
- Paridad de web/API/MCP y permisos de lectura/escritura.
- E2E-07/08 en escritorio, 375 px y 360 px; capturas revisadas, sin errores JS ni desbordamiento.
- Revisión adversarial y qa-gate sobre resultados reales. El cierre de esta tarea no acredita T-10–23 ni las pruebas externas reservadas al usuario.

[QA independiente y PDF](testing/t09/qa/report.md): 24/24 recorridos integrados en tres viewports; gate VERDE. Suite PHP completa 2618/2618 y Studio Node/Chromium 26/26; evidencias en `testing/t09/raw`. La revisión corrigió referencia legacy, undo/redo del kit, presupuesto previo a sustitución y deduplicación de colores antes del cierre.
