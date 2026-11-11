# Estudio: editor, colaboración, tipografías, audio, datasets y exportaciones

Estudio crea diseños gráficos, tablas, presentaciones y vídeo por marca y los reutiliza en publicaciones. Esta página es el mapa del módulo; el detalle de la API y el MCP está en la [guía de API y MCP](../guias/estudio-api-mcp.md) y el uso paso a paso en la [guía del editor](../guias/estudio-creativo.md). Todo está probado en local con simulaciones; el despliegue del VPS sigue pendiente (CS-M-07 y CS-M-08 en [pendientes manuales](../referencia/pendientes-manuales.md)).

## Piezas

| Pieza | Dónde |
|---|---|
| Rutas web | `routes/studio.php`, `studio-images.php`, `studio-audio.php`, `studio-captions.php`, `studio-collab.php`, `studio-datasets.php`, `studio-ai.php` |
| Rutas API v1 | `routes/studio-api.php` y sus ficheros `*-api.php` ([OpenAPI](../api/creative-suite.openapi.yaml)) |
| Servicios | `app/Services/Studio/` (`StudioService`, `StudioCollab`, `StudioRenderer`, `StudioAi`...) |
| Modelos | `app/Models/Studio/` |
| Jobs | `app/Jobs/Studio/` (`RenderStudioDesign`, `ProcessStudioImage`, `RunStudioAiJob` en la cola `media`; `ProcessStudioAvPhase` en la cola `studio`) |
| Configuración | `config/studio.php` |
| MCP | `list_designs`, `get_design`, `create_design`, `update_design`, `manage_design`, `manage_design_folder`, `attach_design_asset`, `manage_brand_kit`, `export_design`, `manage_design_export`, `manage_design_captions`, `manage_design_collaboration`, `manage_studio_font`, `manage_studio_audio`, `manage_studio_dataset`, `process_studio_image` y `generate_studio_image` ([MCP](mcp.md)) |

## Editor

Cada diseño pertenece a una marca y a un usuario. El editor guarda un documento propio versionado (páginas, capas, grupos, formas, texto, imágenes, vídeo, tablas y gráficos) con control optimista por `revision`: un conflicto devuelve 409 y conserva el trabajo local. Parte de catorce plantillas originales y de plantillas propias con campos `{{campo}}`. El kit de marca (paleta, tipografías, logos, guía y voz) se aplica de forma trazable; también hay redimensionado y variantes. Formatos de documento: lienzos sociales, documentos A4, pizarras, presentaciones y gráficos.

## Colaboración (T-13)

Roles `reader`, `commenter` y `editor` por invitación por email a un usuario registrado, o enlaces de lectura o comentario con token revocable (SHA-256 en base de datos, caducidad opcional de hasta un año). Comentarios en hilo, historial de versiones con restauración y copia propia. No hay edición en tiempo real: un conflicto se resuelve recargando o guardando como copia. Los invitados no exportan ni aplican el kit; solo el propietario. Sin acceso la respuesta es 404; con acceso insuficiente, 403. API: `/api/v1/designs/{design}/shares|links|comments|versions|copies`; MCP: `manage_design_collaboration`.

## Tipografías (T-10)

Se suben TTF, OTF, WOFF o WOFF2 de hasta 8 MiB a una marca (`design-fonts`, `manage_studio_font`) y se normalizan con fontTools. Quedan en el disco `STUDIO_FONT_DISK` (por defecto `local`) y se pueden archivar, restaurar o borrar (si ningún diseño las referencia). El render usa también las fuentes instaladas en el servidor; escribir un nombre de familia no la instala.

## Audio y vídeo (T-11)

Las pistas de audio son recursos privados por marca: se sube con un ticket (PUT binario local o URL prefirmada, hasta 100 MiB), se sondea (duración y códec) y solo entonces se usa en `timeline.audio_tracks`. Los vídeos del lienzo se recortan, hay mezcla y subtítulos manuales (SRT y WebVTT, hasta 5000 cues). Las exportaciones MP4 y GIF se renderizan con Node, FFmpeg y Chromium en la cola **`studio`**, con su propio worker y límites de memoria ([despliegue](despliegue.md#worker-de-la-cola-studio-mp4-y-gif)). No hay voz sintética ni traducción automática.

## Datasets y gráficos (T-12)

Tablas privadas versionadas por marca (`datasets`, `manage_studio_dataset`): hasta 1000 filas y 100 columnas, importación de CSV/TSV (25 MB) o JSON tipado (52 MB), revisiones inmutables y exportación por enlace privado con bearer. Alimentan gráficos del lienzo y una vista de presentación con herramientas de audiencia. XLSX y las fuentes conectadas no están implementados.

## Exportaciones

| Formato | Cómo | Cola |
|---|---|---|
| PNG, JPEG, WebP, SVG | Render con Chromium; varias páginas en ZIP | `media` |
| PDF | Páginas rasterizadas (no es un PDF vectorial editable) | `media` |
| MP4, GIF | Timeline audiovisual, trabajos reanudables | `studio` |

Cada exportación queda vinculada a la revisión guardada. **Guardar en biblioteca** sube los ficheros validados y **Usar en publicación** crea un borrador de la marca con ellos (nunca publica solo). Quitar fondo usa un modelo local (BiRefNet general lite) con Python; no se descarga nada durante una petición.

**Historial de revisiones.** Los guardados explícitos («Guardar ahora», Ctrl+S, al salir del editor), restaurar y aplicar kit crean una revisión. Un autoguardado del mismo autor dentro del intervalo mínimo (`STUDIO_REVISION_MIN_INTERVAL`, 300 s) sustituye a la revisión de autoguardado anterior: la nueva conserva la fecha de la sustituida y la anterior se marca `superseded_at` (no aparece en el historial pero sigue sirviendo de base para exportar a quien esté parado sobre ella; la poda la elimina pasadas 24 h); nunca sustituye puntos de control explícitos, la revisión 1 ni una revisión fijada por una exportación no terminada, y los números no se reutilizan. `model:prune` conserva las últimas N revisiones de cada diseño y la última de cada día, y borra el resto (salvo las fijadas por exportaciones no terminadas) junto con sus usos de tipografía (una tipografía solo usada por historial podado se puede borrar).

## Más módulos del estudio

- [IA del estudio](estudio-ia.md): generar y editar imágenes con OpenAI y Google, cuotas.
- [Canva](canva.md): conectar, listar, exportar y enviar.
- [Sitios](sitios.md): webs de varias páginas en `/s/{slug}`.
