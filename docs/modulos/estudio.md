# Estudio: editor, colaboración, tipografías, audio, datasets y exportaciones

Estudio crea diseños gráficos, tablas, presentaciones y vídeo por marca y los reutiliza en publicaciones. Esta página es el mapa del módulo; el detalle de la API y el MCP está en la [guía de API y MCP](../guias/estudio-api-mcp.md) y el uso paso a paso en la [guía del editor](../guias/estudio-creativo.md). Todo está probado en local con simulaciones; el despliegue del VPS sigue pendiente (CS-M-07 y CS-M-08 en [pendientes manuales](../referencia/pendientes-manuales.md)).

## Piezas

| Pieza | Dónde |
|---|---|
| Rutas web | `routes/studio.php`, `studio-images.php`, `studio-audio.php`, `studio-captions.php`, `studio-collab.php`, `studio-datasets.php`, `studio-ai.php` |
| Rutas API v1 | `routes/studio-api.php` y sus ficheros `*-api.php` ([OpenAPI](../api/openapi.yaml)) |
| Servicios | `app/Services/Studio/` (`StudioService`, `StudioCollab`, `StudioRenderer`, `StudioAi`...) |
| Modelos | `app/Models/Studio/` |
| Jobs | `app/Jobs/Studio/` (`RenderStudioDesign`, `ProcessStudioImage`, `RunStudioAiJob` en la cola `media`; `ProcessStudioAvPhase` en la cola `studio`) |
| Configuración | `config/studio.php` |
| MCP | `list_designs`, `get_design`, `create_design`, `update_design`, `manage_design`, `manage_design_folder`, `attach_design_asset`, `manage_brand_kit`, `export_design`, `manage_design_export`, `manage_design_captions`, `manage_design_collaboration`, `manage_studio_font`, `manage_studio_audio`, `manage_studio_dataset`, `process_studio_image` y `generate_studio_image` ([MCP](mcp.md)) |

## Editor

Cada diseño pertenece a una marca y a un usuario. El editor guarda un documento propio versionado (páginas, capas, grupos, formas, texto, imágenes, vídeo, tablas y gráficos) con control optimista por `revision`: un conflicto devuelve 409 y conserva el trabajo local. Parte de catorce plantillas originales y de plantillas propias con campos `{{campo}}`. El kit de marca (paleta, tipografías, logos, guía y voz) se aplica de forma trazable; también hay redimensionado y variantes. Formatos de documento: lienzos sociales, documentos A4, pizarras, presentaciones y gráficos.

## Miniaturas del listado (T-35)

Cada diseño del listado muestra su primera página reducida (480 px en el lado mayor, JPEG). `StudioThumbnails` la genera **bajo demanda**: al listar, un diseño sin miniatura vigente encola `RenderStudioThumbnail` en la cola `studio` (baja prioridad y su propio worker: no retrasa las importaciones de `media`; único por diseño, como mucho 12 por listado) y el listado enseña el hueco con el nombre hasta que está lista (cada fila trae `thumbnail_status`: `ready`, `pending`, `failed` u `off`, y solo se recarga unas pocas veces mientras haya alguna `pending`). La miniatura se **cachea por revisión** (`studio_designs.thumbnail_path` y `thumbnail_revision`) y editar la **invalida** sin renderizar nada: el autoguardado no espera a Chromium. Generarla no cambia `revision` ni `updated_at`, un render fallido (o un job que el worker mata por tiempo: `failed()`) aplaza esa revisión 10 minutos y la anterior se borra al regenerar. Al borrar la cuenta se borran del disco las miniaturas y las exportaciones renderizadas del usuario. Se sirve con una URL firmada temporal (`studio/thumbnails/{design}/{owner}`, misma política que las descargas). `STUDIO_THUMBNAILS=false` las desactiva (sin Chromium en el servidor) y la papelera no las genera. Requiere el worker de la cola `studio` y Chromium (CS-M-08).

## Carpetas (T-33)

Cada marca tiene sus carpetas de diseños. En `/studio`, «Gestionar carpetas» (diálogo común del estudio, accesible con teclado) permite renombrarlas y borrarlas, y el menú de cada tarjeta ofrece «Mover a carpeta» (también «Sin carpeta»). Mover es organización, no contenido: no crea revisión y la carpeta solo cambia con su endpoint: **ningún guardado cambia la carpeta** (el editor ya no la envía y el servidor ignora `folder_id` al actualizar), así que guardar o exportar con el editor abierto no deshace un «Mover a carpeta» ni falla si la carpeta se borró. En el diálogo, Esc dentro del campo de renombrar cancela solo el renombrado, el foco va al campo al empezar y vuelve al botón al terminar. **Política al borrar:** los diseños de una carpeta borrada (activos, plantillas y archivados) no se borran ni piden destino: pasan a «Sin carpeta», y la confirmación dice cuántos. Solo el propietario administra carpetas; un recurso ajeno o de otra marca responde 404 o 422 igual que uno inexistente.

| Superficie | Renombrar | Mover diseño | Borrar |
|---|---|---|---|
| Web | `PATCH /studio/folders/{folder}` | `PATCH /studio/designs/{design}/folder` | `DELETE /studio/folders/{folder}` |
| API (`designs:write`) | `PATCH /api/v1/design-folders/{folder}` | `PATCH /api/v1/designs/{design}/folder` | `DELETE /api/v1/design-folders/{folder}` |
| MCP `manage_design_folder` | `rename` | `move` (sin `folder_id` = «Sin carpeta») | `delete` |

El listado de carpetas incluye `designs_count` (diseños activos) y el borrado devuelve `designs_moved`.

## Colaboración (T-13)

Roles `reader`, `commenter` y `editor` por invitación por email a un usuario registrado, o enlaces de lectura o comentario con token revocable (SHA-256 en base de datos, caducidad opcional de hasta un año). Comentarios en hilo, historial de versiones con restauración y copia propia. No hay edición en tiempo real: un conflicto se resuelve recargando o guardando como copia. Los invitados no exportan ni aplican el kit; solo el propietario. Sin acceso la respuesta es 404; con acceso insuficiente, 403. API: `/api/v1/designs/{design}/shares|links|comments|versions|copies`; MCP: `manage_design_collaboration`.

## Tipografías (T-10)

Se suben TTF, OTF, WOFF o WOFF2 de hasta 8 MiB a una marca (`design-fonts`, `manage_studio_font`) y se normalizan con fontTools. Quedan en el disco `STUDIO_FONT_DISK` (por defecto `local`) y se pueden archivar, restaurar o borrar (si ningún diseño las referencia). El render usa también las fuentes instaladas en el servidor; escribir un nombre de familia no la instala.

## Audio y vídeo (T-11)

Las pistas de audio son recursos privados por marca: se sube con un ticket (PUT binario local o URL prefirmada, hasta 100 MiB), se sondea (duración y códec) y solo entonces se usa en `timeline.audio_tracks`. Los vídeos del lienzo se recortan, hay mezcla y subtítulos manuales (SRT y WebVTT, hasta 5000 cues). Las exportaciones MP4 y GIF se renderizan con Node, FFmpeg y Chromium en la cola **`studio`**, con su propio worker y límites de memoria ([despliegue](despliegue.md#worker-de-la-cola-studio-mp4-y-gif)). No hay voz sintética; la traducción de subtítulos usa el asistente de IA (ver más abajo).

Los ocho casos de Pest que renderizan de verdad (Node + FFmpeg + Chromium) se omiten salvo con `STUDIO_AV_NATIVE_TEST=1`; el job nocturno `native-av` los ejecuta cada noche y en local se lanzan con `STUDIO_AV_NATIVE_TEST=1 php artisan test --filter=StudioAv` (ver [guía de testing](../guias/testing.md)).

## Datasets y gráficos (T-12)

Tablas privadas versionadas por marca (`datasets`, `manage_studio_dataset`): hasta 1000 filas y 100 columnas, importación de CSV/TSV (25 MB) o JSON tipado (52 MB), revisiones inmutables y exportación por enlace privado con bearer. Alimentan gráficos del lienzo y una vista de presentación con herramientas de audiencia. XLSX y las fuentes conectadas no están implementados.

## Exportaciones

| Formato | Cómo | Cola |
|---|---|---|
| PNG, JPEG, WebP, SVG | Render con Chromium; varias páginas en ZIP | `media` |
| PDF | Páginas rasterizadas (no es un PDF vectorial editable) | `media` |
| PPTX | Una diapositiva por página con la imagen renderizada (T-35); el texto **no** es editable | `media` |
| MP4, GIF | Timeline audiovisual, trabajos reanudables | `studio` |

**PowerPoint (T-35).** `format: "pptx"` (diálogo de exportación, API `POST designs/{id}/exports`, MCP `export_design`) renderiza cada página como PNG con el mismo Chromium, las empaqueta en un `.pptx` y borra los PNG: el resultado se descarga desde la lista de exportaciones y **no** entra en la biblioteca ni admite «Crear borrador». Cada página es una diapositiva (1 px = 9525 EMU) y su tamaño respeta el rango de PowerPoint (de 1 a 56 pulgadas por lado): un diseño demasiado grande se reduce y uno demasiado estrecho se centra con bandas, sin deformar la imagen. Los caracteres de control de los nombres se eliminan al guardar el diseño y al escribir el XML. **Decisión: texto no editable.** Las capas del estudio (grupos, tipografías propias, gráficos, efectos) no tienen un equivalente fiel en las formas de PowerPoint; convertirlas daría un resultado distinto al diseño, así que se prefiere la imagen fiel. `StudioPptxExporter` genera el OOXML a mano y `StudioZipWriter` lo empaqueta sin `ext-zip` ni dependencias nuevas (no se añadió `phpoffice/phppresentation`). Pruebas: `StudioPptxExportTest` abre el paquete con un lector ZIP/OOXML propio (firmas, CRC, XML bien formado y relaciones). **Abrirlo en PowerPoint y en Keynote o Google Slides es el manual M-11.**

Cada exportación queda vinculada a la revisión guardada. **Guardar en biblioteca** sube los ficheros validados y **Usar en publicación** crea un borrador de la marca con ellos (nunca publica solo). Quitar fondo usa un modelo local (IS-Net `isnet-general-use`, Apache-2.0) con Python; no se descarga nada durante una petición. **Calidad y límites:** IS-Net recorta bien sujetos destacados (personas, productos, animales) sobre fondos distintos; es menos fino en bordes complejos (pelo, transparencias, sujetos del mismo color que el fondo), a cambio de necesitar ~1 GiB de RAM y tardar 1–3 s en CPU por imagen. La imagen se reduce a 1024×1024 para inferir y la máscara se reescala al tamaño original (máx. 16 millones de píxeles y 30 MiB, PNG/JPEG/WebP estáticos). Requisitos de servidor y paso de actualización en [despliegue](despliegue.md).

**Historial de revisiones.** Los guardados explícitos («Guardar ahora», Ctrl+S, al salir del editor), restaurar y aplicar kit crean una revisión. Un autoguardado del mismo autor dentro del intervalo mínimo (`STUDIO_REVISION_MIN_INTERVAL`, 300 s) sustituye a la revisión de autoguardado anterior: la nueva conserva la fecha de la sustituida y la anterior se marca `superseded_at` (no aparece en el historial pero sigue sirviendo de base para exportar a quien esté parado sobre ella; por diseño y autor solo se conserva una sustituida, la siguiente sustitución borra la anterior, y la poda elimina la que quede pasados 15 min); nunca sustituye puntos de control explícitos, la revisión 1 ni una revisión fijada por una exportación no terminada, y los números no se reutilizan. `model:prune` conserva las últimas N revisiones de cada diseño y la última de cada día, y borra el resto (salvo las fijadas por exportaciones no terminadas) junto con sus usos de tipografía (una tipografía solo usada por historial podado se puede borrar). Si un cliente desfasado exporta o usa en publicación una revisión que ya no existe porque se fusionó o se podó, el servidor responde 409 («El diseño ha cambiado desde que lo abriste», con la revisión actual y el autor, como un conflicto de guardado, también por API/MCP) y el editor abre el diálogo de conflicto para recargar o guardar como copia.

## Traducción de subtítulos (T-35)

En el panel de subtítulos, «Traducir subtítulos con IA» pide al **asistente de IA ya configurado** (`AiAssistant`, Claude: misma clave, modelo y cuota diaria) una propuesta de traducción. Está **desactivada sin clave**: la UI muestra el mismo aviso que el resto de textos con IA («Textos con IA: no configurado…») y no se envía nada. Los textos solo se envían cuando el usuario pulsa «Traducir con IA», como dato en un JSON; son hasta 100 subtítulos y 12.000 caracteres por petición (el panel los trocea). Correos, enlaces y teléfonos **no salen del servidor**: `StudioCaptionMasker` los sustituye por marcadores numerados (`⟦1⟧`), guarda el mapa solo durante la petición y los restaura en la traducción; si la IA pierde o inventa un marcador, la respuesta se rechaza. La detección de teléfonos pide de 9 a 15 cifras y deja pasar cifras con separador de millares, años y rangos. El `max_tokens` cubre el JSON de salida de cada subtítulo y una respuesta cortada o inválida **no consume cuota** y se explica. La respuesta se valida con un esquema estricto (mismos ids, mismo orden, texto de hasta 500 caracteres) y es una **propuesta** que se muestra entera (con scroll): el usuario la revisa y elige «Aplicar traducción» (cambia solo los textos; los tiempos no se tocan), descargarla como SRT/VTT o descartarla. Entrada: `StudioCaptions::translate()` vía la acción `translate_captions` de `POST brands/{brand}/studio/ai-text` (web y API v1, `studio:ai`); el MCP no la expone. Sigue fuera el reconocimiento de voz (ASR), a la espera de elegir proveedor.

## Más módulos del estudio

- [IA del estudio](estudio-ia.md): generar y editar imágenes con OpenAI y Google, cuotas.
- [Canva](canva.md): conectar, listar, exportar y enviar.
- [Sitios](sitios.md): webs de varias páginas en `/s/{slug}`.
