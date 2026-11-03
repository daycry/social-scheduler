# Contrato del documento nativo v1

Contrato compartido de UI, API y MCP. JSON, máximo 2 MiB; 1–100 páginas; dimensiones de diseño enteras de 40–8000 px. Identificadores únicos de página/elemento de 1–64 caracteres ASCII alfanuméricos, guion o guion bajo. Geometría finita y acotada. Texto plano, nunca HTML ejecutable. El servidor rechaza documentos desconocidos o recursos ajenos.

El límite de bytes se mide sobre JSON UTF-8 canónico, sin escapar Unicode ni barras; los límites de caracteres cuentan puntos de código Unicode. Los grupos deben contener al menos un elemento. Estas reglas evitan aceptar documentos que después no pueden reabrirse en el editor.

```json
{"schema_version":1,"width":1080,"height":1080,"pages":[{"id":"page_1","name":"Página 1","background":"#ffffff","duration":5,"elements":[{"id":"title","type":"text","name":"Título","x":80,"y":100,"width":920,"height":120,"scaleX":1,"scaleY":1,"rotation":0,"opacity":1,"locked":false,"visible":true,"fill":"#111827","text":"Hola","fontFamily":"Arial","fontSize":64,"fontWeight":"bold","fontStyle":"normal","textAlign":"left","underline":false,"lineHeight":1.2,"charSpacing":0}]}]}
```

Elementos iniciales: `text`, `rect`, `ellipse`, `triangle`, `line`, `path`, `image`, `video`, `group`. Propiedades comunes: `id`, `type`, `name`, `x`, `y`, `width`, `height`, `scaleX`, `scaleY`, `rotation`, `opacity`, `locked`, `visible`, `fill`, `stroke`, `strokeWidth`, `flipX`, `flipY`, `shadow`. Grupo contiene `elements` recursivos; profundidad máxima 8. Cada página tiene hasta 500 elementos, contando descendientes. Dimensiones no negativas, escala positiva; coordenadas -32000–32000, escala 0.01–100, rotación -360–360, opacidad 0–1. IDs únicos en todo el documento.

`skewX` y `skewY` opcionales, -89–89 grados, preservan la transformación de una selección múltiple rotada y escalada no uniformemente. No se descartan al guardar o reabrir.

`fill`/`background`: color hexadecimal o gradiente `{type:"linear",angle:0,stops:[{offset:0,color:"#ffffff"},{offset:1,color:"#7c3aed"}]}` (2–12 paradas ordenadas). `shadow`: `{color:"#000000",blur:10,offsetX:0,offsetY:4}`. `radius` opcional en rectángulos. `stroke` hexadecimal, o cadena vacía para ausencia.

Texto: hasta 10000 caracteres; fuente hasta 100 caracteres (familia, sin CSS/URL), tamaño 1–1000, peso `normal|bold`, estilo `normal|italic`, alineación `left|center|right|justify`, subrayado, interlineado 0.5–5 y espaciado -200–2000. Las tipografías externas se registran como recursos; escribir un nombre no garantiza su disponibilidad.

Imágenes/vídeos: `asset_id` entero positivo, registrado en la misma marca; no aceptan URLs arbitrarias. `cropX`/`cropY` opcionales >=0. Filtros: `brightness`, `contrast`, `saturation` -1..1; `blur` 0..1; `grayscale`, `sepia`, `invert` booleanos. Vídeo añade `trimStart`, `trimEnd`, `volume` y `muted`. Rutas para preview se entregan en metadatos de recursos, fuera del documento.

`path` contiene comandos vectoriales `M,L,C,Q,Z` y sus coordenadas numéricas; no contiene SVG/XML libre. Líneas usan `x1,y1,x2,y2` opcionales. Los futuros tipos (tablas/gráficos/docs/timeline) incrementarán el contrato explícitamente; no se ejecutan clases de una librería a partir de JSON ajeno.

Actualización: `{name,document,revision,is_template?,folder_id?}`. `revision` esperado obligatorio para modificar. El servicio guarda snapshot y aumenta revisión; versión incompatible responde 409. Exportación registra revisión existente, páginas elegidas en orden y archivos validados. Nunca se sobrescriben bytes de una exportación anterior.

Kit por marca: `{palette:["#..."],fonts:["Arial"],logos:[media_id],guidelines:"...",voice:"..."}`. Los logos deben estar registrados en esa marca. Una plantilla es un diseño marcado `is_template`; instanciar o duplicar crea un documento y revisiones independientes.
