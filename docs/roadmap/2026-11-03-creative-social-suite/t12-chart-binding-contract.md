# T12 — referencia y proyección de datos de gráficos

Contrato interno previo a la implementación del siguiente subbloque. No incorpora aún campos al documento público ni cambia endpoints. La tabla editable conserva su contrato y su aceptación independiente. El catálogo completo de 32 configuraciones y 11 familias sigue en [cobertura exigida](t12-chart-coverage.md).

## B1. Identidad y selección

El gráfico se fija a una referencia privada `{id, revision, sha256}`: enteros positivos seguros para id/revision y SHA-256 hexadecimal minúsculo de 64 caracteres. La propiedad de marca se comprobará en el servidor al integrar el documento. Una referencia válida por forma no acredita permiso ni existencia. No hay URL, ruta de archivo, código o configuración arbitraria de una biblioteca de gráficos.

La proyección recibe además metadatos de la revisión realmente leída y la tabla tipada del núcleo existente. Deben coincidir id, revision y sha256 antes de utilizar sus celdas. La tabla original no se muta ni se incrusta dentro del documento de 2 MiB. Actualizar a una revisión distinta será una acción explícita con undo/redo en la futura integración.

La primera proyección categórica cubre los datos necesarios para las diez configuraciones de barras y columnas; no acredita geometría ni ofrece aún esas opciones en la interfaz. Configuración cerrada:

```json
{
  "dataset": {"id": 1, "revision": 2, "sha256": "64 caracteres hexadecimales"},
  "category_column": "category",
  "series_columns": ["value"],
  "precision": "safe"
}
```

IDs de columna ASCII alfanuméricos, guion o guion bajo, de 1 a 64 caracteres. Entre una y doce columnas de serie, distintas entre sí y de la columna de categoría. Deben existir en la revisión cargada. No se seleccionan por etiqueta porque las etiquetas pueden repetirse. Campos desconocidos, propiedades accesoras, arrays dispersos y valores heredados se rechazan sin invocar getters. Sólo se aceptan objetos ordinarios o con prototipo nulo y propiedades propias de datos.

## B2. Tipos, aproximación y diagnóstico

Las categorías aceptan texto, número tipado o null. Conservan la identidad de fila; las categorías repetidas siguen siendo observaciones distintas. Un número usado como categoría muestra su lexema original. Null se presenta como categoría vacía identificada, sin confundirse con la cadena vacía en los datos accesibles.

Una serie acepta números tipados del núcleo o null. Null significa valor ausente y se conserva como hueco; no se transforma en cero. El texto, incluso `"12"`, exige conversión explícita en la tabla antes de graficar. El diagnóstico identifica fila, columna e ID afectados. No se evalúan fórmulas ni se adivinan separadores o fechas. Cero y negativo son datos válidos en esta proyección; las restricciones específicas de cada tipo se aplicarán antes de generar su geometría.

`precision` es `safe` por defecto o `approximate` por decisión explícita. Las coordenadas usan Number finito; el lexema original se conserva por separado para etiquetas, tabla accesible y tooltips. En modo safe se rechaza una magnitud superior a Number.MAX_SAFE_INTEGER, incluso si se escribe con exponente. También se rechaza un número decimal no nulo que Number redondee a cero por underflow: su barra no puede desaparecer silenciosamente como si el dato fuese cero. En approximate se permiten ambos casos finitos, pero la salida incluye advertencias por celda afectada, distinguiendo pérdida por magnitud y por underflow. Los lexemas de cero con exponentes extremos siguen siendo cero sin advertencia. Nunca se cambia el dato persistido. No se afirma exactitud decimal de coordenadas binarias; conservar la etiqueta original no equivale a conservar precisión geométrica ilimitada.

La salida contiene categorías con row_id/tipo/etiqueta original y series con column_id/etiqueta y valores `{value, raw}` o null. Arrays alineados con el orden de filas y selección de series. Sin agrupar, ordenar, sumar ni eliminar observaciones silenciosamente. La tabla válida sin filas produce arrays vacíos. Una revisión completa supera a una respuesta parcial: no se proyectan páginas sueltas como si fueran toda la tabla.

## B3. Presupuesto y puertas

Máximo 1000 filas, 100 columnas de origen y 12 series seleccionadas, reutilizando límites y validación del núcleo. Recorrido acotado por tabla de entrada y proyección de hasta 12.000 valores; no serializar/copiar 52 MB repetidamente por celda ni hacer I/O. La API interna será `normalizeChartBinding(value)` y `projectCategoricalChart(binding, snapshot)`, donde snapshot tiene exactamente `{id, revision, sha256, table}`. Devuelve datos propios inmutables sin conservar objetos externos mutables. El módulo no conoce DOM, Fabric, HTTP, BD o bibliotecas externas.

TDD genuino por criterios, incluyendo identidades/revisión/SHA discrepantes, selección duplicada/inexistente, getter que no se ejecuta, arrays dispersos, Unicode, números con lexema, negativos/cero/null/texto, límites y precisión explícita. Revisión A/B y C/D según selector. Este subbloque no cierra una sola configuración de gráfico: cada tipo conserva las puertas de geometría, controles, integración por marca, reapertura/undo y exportación real documentadas en el inventario.
