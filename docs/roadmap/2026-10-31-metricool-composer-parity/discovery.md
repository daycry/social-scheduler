# Investigación de paridad del compositor

Fecha: 2026-10-31. Investigación inicial y evidencia del ejemplo. La entrega posterior se acota a audio de Instagram Reels; implementación y verificaciones en el [ledger](tasks.md). Las secciones sobre el código previo describen la base, no el estado después de implementar.

## Aclaración sobre música y MCP

El usuario usa el MCP de Metricool y observa música en sus publicaciones, pero aclara que desconoce cómo se añade. Aporta [esta publicación](https://www.instagram.com/p/DfHNz86ku9E/) y describe «reels de audio incrustados». El lector web no pudo cargarla inicialmente. Después autorizó inspeccionar su pestaña abierta y se comprobó el ejemplo mediante la UI de Edge. No debe interpretarse su observación como prueba de que el agente selecciona una pista de catálogo.

### Resultado de la inspección real del ejemplo

Comprobación: 2026-10-31, aproximadamente 22:17 Europe/Madrid. Se usó `computer-use` sobre la sesión ya abierta de Edge; el host de acceso directo a pestañas no estaba disponible.

- Instagram muestra el Reel `DfHNz86ku9E` y el enlace de audio «aipocalipsis · Botón automático», con ID visible `1436876720937897`.
- En Metricool → List se abrió exactamente el Reel del 30 de octubre a las 10:00, cuyo enlace publicado coincide con ese ID. El primer diálogo que estaba abierto correspondía a otro post del día 27 y se descartó para esta comprobación.
- Sus ajustes muestran **Audio of reel: Add audio**, sin una pista seleccionada. No se pulsó ese selector ni se modificaron campos.
- Se descargó el adjunto desde la vista de media de Metricool. El navegador lo guardó como `C:\Users\daycr\Downloads\dummy.mp4`, 2.533.162 bytes. SHA-256: `785d083893a515e26f2bc746005143d7f9b3b7e867210aa6507e950ff7fe19fc`. El vídeo no se incorpora al repositorio.
- `ffprobe` confirma vídeo H.264 de 15,5 s y una pista **AAC estéreo, 44.100 Hz, 15,487 s** dentro del MP4. `ffmpeg` con `volumedetect` confirma señal no silenciosa: media −15,5 dB y pico −2,3 dB.

**Conclusión comprobada:** el adjunto de Metricool ya contiene audio; no hay una canción seleccionada en el campo de audio visible de ese post. **Inferencia:** Instagram pudo reconocer la canción incluida y enlazarla con su catálogo. No se ha comprobado el mecanismo interno de reconocimiento ni el payload histórico del MCP, y no se ha realizado una comparación acústica entre el archivo y el catálogo. Este ejemplo no acredita que el agente añadiera música nativa mediante MCP.

Esto separa dos necesidades: publicar un vídeo que ya lleva sonido (sin nueva selección de catálogo) y permitir elegir una pista nativa por web/MCP (ampliación aún pendiente). La petición general de poder seleccionar música sigue en alcance; no se declara implementada por este resultado.

Hay dos resultados distintos que deben comprobarse:

| Resultado | Qué acredita |
|---|---|
| Archivo de vídeo con banda sonora | El archivo contiene sonido. No demuestra una asociación al catálogo musical de la red. |
| Pista nativa asociada a la publicación | La plataforma conserva la identidad del audio seleccionado. Este es el requisito cuando se pide escoger música de catálogo. |

El audio original de otro creador también puede ser una referencia seleccionable; eso no supone incrustar su Reel dentro del post. No se deduce el formato de la publicación aportada de su ruta `/p/`.

## Fuentes primarias consultadas

- [Audio para Instagram Reels en Metricool](https://help.metricool.com/es/como-anadir-audio-a-tus-instagram-reels-desde-metricool-2o2ks): confirma selección de música y audio original, publicación automática en Reels y Trial Reels, conexión mediante Facebook Login y catálogo autorizado para terceros. La guía excluye feed y Stories; no acredita soporte equivalente en Facebook.
- [Programar mediante el MCP de Metricool](https://help.metricool.com/es/programa-y-edita-publicaciones-mediante-un-llm-con-el-mcp-de-metricool-p8y4b): documenta creación y edición mediante agente y señala que algunos ajustes avanzados pueden requerir el planificador. No especifica el contrato de música de ese MCP.
- [Preguntas frecuentes del MCP de Metricool](https://help.metricool.com/es/preguntas-frecuentes-sobre-metricool-mcp-1i3w0): enumera redes y funciones, pero no ofrece un esquema de audio. Remite al repositorio `metricool/mcp-metricool`; la URL de GitHub devolvió 404 en esta comprobación. No se ha inspeccionado su código.
- [Meta: Audio API](https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api): el lector web devolvió HTTP 429 inicialmente. La consulta HTTP directa desde el host posterior devolvió 200 y permitió confirmar el contrato oficial antes de implementar: catálogo `ig_audio`, metadata por ID y JSON `audio_configuration`. Los parámetros no se han tomado de proyectos ajenos. El acceso real de nuestra aplicación sigue pendiente de smoke.

La documentación pública general del MCP no revela los campos históricos enviados por su agente. La inspección anterior sí acredita audio en el adjunto concreto y ausencia de selección en la UI actual del post.

## Estado de la aplicación antes de implementar esta entrega

- `app/Mcp/Tools/PostPayload.php` describe opciones de publicación, pero no ofrece selección ni búsqueda de audio.
- `app/Http/Requests/PostRequest.php` no declara campos de pista nativa.
- `app/Connectors/Meta/Instagram/InstagramPublisher.php` no tiene integración con el catálogo de audio. No basta con añadir un selector al compositor.
- No se ha acreditado aquí un contrato equivalente para música de Facebook. Se conserva como requisito a investigar por separado.

La cadena pendiente es: capacidades de cuenta → búsqueda autorizada → selección inequívoca → persistencia por destino → envío al publicador → comprobación de la pista final. Web y MCP deben compartir validación y datos.

## Threads en esta instalación

La comprobación local de Laravel mostró `threads_enabled=true`, App ID y secreto ausentes, y conector no registrado ni ofrecido. La configuración no estaba cacheada. Ngrok expone esta misma instalación y no añade esas credenciales. No se han registrado secretos en este documento ni se ha cambiado el `.env`.

## Incógnitas que siguen abiertas

1. Acceso y contrato oficial de audio de Meta para nuestra aplicación, tipos de cuenta y formatos.
2. Cómo identificar la pista exacta que quiere el usuario y qué referencias acepta cada proveedor.
3. Música de Facebook: asociación nativa, audio ya incluido o distribución de un Reel; no extrapolar desde Instagram.
4. Contrato efectivo de música del MCP de referencia, frente a la documentación general.
5. Inventario completo del compositor real y contraste con los límites oficiales de cada red. La [matriz inicial](feature-matrix.md) aún no cierra ese inventario.

TDD n/a: documentación. No se han ejecutado publicaciones, cambiado conexiones ni declarado superadas pruebas reales.
