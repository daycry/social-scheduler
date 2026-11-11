# T12/P3: herramientas de presentación

2026-11-07. Subbloque del alcance autorizado de T12. Añade cursor, trazos temporales y temporizador con música propia. No cierra P2, T12 ni la paridad completa. Edición en vivo, grabación, Office, controles remotos y Canva Live conservan sus tareas y requisitos del inventario.

La referencia de comportamiento está en [la lectura de presentaciones](t12-reference-refresh-2026-11-07.md). No se declara una observación nueva de Canva ni acceso premium. Las funciones locales se aceptarán por código, archivos y comportamiento reales.

## Comportamiento

Una sección plegable «Herramientas» ofrece cursor normal, láser y oculto; modo dibujar, color, grosor y borrado; duración de trazos; y temporizador con duración, iniciar, pausar, reiniciar, visibilidad para audiencia, música, volumen y silencio. Conserva el teclado y los controles existentes. Espacio en un botón activa ese botón, sin disparar además el autoplay; los campos y selectores siguen recibiendo sus teclas.

Los trazos son efímeros y de la sesión, no elementos del documento. Desaparecen tras 3 s por defecto, configurable entre 1 y 10 s. Cambiar diapositiva limpia puntero y trazos. Ratón, lápiz y toque usan Pointer Events y captura del puntero activo; terminar/cancelar fuera de la imagen no deja un trazo abierto. Un segundo puntero no secuestra el dibujo.

El mapeo utiliza el rectángulo **real del contenido** de `object-fit:contain`, incluyendo márgenes laterales o superiores. No dibuja en las bandas negras ni mezcla coordenadas CSS con píxeles del documento. Las posiciones son normalizadas 0–1. Un láser visible y los trazos se proyectan en el canvas principal y en la ventana de audiencia; las notas nunca se copian. El preview siguiente permanece sin herramientas ni audio.

El temporizador admite segundos enteros de 1 a 86.400. Se configura inicialmente a 300 s, sin música seleccionada. Estados: idle, running, paused, finished, disposed. Reloj monotónico; nunca resta un segundo por callback. Formato mm:ss o hh:mm:ss, redondeando hacia arriba el tiempo restante. Terminar muestra 00:00 y detiene música, sin navegar ni modificar la reproducción de la presentación. Pausa conserva el tiempo; reinicio vuelve a idle y restaura la duración. Mostrar/ocultar afecta sólo a la proyección del reloj, no a su marcha.

La música sólo se obtiene de los DTO de audio listos ya autorizados para esta marca. Se elige por ID, no mediante una URL libre. No descarga ni decodifica una biblioteca completa. Sólo crea un elemento de audio al iniciar con música o cambiarla durante la marcha; loop activo, volumen inicial0.4, mute independiente. No reproduce al montar o seleccionar mientras el reloj está detenido. Cambiar pista detiene y libera la anterior. Pausa, reinicio, fin, cierre y desmontaje detienen la música. Rechazos de reproducción o errores de carga quedan visibles y pausan el reloj conservando su tiempo restante; un intento antiguo nunca detiene una pista nueva ni reproduce después del cierre. Se puede iniciar otra vez con un gesto del usuario.

Pausar conserva también la posición de la música: reanudar la misma pista usa su nodo y posición, sin empezar la canción otra vez. Reinicio, cambio de pista, fin y dispose sí la liberan. Un rechazo/resolución de play antiguo sobre ese mismo nodo no detiene una reanudación vigente; mientras siga pausado debe impedir reproducción tardía. Después de un error de recurso se permite crear un nodo nuevo para recuperar, sin confundir ese caso con una pausa normal.

Los DTO aceptados tienen ID positivo seguro, kind=audio, status=listo, audio_codec y duración positiva finita. La ruta privada relativa studio_url/preview_url debe apuntar al archivo Studio de ese mismo ID (`/brands/<id>/studio/assets/<id>/file`), sin origen externo ni credenciales. La autorización real sigue en el proxy existente. Las opciones se capturan para esta sesión; cambio de diseño/marca desmonta y limpia. No se añade persistencia global entre marcas ni rutas API/MCP nuevas para este estado efímero.

## Interfaces internas entre módulos

### presentation-tools.js

Exporta `presentationPoint(rect,width,height,clientX,clientY)`, que devuelve `{x,y}` normalizado o null fuera del contenido, y `createPresentationTools({now,onChange})`.

El controlador ofrece `snapshot()`, `setPage(id)`, `setCursor(normal|laser|hidden)`, `setTool(pointer|pen)`, `setPenColor(hex)`, `setPenWidth(normalized)`, `setTrailSeconds(integer)`, `pointerDown(point,pointerId)`, `pointerMove(point,pointerId)`, `pointerUp(pointerId)`, `pointerLeave()`, `clear()`, `tick()` y `dispose()`. No posee timers, DOM o recursos multimedia. `now` usa performance.now por defecto. onChange se llama tras mutaciones, no durante la construcción ni al leer snapshots.

Snapshot: pageId, cursor, tool, pointer `{x,y}` o null, penColor, penWidth, trailSeconds, strokes y needsFrame. Strokes llevan puntos normalizados, color, width y alpha. `needsFrame` sólo pide animación para trazos terminados que aún deben desvanecerse; un láser quieto o un trazo activo quieto no crean un bucle perpetuo. `tick()` elimina expirados y devuelve snapshot. Las estructuras devueltas no permiten alterar el estado privado.

Límites: 64 trazos, 4.096 puntos totales y 1.024 por trazo. El controlador elimina trazos terminados antiguos y reduce muestras preservando extremos al alcanzar el límite de un trazo activo. No debe dejar de seguir el extremo actual. Grosor normalizado0.001–0.04 del lado menor; color hexadecimal opaco. Entradas no finitas o enums inválidos se rechazan. Después de dispose no hay publicación ni nuevas asignaciones de recursos.

Exporta `paintPresentationTools(ctx,snapshot,{width,height,timer})` y `presentationCursorStyle(snapshot)`. El pintor usa save/restore, no carga recursos ni toca el documento. Timer opcional tiene visible,status,label; sólo dibuja si visible y status no es idle/disposed. Muestra sólo el reloj, nunca datos del presentador. CursorStyle devuelve cursor CSS apropiado sin usar HTML.

### presentation-timer.js

Exporta `createPresentationTimer({assets,now,requestTick,cancelTick,createAudio,onChange,onError})`. Defaults: performance.now, setTimeout(callback,100), clearTimeout y document.createElement('audio'). requestTick recibe callback y delay. Como máximo hay un timeout pendiente y un elemento de música propio activo. onChange no se invoca durante la construcción.

Métodos: `snapshot()`, `configure(seconds)`, `start()`, `pause()`, `reset()`, `setMusic(id|null)`, `setVolume(0..1)`, `setMuted(boolean)`, `setVisible(boolean)` y `dispose()`. Start/setMusic devuelven promesa; llaman play dentro del gesto antes de esperar resultados. Dispose es síncrono e idempotente para detener audio y callbacks; cualquier promesa antigua que termine después también pausa su nodo capturado y no publica estado. No necesita Web Audio ni buffers completos.

Snapshot: durationMs,remainingMs,elapsedMs,status,label,musicId,musicOptions `[{id,name}]`,volume,muted,visible. Estructuras separadas del input y protegidas contra mutación externa. visible=true por defecto, pero idle no se proyecta. Música rechazada o error visible pausa y conserva remaining; errores antiguos no alteran la sesión vigente. No se aceptan IDs ajenos a la lista capturada.

## Integración y recursos

PresentationDialog captura los controladores para su sesión. Las herramientas se componen sobre el último canvas real del renderer; mover el puntero, borrar o actualizar el reloj **no llama seek ni crea otro renderer**. Un coalescedor requestAnimationFrame hace como máximo una composición por frame. En pausa, las herramientas siguen actualizando la imagen a partir del último frame. El reloj notifica a100ms; los trazos piden RAF sólo mientras se desvanecen. No hay intervalos en idle.

La ventana de audiencia recibe el canvas compuesto por el puente de píxeles existente. Pantalla negra permanece completamente negra, incluidas herramientas y reloj. Fullscreen del canvas incluye esas herramientas; salir conserva el estado. onBeforeUnmount/cierre cancela RAF, dispone controladores antes de liberar reproductores y cierra la ventana propia. Una operación tardía no publica ni adquiere recursos. Sin cambios en document.js/player/timeline-preview ni en los contratos de notas, cancelación o medios de P2.

El puente de audiencia admite opcionalmente `onPointer(event,canvas)` para pointerdown/move/up/cancel/leave de su canvas y un método `setCursor(default|crosshair|none)`. Los consumidores anteriores siguen funcionando sin esas opciones. Sus listeners se retiran al cerrar o pagehide. Son eventos de entrada del propietario, no un canal de control remoto ni transmisión de notas/documentos. La UI aplica el mismo mapeo de contenido y captura al canvas principal y al popup. En blackout oculta también el cursor CSS. Al abrir el popup pinta inmediatamente la composición actual, no el frame bruto anterior a las herramientas.

## Pruebas y evidencia

TDD con rojo funcional antes de cada comportamiento; logs y fuentes vinculados. Núcleos: geometría con bandas y coordenadas no finitas, multi-pointer/cancelación, expiración y límites, inmutabilidad, reloj con callbacks retrasados/pausa/reinicio/fin, música por recurso real validado y carreras de play después de pausa/cambio/dispose. UI: componente compilado y ejecutado, controles conectados a módulos reales, composición principal/audiencia, negro/preview siguiente y limpieza; no basta buscar nombres en el template.

La QA integrada futura debe conservar íntegros los24casos P2 actuales y375/768/1440, y añadir comportamiento real de estas herramientas, fullscreen, música propia y cancelación. Sólo después de SourceDone, revisión fresca y build correspondiente: manifiesto actual, guardas y ACK para una fase con su alcance completo. No reactivar ni cambiar el origen del perfil3 vencido para repetirlo. Sus fallos y evidencias permanecen inmutables; las pruebas puras no acreditan aceptación nativa.

Root conserva docs/ledger y el único slot pesado. Implementers sólo fuentes propias y pruebas puras Node512, sin browser/build/ffmpeg/PHPapp/DB/.env/proveedores. No cerrar T12 ni el objetivo de23tareas por P3.
