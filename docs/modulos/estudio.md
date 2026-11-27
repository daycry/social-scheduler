# Estudio: editor, colaboración, tipografías, audio, datasets y exportaciones

Estudio crea diseños gráficos, tablas, presentaciones y vídeo por marca y los reutiliza en publicaciones. Esta página es el mapa del módulo; el detalle de la API y el MCP está en la [guía de API y MCP](../guias/estudio-api-mcp.md) y el uso paso a paso en la [guía del editor](../guias/estudio-creativo.md). Todo está probado en local con simulaciones; el despliegue del VPS sigue pendiente (CS-M-07 y CS-M-08 en [pendientes manuales](../referencia/pendientes-manuales.md)).

Mientras abre el editor, el Estudio informa del progreso. El editor confirma «Diseño creado» cuando se abre. Si la apertura falla, el diseño sigue guardado y el foco pasa a «Abrir diseño» para reintentar con el mismo diseño. Si el servidor rechaza el formulario, el diálogo conserva los datos introducidos y muestra el error con el componente común de avisos.

## Piezas

| Pieza | Dónde |
|---|---|
| Rutas web | `routes/studio.php`, `studio-images.php`, `studio-audio.php`, `studio-captions.php`, `studio-collab.php`, `studio-datasets.php`, `studio-ai.php` |
| Rutas API v1 | `routes/studio-api.php` y sus ficheros `*-api.php` ([OpenAPI](../api/openapi.yaml)) |
| Validación | `app/Http/Requests/Studio/`: los FormRequests que comparten los controladores web y API (`ListDesignsRequest`, `FolderNameRequest`, `EditorRequest`...) |
| Servicios | `app/Services/Studio/` (`StudioService`, `StudioEditor` con los datos con que se abre un diseño en web y API, `StudioCollab`, `StudioRenderer`, `StudioAi`...) |
| Modelos | `app/Models/Studio/` |
| Jobs | `app/Jobs/Studio/` (`RenderStudioDesign`, `ProcessStudioImage`, `RunStudioAiJob` en la cola `studio-render`; `ProcessStudioAvPhase` en la cola `studio`) |
| Configuración | `config/studio.php` |
| MCP | `list_designs`, `get_design`, `create_design`, `update_design`, `manage_design`, `manage_design_folder`, `attach_design_asset`, `manage_brand_kit`, `export_design`, `manage_design_export`, `manage_design_captions`, `manage_design_collaboration`, `manage_studio_font`, `manage_studio_audio`, `manage_studio_dataset`, `process_studio_image` y `generate_studio_image` ([MCP](mcp.md)) |

## Editor

El editor reutiliza el plan de la línea de tiempo para su duración, el inicio de cada página y la división de clips. Los enlaces de las tablas se generan con las rutas web nombradas de Ziggy.

Cada diseño pertenece a una marca y a un usuario. El editor guarda un documento propio versionado (páginas, capas, grupos, formas, texto, imágenes, vídeo, tablas y gráficos) con control optimista por `revision`: un conflicto devuelve 409 y conserva el trabajo local. Parte de catorce plantillas originales y de plantillas propias con campos `{{campo}}`. El kit de marca (paleta, tipografías, logos, guía y voz) se aplica de forma trazable; también hay redimensionado y variantes. Formatos de documento: lienzos sociales, documentos A4, pizarras, presentaciones y gráficos.

Los filtros de diseños, audio, datasets y tipografías buscan texto literal con `whereLikeEscaped`. Los caracteres `%`, `_` y `!` se pueden buscar como parte de un nombre.

Los diálogos de Studio usan el componente modal nativo compartido. Tab y Mayús+Tab recorren los controles del diálogo activo. Escape cierra el diálogo cuando la acción lo permite. Al cerrar, el foco vuelve al control que lo abrió si sigue disponible. Si hay dos diálogos abiertos, el teclado actúa sobre el que está encima.

## Diseños y aprobadores de marca

Un aprobador invitado ve los diseños activos de esa marca en **Compartidos conmigo**. Puede abrirlos, comentar y
decidir en **Aprobaciones**. Para editar necesita una invitación específica al diseño con el rol de editor.
El propietario conserva la gestión de accesos y puede decidir sus propias solicitudes, como en publicaciones.
Si una persona reúne los permisos de editor y aprobador, puede editar y decidir. Un aprobador puede resolver sus propios hilos.
Al eliminar el comentario inicial de un hilo se eliminan también sus respuestas, igual que con el rol de comentarista.

Desde el menú del diseño, el propietario elige **Solicitar aprobación**. La solicitud aparece junto a las de
publicaciones y se registra un aviso para los aprobadores. Cada decisión corresponde a una revisión. Si el diseño cambia,
el propietario solicita aprobar la revisión actual. Las decisiones sobre una revisión anterior conservan el comentario
y se muestran con su revisión en **Tus decisiones recientes** hasta que se abra otra solicitud. Las decisiones de
diseño se hacen en la bandeja web. Las acciones REST y MCP de aprobación conservan su contrato de publicaciones.

## Miniaturas del listado (T-35)

El listado muestra el total de diseños que coinciden con los filtros actuales. Al pasar de página conserva la búsqueda, la carpeta y las opciones de plantillas y papelera.

`QueryCountTest` mide las consultas del listado al pasar de 2 a 16 diseños con miniaturas listas y pendientes. Usa una caché en memoria y simula la cola de renderizado, cuyo almacenamiento puede generar consultas propias. Fuera de producción, Eloquent detecta la carga implícita de relaciones en colecciones.

Cada diseño del listado muestra su primera página reducida (480 px en el lado mayor, JPEG). `StudioThumbnails` la genera **bajo demanda**: al listar, un diseño sin miniatura vigente encola `RenderStudioThumbnail` en la cola `studio` (único por diseño, como mucho 12 por listado) y el listado enseña el hueco con el nombre hasta que está lista (cada fila trae `thumbnail_status`: `ready`, `pending`, `failed` u `off`, y solo se recarga unas pocas veces mientras haya alguna `pending`). La miniatura se **cachea por revisión** (`studio_designs.thumbnail_path` y `thumbnail_revision`) y editar la **invalida** sin renderizar nada: el autoguardado no espera a Chromium. Generarla no cambia `revision` ni `updated_at`, un render fallido (o un job que el worker mata por tiempo: `failed()`) aplaza esa revisión 10 minutos y la anterior se borra al regenerar. Al borrar la cuenta se borran del disco las miniaturas y las exportaciones renderizadas del usuario. Se sirve con una URL firmada temporal (`studio/thumbnails/{design}/{owner}`, misma política que las descargas). `STUDIO_THUMBNAILS=false` las desactiva (sin Chromium en el servidor) y la papelera no las genera. Requiere el worker de `studio` y Chromium (CS-M-08).

## Carpetas (T-33)

Cada marca tiene sus carpetas de diseños. En `/studio`, «Gestionar carpetas» (diálogo común del estudio, accesible con teclado) permite renombrarlas y borrarlas, y el menú de cada tarjeta ofrece «Mover a carpeta» (también «Sin carpeta»). Mover es organización, no contenido: no crea revisión y la carpeta solo cambia con su endpoint: **ningún guardado cambia la carpeta** (el editor ya no la envía y el servidor ignora `folder_id` al actualizar), así que guardar o exportar con el editor abierto no deshace un «Mover a carpeta» ni falla si la carpeta se borró. En el diálogo, Esc dentro del campo de renombrar cancela solo el renombrado, el foco va al campo al empezar y vuelve al botón al terminar. **Política al borrar:** los diseños de una carpeta borrada (activos, plantillas y archivados) no se borran ni piden destino: pasan a «Sin carpeta», y la confirmación dice cuántos. Solo el propietario administra carpetas; un recurso ajeno o de otra marca responde 404 o 422 igual que uno inexistente.

| Superficie | Renombrar | Mover diseño | Borrar |
|---|---|---|---|
| Web | `PATCH /studio/folders/{folder}` | `PATCH /studio/designs/{design}/folder` | `DELETE /studio/folders/{folder}` |
| API (`designs:write`) | `PATCH /api/v1/design-folders/{folder}` | `PATCH /api/v1/designs/{design}/folder` | `DELETE /api/v1/design-folders/{folder}` |
| MCP `manage_design_folder` | `rename` | `move` (sin `folder_id` = «Sin carpeta») | `delete` |

El listado de carpetas incluye `designs_count` (diseños activos) y el borrado devuelve `designs_moved`.

## Colaboración (T-13)

En las pestañas de colaboración, las flechas mueven el foco y cambian de panel. Inicio y Fin llevan a la primera y la última pestaña. Tab entra en la pestaña seleccionada.

Roles `reader`, `commenter` y `editor` por invitación por email a un usuario registrado, o enlaces de lectura o comentario con token revocable (SHA-256 en base de datos, caducidad opcional de hasta un año). Comentarios en hilo, historial de versiones con restauración y copia propia. No hay edición en tiempo real: un conflicto se resuelve recargando o guardando como copia. Los invitados no exportan ni aplican el kit; solo el propietario. Sin acceso la respuesta es 404; con acceso insuficiente, 403. API: `/api/v1/designs/{design}/shares|links|comments|versions|copies`; MCP: `manage_design_collaboration`.

## Tipografías (T-10)

Se suben TTF, OTF, WOFF o WOFF2 de hasta 8 MiB a una marca (`design-fonts`, `manage_studio_font`) y se normalizan con fontTools. Quedan en el disco `STUDIO_FONT_DISK` (por defecto `local`) y se pueden archivar, restaurar o borrar (si ningún diseño las referencia). El render usa también las fuentes instaladas en el servidor; escribir un nombre de familia no la instala.

Al borrar una tipografía, la transacción comprueba las referencias y elimina la fila. El almacenamiento borra los bytes tras confirmar la transacción externa. Si el disco falla, la cola `media` reintenta la limpieza. Un rollback conserva la fila y su archivo.

La eliminación de fondo guarda y valida la imagen generada antes de bloquear filas de base de datos. La transacción registra la media, su vínculo con la marca y el estado del trabajo juntos. Si falla el registro, el worker limpia el objeto preparado.

## Audio y vídeo (T-11)

Las pistas de audio son recursos privados por marca: se sube con un ticket (PUT binario local o URL prefirmada, hasta 100 MiB), se sondea (duración y códec) y solo entonces se usa en `timeline.audio_tracks`. Los vídeos del lienzo se recortan, hay mezcla y subtítulos manuales (SRT y WebVTT, hasta 5000 cues). Las exportaciones MP4 y GIF se renderizan con Node, FFmpeg y Chromium en la cola **`studio`**, con su propio worker y límites de memoria ([despliegue](despliegue.md#worker-de-la-cola-studio-mp4-y-gif)). No hay voz sintética; la traducción de subtítulos usa el asistente de IA (ver más abajo).

La recuperación audiovisual recorre las copias en retirada, los espacios huérfanos, los trabajos terminales y los pendientes en fases acotadas por un mismo plazo. Las reservas de disco se comprueban en `StudioAvStore` con el espacio de trabajo bloqueado dentro de la transacción. El registro de la copia final usa la misma contabilidad que el render.

Los ocho casos de Pest que renderizan de verdad (Node + FFmpeg + Chromium) se omiten salvo con `STUDIO_AV_NATIVE_TEST=1`; el job nocturno `native-av` los ejecuta cada noche y en local se lanzan con `STUDIO_AV_NATIVE_TEST=1 php artisan test --filter=StudioAv` (ver [guía de testing](../guias/testing.md)).

## Datasets y gráficos (T-12)

Tablas privadas versionadas por marca (`datasets`, `manage_studio_dataset`): hasta 1000 filas y 100 columnas, importación de CSV/TSV (25 MB) o JSON tipado (52 MB), revisiones inmutables y exportación por enlace privado con bearer. Alimentan gráficos del lienzo y una vista de presentación con herramientas de audiencia. XLSX y las fuentes conectadas no están implementados.

## Exportaciones

El editor usa todo el ancho disponible del área autenticada. El historial de edición, el guardado de revisiones y la reproducción de la cronología tienen estados separados. El editor carga cada diálogo cuando se abre por primera vez. La exportación en el navegador carga `pdf-lib` al generar un PDF. Las descargas de subtítulos usan un módulo independiente. La cabecera del editor delega sus acciones a `EditorToolbar`. `usePresentation` conserva las notas y prepara la vista de presentación tras detener la reproducción y guardar la revisión. `useChartFlow` coordina los diálogos de datos y gráficos y libera la caché de tablas cuando cambia el diseño. `useEditorCanvas` controla el lienzo y la
cronología. `useEditorDialogs` monta y cierra los diálogos diferidos. `useEditorDesignOperations` reúne el guardado, la
restauración y las variantes del diseño. `useEditorLibrary` encola las selecciones de archivos mientras se ocupa el lienzo,
las sube en orden y conserva por separado los intentos de audio recuperables. Si cambia la marca o el diseño,
la vista se desactiva y pide volver a abrir el diseño. El editor anterior deja de aceptar cambios y de enviar guardados.

| Formato | Cómo | Cola |
|---|---|---|
| PNG, JPEG, WebP, SVG | Render con Chromium; varias páginas en ZIP | `media` |
| PDF | Páginas rasterizadas (no es un PDF vectorial editable) | `media` |
| PPTX | Una diapositiva por página con la imagen renderizada (T-35); el texto **no** es editable | `media` |
| MP4, GIF | Timeline audiovisual, trabajos reanudables | `studio` |

**Render estático.** `StudioRenderer` coordina la revisión, el render y el estado del trabajo. `StudioRenderResources` reúne los recursos privados de las páginas seleccionadas. `StudioExportFiles` valida, empaqueta y limpia los archivos de cada intento.

**PowerPoint (T-35).** `format: "pptx"` (diálogo de exportación, API `POST designs/{id}/exports`, MCP `export_design`) renderiza cada página como PNG con el mismo Chromium, las empaqueta en un `.pptx` y borra los PNG: el resultado se descarga desde la lista de exportaciones y **no** entra en la biblioteca ni admite «Crear borrador». Cada página es una diapositiva (1 px = 9525 EMU) y su tamaño respeta el rango de PowerPoint (de 1 a 56 pulgadas por lado): un diseño demasiado grande se reduce y uno demasiado estrecho se centra con bandas, sin deformar la imagen. Los caracteres de control de los nombres se eliminan al guardar el diseño y al escribir el XML. **Decisión: texto no editable.** Las capas del estudio (grupos, tipografías propias, gráficos, efectos) no tienen un equivalente fiel en las formas de PowerPoint; convertirlas daría un resultado distinto al diseño, así que se prefiere la imagen fiel. `StudioPptxExporter` genera el OOXML a mano y `App\Support\Zip\ZipWriter` (el único escritor ZIP de la aplicación, también usado por la exportación de datos) lo empaqueta sin `ext-zip` ni dependencias nuevas (no se añadió `phpoffice/phppresentation`). Pruebas: `StudioPptxExportTest` abre el paquete con un lector ZIP/OOXML propio (firmas, CRC, XML bien formado y relaciones). **Abrirlo en PowerPoint y en Keynote o Google Slides es el manual M-11.**

Cada exportación queda vinculada a la revisión guardada. **Guardar en biblioteca** sube los ficheros validados y **Usar en publicación** crea un borrador de la marca con ellos (nunca publica solo). Quitar fondo usa un modelo local (IS-Net `isnet-general-use`, Apache-2.0) con Python; no se descarga nada durante una petición. **Calidad y límites:** IS-Net recorta bien sujetos destacados (personas, productos, animales) sobre fondos distintos; es menos fino en bordes complejos (pelo, transparencias, sujetos del mismo color que el fondo), a cambio de necesitar ~1 GiB de RAM y tardar 1–3 s en CPU por imagen. La imagen se reduce a 1024×1024 para inferir y la máscara se reescala al tamaño original (máx. 16 millones de píxeles y 30 MiB, PNG/JPEG/WebP estáticos). Requisitos de servidor y paso de actualización en [despliegue](despliegue.md).

Un borrador creado con **Usar en publicación** conserva la exportación de origen aunque todavía no tenga destinos. **Editar en el Estudio** abre el diseño en la misma marca. En el diálogo de exportación, **Actualizar publicación** reemplaza los archivos procedentes del diseño, conserva los adjuntos independientes y vuelve al mismo post. El texto alternativo sigue a su página cuando se cambia el orden o la selección. Si una exportación de vídeo combinada impide conservar descripciones o portadas, el editor avisa para que se revisen los adjuntos en el compositor. Si la publicación ya salió o está en curso, el editor muestra un aviso y la exportación queda en la biblioteca. **Crear borrador con esta exportación** permite reutilizar el archivo ya preparado. Si se elimina la exportación, se conservan los archivos adjuntos y el compositor deja de mostrar el enlace al diseño. Un enlace antiguo sigue abriendo el diseño y el editor avisa si el vínculo caducó.

Las imágenes y los vídeos subidos desde el compositor aparecen en **Recursos** de la marca. Si el vínculo falla, el archivo sigue en el compositor y, cuando el error lo permite, el aviso ofrece un reintento durante la edición. **Abrir en el Estudio**, desde el compositor o una plantilla de la biblioteca, abre el diseño de esa imagen en una pestaña nueva. Pulsar de nuevo el mismo control abre ese diseño mientras siga disponible. Si se ha enviado a la papelera, vuelve a crear un diseño con la misma imagen.

**Historial de revisiones.** Los guardados explícitos («Guardar ahora», Ctrl+S, al salir del editor), restaurar y aplicar kit crean una revisión. Un autoguardado del mismo autor dentro del intervalo mínimo (`STUDIO_REVISION_MIN_INTERVAL`, 300 s) sustituye a la revisión de autoguardado anterior: la nueva conserva la fecha de la sustituida y la anterior se marca `superseded_at` (no aparece en el historial pero sigue sirviendo de base para exportar a quien esté parado sobre ella; por diseño y autor solo se conserva una sustituida, la siguiente sustitución borra la anterior, y la poda elimina la que quede pasados 15 min); nunca sustituye puntos de control explícitos, la revisión 1 ni una revisión fijada por una exportación no terminada, y los números no se reutilizan. `model:prune` conserva las últimas N revisiones de cada diseño y la última de cada día, y borra el resto (salvo las fijadas por exportaciones no terminadas) junto con sus usos de tipografía (una tipografía solo usada por historial podado se puede borrar). Si un cliente desfasado exporta o usa en publicación una revisión que ya no existe porque se fusionó o se podó, el servidor responde 409 («El diseño ha cambiado desde que lo abriste», con la revisión actual y el autor, como un conflicto de guardado, también por API/MCP) y el editor abre el diálogo de conflicto para recargar o guardar como copia.

## Marca de agua y exportación en lote (T-91)

**Marca de agua por marca.** El kit de marca (`studio_brand_kits.watermark`, JSON) guarda `enabled`, `logo_id` (uno de los logotipos del kit, PNG/JPEG/WebP de hasta 8 MB), `position` (`top-left`, `top-right`, `bottom-left`, `bottom-right`, `center`), `opacity` (5–100 %), `size` (5–50 % del ancho de la imagen) y `margin` (0–20 % del lado menor). Se edita en **Identidad de marca** (`PUT brands/{id}/studio/brand-kit`, MCP `manage_brand_kit`); un logotipo ajeno, inexistente o que no esté en los logotipos del kit se rechaza (422 con `watermark.logo_id`), y no se puede quitar del kit un logotipo que la marca de agua activa usa. Solo admite **logotipo**: no hay marca de agua de texto (haría falta una tipografía en el servidor).

**Dónde se aplica.** En las exportaciones PNG, JPEG y WebP que renderiza el servidor (`StudioRenderer`: API `POST designs/{id}/exports`, MCP `export_design` y el lote). La exportación pide `watermark` opcional: omitido manda la marca de la marca (se aplica si está activa), `false` la desactiva y `true` la fuerza aunque esté desactivada (422 si no hay logotipo o el formato es SVG, PDF, PPTX, MP4 o GIF). Los ajustes se **fijan en `options.watermark` al crear la exportación**, así que cambiar el kit después no altera un render ya encolado. `StudioWatermark` superpone el logotipo con GD sobre el fichero que dejó Chromium, antes de guardarlo en la biblioteca (no hay un segundo renderizador); el tope es `studio.watermark.max_pixels` (25 Mpx) y un fallo deja la exportación en `failed` sin ficheros huérfanos. **Diálogo Exportar.** Con la marca de agua activa, la exportación PNG/JPEG/WebP del diálogo (guardar en biblioteca, publicar, descargar) se prepara en el servidor con este mismo camino, conserva el selector de fotograma de los documentos animados y «Usar en publicación» abre el panel de exportación en segundo plano, donde se prepara la imagen y luego se pulsa «Crear borrador» (igual que con vídeo o PPTX); el diálogo indica si la imagen lleva marca y se puede desmarcar para esa exportación (entonces se renderiza en el navegador, sin marca). Con marca y JPEG/WebP, Chromium entrega un PNG sin pérdida y GD codifica una sola vez con la calidad pedida. Un JPEG con marca aplana la transparencia sobre blanco, como el render sin marca; WebP conserva el alfa. El tope de `studio.export.max_file_bytes` (64 MB) se mide sobre el fichero final, no sobre el PNG intermedio (acotado por `studio.watermark.max_pixels`).

**Exportación en lote.** **Exportar en lote** (editor, propietario) o `POST studio/designs/{design}/batch-export` (`StudioBatchExport`) crea **una exportación de imagen por formato** (hasta `studio.batch.max_formats`, 8): cuadrado, 4:5, 3:4, historia, horizontal… o cualquier tamaño entre 40 y 8000 px. Cada una guarda `options.fit` y se renderiza con el mismo `RenderStudioDesign`, en la cola `studio-render` (sin worker nuevo): el documento de la revisión se ajusta al lienzo con la misma lógica que **Cambiar tamaño** (`StudioAutomation::resize`, modos `contain`, `cover` y `canvas`) sin crear diseños ni revisiones. Cada fichero acaba en la biblioteca de la marca (`MediaAsset` + recurso del Estudio) con las dimensiones pedidas, y lleva la marca de agua si está activa. Con varias páginas, cada formato genera un fichero por página.

**Idempotencia y recuperación.** Cada exportación lleva una huella en `options.batch` (diseño, revisión, páginas, tipo de archivo, formato, modo, calidad y marca de agua). Repetir el lote (doble clic, reintento tras un corte) **reutiliza** las exportaciones en curso o listas cuyos ficheros siguen en la biblioteca y solo crea las que fallaron, se cancelaron o cuyo fichero se borró: no hay duplicados ni ficheros huérfanos (un fallo de render limpia lo que generó ese intento, igual que cualquier exportación). La creación es atómica: si un formato no valida, no se crea ninguno. Los lotes del mismo diseño se serializan con un bloqueo del diseño, y un usuario no puede tener más de `studio.batch.max_active` (24) exportaciones pendientes. La API responde 202 con `exports` (en el orden de los formatos pedidos), `created` y `reused`; el diálogo consulta el historial hasta que terminan y ofrece **Reintentar los fallidos**.

## Traducción de subtítulos (T-35)

En el panel de subtítulos, «Traducir subtítulos con IA» pide al **asistente de IA ya configurado** (`AiAssistant`, Claude: misma clave, modelo y cuota diaria) una propuesta de traducción. Está **desactivada sin clave**: la UI muestra el mismo aviso que el resto de textos con IA («Textos con IA: no configurado…») y no se envía nada. Los textos solo se envían cuando el usuario pulsa «Traducir con IA», como dato en un JSON; son hasta 100 subtítulos y 12.000 caracteres por petición (el panel los trocea). Correos, enlaces y teléfonos **no salen del servidor**: `StudioCaptionMasker` los sustituye por marcadores numerados (`⟦1⟧`), guarda el mapa solo durante la petición y los restaura en la traducción; si la IA pierde o inventa un marcador, la respuesta se rechaza. La detección de teléfonos pide de 9 a 15 cifras y deja pasar cifras con separador de millares, años y rangos. El `max_tokens` cubre el JSON de salida de cada subtítulo y una respuesta cortada o inválida **no consume cuota** y se explica. La respuesta se valida con un esquema estricto (mismos ids, mismo orden, texto de hasta 500 caracteres) y es una **propuesta** que se muestra entera (con scroll): el usuario la revisa y elige «Aplicar traducción» (cambia solo los textos; los tiempos no se tocan), descargarla como SRT/VTT o descartarla. Entrada: `StudioCaptions::translate()` vía la acción `translate_captions` de `POST brands/{brand}/studio/ai-text` (web y API v1, `designs:ai`); el MCP no la expone. Sigue fuera el reconocimiento de voz (ASR), a la espera de elegir proveedor.

## Más módulos del estudio

- [IA del estudio](estudio-ia.md): generar y editar imágenes con OpenAI y Google, cuotas.
- [Canva](canva.md): conectar, listar, exportar y enviar.
- [Sitios](sitios.md): webs de varias páginas en `/s/{slug}`.
