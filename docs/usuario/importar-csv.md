# Importar publicaciones por CSV

Crea muchas publicaciones de una marca de una vez. Entra en **Planificación → Importar CSV** (con la marca elegida).

## Flujo

1. **Sube el fichero.** El servidor lo analiza y muestra una **vista previa fila a fila** con los datos interpretados y los errores de cada una. En este paso no se crea nada.
2. **Confirma.** Se crean solo las filas válidas. El servidor vuelve a validarlas al confirmar (una fecha puede haber pasado entre tanto). Las filas con errores siguen listadas y no se crean.
3. **Resumen.** Publicaciones creadas (con enlace para abrirlas), filas omitidas con su motivo y acceso al calendario.

Puedes descargar un **CSV de ejemplo** desde la página. Lleva las columnas documentadas y la leyenda de las cuentas de la marca en líneas que empiezan por `#`, y solo incluye filas que **son válidas para las cuentas reales de la marca** (una programada y un borrador). Si ninguna red de la marca admite una fila sin archivos (por ejemplo, solo tiene Instagram), no trae filas y un comentario lo explica: añade ids en `medios`. Usa el separador de la configuración común (`reports.csv_locale`: `;` en español, `,` en inglés) y ninguna celda puede empezar por `=`, `+`, `-` o `@`.

## Formato

- UTF-8 (se tolera el BOM). Separador `;` o `,`, detectado por la cabecera. Comillas según RFC 4180: un texto con comas o saltos de línea va entre comillas, y una comilla se escribe doble (`""`).
- La primera fila es la cabecera. Las líneas en blanco o que empiezan por `#` **antes** de la cabecera se ignoran. Las filas totalmente vacías se ignoran.

| Columna | Obligatoria | Contenido |
|---|---|---|
| `fecha` | sí (puede ir vacía) | Hora local de la marca. Formatos: `AAAA-MM-DD HH:MM` (también con `T`) y `DD/MM/AAAA HH:MM` (como lo reescribe Excel en español; día y mes pueden llevar una cifra), con segundos opcionales si son `00`. Con barras el primer número es siempre el día: nunca se interpreta `MM/DD`. Las fechas imposibles (`31/02/2026`, mes 13) se rechazan. Vacía = borrador. |
| `texto` | sí | Texto de la publicación. |
| `cuentas` | sí | Una o varias cuentas **de esta marca** separadas por `\|`, escritas `red:id` (por ejemplo `facebook:12`). Es el único formato: el id es único, así que dos cuentas con el mismo nombre, o con `\|` o espacios en el nombre, nunca se confunden. La página y los comentarios del CSV de ejemplo listan el identificador de cada cuenta junto a su nombre. |
| `tipo` | no | Tipos de contenido separados por `\|` (por ejemplo `instagram_reel`). Cada uno se aplica a la cuenta de su red; debe haber una cuenta de esa red en la fila. Si falta, se usa el tipo por defecto de la red (Instagram: imagen, Facebook: texto o foto, Threads y LinkedIn: texto o imagen, Bluesky: publicación, YouTube y TikTok: vídeo, Pinterest: pin). |
| `medios` | no | Ids de archivos de **tu** biblioteca separados por `\|`. |
| `aprobacion` | no | `sí` o `no`. Con `sí` la publicación nace como borrador pendiente de aprobar (la puede aprobar el propietario o un aprobador de la marca). |

Una columna desconocida o repetida, o la falta de una obligatoria, es un error del fichero entero.

## Validación

Es la **misma que la del compositor**: cada fila se convierte en el mismo payload y pasa por las mismas reglas. Una fecha en el pasado, una red que exige media sin ella o un texto que supera el límite de la red salen como error de esa fila con el mensaje del compositor. Una cuenta que no existe, es de otra marca o de otro usuario da siempre el mismo error: no se revela si existe en otro sitio.

## Límites

Definidos en `config/post_import.php`:

| Límite | Valor por defecto |
|---|---|
| Tamaño del fichero | 1024 KB |
| Filas de datos | 200 |
| Caducidad de la vista previa | 30 minutos |

Además, la subida y la confirmación tienen su límite de ritmo por minuto (`brands.posts.import.preview` y `brands.posts.import.confirm` en `config/rate_limits.php`).

## No se duplica

Cada vista previa genera un identificador de un solo uso, ligado a tu usuario y a la marca, y solo hay **una vista previa viva por usuario y marca**: subir otro fichero sustituye a la anterior. Al confirmar, el servidor lo reclama de forma atómica antes de crear nada. Si la confirmación se corta (por ejemplo, un tiempo de espera) y la repites, **recibes el mismo resumen y los mismos enlaces sin que se cree nada más**; si se interrumpió a medias, recibes un aviso con las filas que ya se procesaron. Si subes el mismo fichero otra vez, es una importación nueva.

## Errores frecuentes

- **«Falta la columna obligatoria»**: la cabecera no tiene `fecha`, `texto` o `cuentas`.
- **«El fichero debe estar codificado en UTF-8»**: guarda el CSV como UTF-8.
- **«La fecha … no tiene un formato válido»**: usa `2031-03-10 10:00` o `10/03/2031 10:00`. **«no existe en el calendario»**: día, mes u hora imposibles. **«tiene el mes 27»**: con barras va primero el día. Una hora inexistente o ambigua por el cambio de hora también se rechaza.
- **«No hay ninguna cuenta … en esta marca»** o **«Cuenta … no válida»**: copia el identificador `red:id` de la lista de la página.
- **La fecha debe ser futura**: las filas con fecha pasada no se crean.
- **Una red exige media**: añade ids en `medios`.
