# Matriz inicial del compositor

Fecha: 2026-10-31. Base inspeccionada: `8fe2f230853d6edc46667a61d08d23d0110edf5d`.

Actualizado al cierre local de audio: MUS-01–06 reflejan la implementación actual; las demás filas conservan el inventario inicial. Inventario abierto: estas filas organizan el alcance, no acreditan una lista exhaustiva ni paridad completada. «Existente» indica presencia de implementación, no un smoke real aprobado. El desglose completo por opción y red sigue pendiente.

| ID | Función o bloque | Estado local | Evidencia o trabajo pendiente |
|---|---|---|---|
| MUS-01 | Audio nativo en Instagram Reels | Implementado localmente / smoke pendiente | `audio_configuration`, User token y volúmenes validados; [QA final](testing/report.md). |
| MUS-02 | Búsqueda, identificación y selección de pista desde MCP | Implementado localmente / smoke pendiente | `search_instagram_audio`, `get_instagram_audio` y selección por destino en crear/editar; 27 herramientas. |
| MUS-03 | Audio original de otro creador | Implementado según catálogo autorizado / smoke pendiente | Filtro `original_sound` y metadatos; URL de Reel no aceptada como ID. Sin descarga/mezcla sustitutiva. |
| MUS-04 | Persistencia y lectura del audio por destino | Implementado localmente / smoke pendiente | Guardar, editar, reabrir, quitar y limpiar al cambiar formato probados por web/MCP. |
| MUS-05 | Música en Facebook | Audio del vídeo; catálogo no implementado | Guía oficial de recomendaciones consultada, asociación de pista al publicar no confirmada. El Reel de esta cuenta en Metricool no mostró selector. |
| MUS-06 | Música en TikTok | Música recomendada de fotos implementada / smoke pendiente | `auto_add_music` en Direct Post; TikTok elige la pista. Selector Business de canción concreta permanece pendiente. |
| CMP-01 | Texto común y adaptado por destino | Existente | `PostService`, `PostRequest`, `Composer/TargetOptions.vue`; pendiente contraste de detalles. |
| CMP-02 | Media y metadatos independientes por destino | Parcial | Media compartida en `Composer/MediaSection.vue`; falta herencia/desvinculación completa. |
| CMP-03 | Emojis, textos guardados, hashtags, UTM e IA | Existente / ampliar inventario | `Composer/EditorToolbar.vue`; distinguir historial de hashtags de catálogo de referencia. |
| CMP-04 | Previsualización por red y dispositivo | Parcial | `Composer/PreviewPanel.vue` ofrece una vista genérica. |
| MED-01 | Subida e importación de media | Existente / ampliar inventario | `Composer/MediaSection.vue`: subida, URL, Drive y Giphy; otras fuentes por contrastar. |
| MED-02 | Edición de imagen y vídeo | Falta ampliar | Desglosar herramientas y transformaciones del editor de referencia. |
| MED-03 | Alt text, portadas y selección de fotograma | Falta ampliar | Inventariar por red y formato; no confundir portada de vídeo con primera foto. |
| ACT-01 | Borrador, biblioteca, programar y publicar ahora | Existente | `Composer/ActionBar.vue`; conservar idempotencia del envío. |
| ACT-02 | Recurrencia y programación entre marcas | Falta ampliar | Desglosar fechas, zonas, vista previa y selección de destinos. |
| ACT-03 | Revisión y aprobación | Falta | Definir política y bloqueo del worker sobre versión no aprobada. |
| ACT-04 | Publicación mediante aviso manual | Falta ampliar | Un aviso no equivale al estado publicado. |
| NET-IG | Todas las opciones de Instagram | Parcial | Tipos básicos implementados; faltan opciones avanzadas y contraste fila por fila. |
| NET-FB | Todas las opciones de Facebook | Parcial | Tipos básicos implementados; Stories y opciones avanzadas por ampliar. |
| NET-TT | Todas las opciones de TikTok | Parcial | Presets y música recomendada de fotos Direct Post existentes; catálogo Business concreto, portada y restantes diferencias por verificar. |
| NET-YT | Todas las opciones de YouTube | Parcial | Presets existentes; portada y selección de playlists por ampliar. |
| NET-BS | Todas las opciones de Bluesky | Parcial | Conector existente; contrastar hilos, idiomas, alt y menciones. |
| NET-TH | Threads y opciones avanzadas | Implementado / sin configurar localmente | Falta App ID/secreto local; inventariar respuestas, hilos y demás opciones. |
| NET-X | Twitter/X | Falta | Nuevo conector, capacidades de cuenta, formatos y opciones específicas. |
| MCP-01 | Paridad web/MCP de nuevas opciones | Parcial | Infraestructura existente; incorporar capacidades y validación de cada ampliación. |

Conectores existentes: Instagram, Facebook, TikTok, YouTube, Bluesky y Threads (este último sin configurar localmente). X sigue solicitado en el backlog, sin conector implementado. LinkedIn, Pinterest y Google Business permanecen como referencia de inventario, sin conector nuevo aprobado en esta selección.

El ejemplo aportado por el usuario se inspeccionó en sus pestañas abiertas: el adjunto de Metricool contiene audio y la UI del post no muestra una pista seleccionada; Instagram enlaza «Botón automático». La [investigación](discovery.md#resultado-de-la-inspección-real-del-ejemplo) distingue estos hechos del posible reconocimiento automático. No acredita selección de música mediante MCP ni cierra filas de implementación o smokes de nuestra aplicación.

## Seguimiento de presets T-11/T-12

Paneles por red, colaboradores/IA de Instagram, título de Facebook y errores plegables implementados; QA local completada: 54 E2E y 2.511 tests PHP pasan; revisión A+B+D sin gaps. El smoke real sigue abierto. [Inventario visible y límites](../../guias/ajustes-compositor.md). Acortador, modo manual y automatización de SmartLinks quedan pendientes; no se declara paridad total.
