# T12/P2: aceptación nativa de presentación

2026-11-07. Preparación previa; no autoriza ejecutar antes de revisión limpia, compilación correspondiente, manifiesto actual y ACK root. Las pruebas puras39+5 y13 de timeline acreditan lógica bajo dependencias controladas, no reproducción, fullscreen, fuentes ni píxeles nativos.

## Entorno y recursos

Sólo servidor gestionado localhost:8080, configuración ficticia cacheada antes del Kernel, SQLite y almacenamiento con UUID nuevos, cuentas de prueba propias. No .env, MySQL, R2, proveedores, posts ni datos reales. Un único slot pesado para root; ningún agente prepara datos mediante procesos nativos por su cuenta.

Windows Job agregado3GiB COMMIT, killOnClose y sin breakaway. Antes de crear runtime/semilla: memoria física libre>=6GiB; durante la fase:>=3GiB cada100ms. Node512/PHP256, un worker, retries0. Plazo total900s para arranque, todos los escenarios de presentación, sus observadores, evidencias y cierre; no reiniciarlo por viewport o comando. Compilar es otra fase anterior, con guarda de build ya establecida.

Esta fase nueva verifica presentaciones. La renovación de gráficos conserva su propio contrato anterior: foco6+cliente3+servidor3 dentro de un único common900. No ejecutar esos grupos por separado ni usar esta fase para fraccionar ese plazo.

## Casos existentes y alcance adicional necesario

Los tres escenarios de `studio-presentations.spec.ts`, preparados en E2E29, deben ejecutarse con el editor compilado actual y su renderer real a375/768/1440. Observar persistencia/Unicode/historial/apertura con últimas notas; dos páginas con fade y captions; navegación manual y teclado, autoplay/loop, blackout, popup sin notas, foco y cierre; errores de popup/fullscreen visibles; fin que conserva un frame visible. Sus tres casos no prueban todavía vídeos, audio, tipografías privadas, gráficos ni una entrada positiva en fullscreen.

Completar la preparación de pruebas antes de reclamar aceptación de esas capacidades:

- Vídeo y audio reales propios, generados determinísticamente como fixtures bajo el mismo Job, subidos por rutas reales de la marca. Capturar HTMLMediaElement y frames nativos preservando las llamadas originales, sin reemplazar reproducción por mocks. Reproducir, pausar, navegar y cerrar; comprobar medios pausados y ausencia de eventos play posteriores al cierre. La regresión pura ya cubre el seek pendiente; no presentarla como observación nativa.
- Una tipografía privada y un gráfico con dataset versionado de la misma marca, usando sus servicios y renderer reales en la diapositiva. Comprobar contenido y la fuente efectivamente cargada en audiencia y next-preview; las notas sólo aparecen en presentador. No reutilizar una imagen de gráfico estática como prueba de su recurso vivo.
- Fullscreen positivo con gesto real y comprobación del elemento en fullscreen. Separar ese resultado del caso negativo que simula un rechazo. Comprobar salida, restauración de foco y cierre de la ventana adicional propia. Un rechazo visible no acredita entrada exitosa.
- Cierre de contexto durante preparación pendiente: no publicación tardía ni reproducción huérfana al cambiar diseño/marca o desmontar. Usar retrasos de respuestas propias cuando haga falta, sin falsear la fuente de datos ni relajar aserciones.

## Evidencia y cierre

Guardar JSON original del runner, artefactos y blobs originales, respuestas de recursos, capturas, estados de medios y medición de Job/plazo. Cada oráculo pesado una sola vez; los recuentos posteriores leen sus resultados, no vuelven a renderizar. Gate según los casos reales y límites declarados, sin inventar un número final antes de la colección.

Comprobar terminalidad del proceso raíz e hijos propios, cierre del servidor y puerto8080 libre IPv4/IPv6. Inspección visual de presentador, audiencia y layouts; PDF de QA sólo tras tener evidencia y verificar sus páginas. No cerrar P2 por compilación ni declarar T12 completa tras aceptar P2: Office editable, grabación, documentos refluibles, pizarras y el resto del alcance siguen pendientes.
