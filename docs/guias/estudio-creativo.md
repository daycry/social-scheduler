# Crear diseños por marca

Abre **Estudio** y selecciona la marca en la cabecera. Cada marca conserva sus diseños, carpetas, kit y recursos registrados. Cambiar de marca desde un diseño vuelve al catálogo de la marca elegida.

1. Pulsa **Nuevo diseño**, elige nombre y tamaño, o parte de una de las 14 plantillas originales. Las miniaturas muestran el documento que se creará.
2. Añade texto, formas o archivos. **Subidos** registra la media en esta marca; un archivo pendiente o ajeno no se puede utilizar.
3. Selecciona un objeto en el lienzo o en **Capas**. **Propiedades** permite editar texto, geometría, rellenos, sombras, recorte y filtros. Los grupos y bloqueos se conservan al guardar.
4. Añade, duplica y ordena páginas. Deshacer/rehacer conserva el documento completo. Los atajos evitan interferir cuando estás escribiendo en un campo o editando texto.
5. Comprueba **Guardado** antes de cerrar. Si otra sesión adelantó la revisión, se conserva tu trabajo: descarga una copia local o recupera la versión guardada; no se sobrescribe silenciosamente.

En **Identidad de marca** guarda paleta, familias tipográficas, guía y voz. Las familias deben estar disponibles en el equipo o servidor que renderiza; escribir el nombre de una fuente no la instala. Las plantillas usan los estilos de la marca y crean copias independientes.

## Exportar y crear una publicación

**Exportar** permite seleccionar páginas, formato y opciones. PNG/JPEG/WebP generan imágenes reales; SVG incorpora los píxeles de los medios, y PDF reúne las páginas como imágenes de alta calidad. El PDF actual es rasterizado, no un documento vectorial editable.

**Guardar en biblioteca** sube y valida las imágenes. **Usar en publicación** crea un borrador en la marca del diseño con los archivos adjuntos; continúa editándolo en el compositor. No publica automáticamente. La exportación queda vinculada a la revisión que se guardó, aunque el diseño cambie después.

Vídeos en el lienzo pueden reproducirse y recortarse para preparar un fotograma. Los cinco formatos actuales son estáticos; timeline, mezcla y MP4/GIF continúan pendientes. También permanecen abiertos resize automático, colaboración, fuentes propias, IA creativa, documentos/sitios e integración externa de Canva. Consulta el [inventario completo](../roadmap/2026-11-03-creative-social-suite/reference-inventory.md), que no declara paridad total.

## API y MCP

Los mismos documentos, permisos, recursos y revisiones se usan desde la [API v1 y las nueve herramientas MCP del estudio](estudio-api-mcp.md). Un agente puede crear y exportar sin abrir el editor; los trabajos de render necesitan Node, Chromium y el worker de `media` en el servidor.
