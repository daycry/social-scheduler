# T12: notas persistentes y presentación operativa

2026-11-07. Continúa P1 dentro del alcance aprobado de T12. La fase de gráficos common900 fue rechazada antes de crear el Job por memoria física insuficiente. Root cambia la secuencia para avanzar en implementación sin esperar capacidad: conserva íntegros build8 y todos sus recibos, que pasarán a ser históricos cuando cambien sus fuentes. La siguiente compilación y QA deberán corresponder al árbol nuevo. No se reutiliza un PASS antiguo como prueba del nuevo editor.

## Notas de página

`pages[].notes` es texto plano opcional, máximo20.000 puntos de código Unicode por página, tanto en documentos v1 como v2. Se conservan exactamente saltos, espacios, emoji y texto vacío; ausente sigue ausente en documentos anteriores. JS y PHP rechazan null, booleanos, números, listas, objetos y20.001 caracteres. El límite total de documento sigue en2MiB. Las notas participan en copiar/duplicar, guardar/reabrir, revisiones, API y MCP mediante el mismo documento. No se interpretan como HTML ni se añaden a la salida visual de audiencia o a los gráficos exportados. OpenAPI documenta el campo opcional sin cambios rompedores.

La edición en el panel de página usa el guardado y el historial existentes, conserva la página destinataria y comunica errores de validación. Guardar, cambiar página y abrir presentación no pueden perder las últimas notas escritas. Deshacer/rehacer y recuperar revisión deben reflejar su valor actual. Una sesión abierta toma copia propia del documento y notas; cambios posteriores no alteran esa sesión.

## Presentación

El editor abre una vista de audiencia o presentador mediante controles reales. La vista de presentador incluye diapositiva actual, siguiente y notas; la audiencia recibe sólo los píxeles de la escena. Se admite una ventana de audiencia adicional creada por gesto del usuario, con bloqueo de popup y errores de fullscreen visibles. Cerrar la presentación cierra sólo su ventana propia y libera todos los recursos. No se modifica la escena Fabric editable para presentar.

La reproducción usa `createTimelinePreview` real, con recursos, fuentes privadas, vídeos, audio, subtítulos y gráficos de la marca. Un documento v1 se adapta a v2 sólo en una copia de sesión, con fps30 y pistas vacías. Para v2 se conserva la timeline original y sus transiciones; no se eliminan audio, captions ni ventanas temporales. Se usa el plan de `compileTimeline` como reloj de medios para evitar diferencias con sus solapes y cuantización. P1 continúa suministrando selección/navegación/loop/blackout; el adaptador de UI publica el tiempo de medios explícito sin cambiar sus contratos puros.

Autoplay avanza desde un reloj monotónico, descuenta buffering de medios y no solapa renders. Pausa, navegación, blackout, fin, loop y cierre son operativos. Los saltos manuales eligen un instante donde la página destino ya está visible: no se quedan en el frame anterior al inicio de un fade. La sesión informa qué página corresponde a las notas durante una transición. Al final conserva una imagen visible de la última página en lugar del frame vacío posterior a su duración.

Controles: anterior/siguiente, primera/última, selección por página, reproducción/pausa, bucle, blackout y pantalla completa. Teclado: flechas/PageUp/PageDown, Home/End, Space, B y Escape; no interceptar escritura en campos ni permitir atajos de edición del editor detrás de la presentación. El diálogo restaura foco al cerrar. No iniciar dos reproductores: abrir presentación pausa el canvas/timeline del editor; cambiar marca/diseño y desmontar cancela tareas pendientes.

Toda operación asíncrona comprueba su generación/contexto después de cada await. Un cierre o una navegación más reciente impide que una promesa antigua pinte, vuelva a reproducir audio o cambie el estado; recursos creados tarde se liberan. Errores de preparación o reproducción detienen el reloj y los medios y se muestran. El componente y sus ventanas/listeners/RAF tienen cierre idempotente.

## Verificación y alcance pendiente

TDD: regresiones JS/PHP de notas, guardar/reabrir/revisiones/API/MCP con datos ficticios y SQLite aislado; pruebas del controlador de presentación con la sesión/plan reales y renderer/reloj controlados, incluidas carreras y buffering. Compilar el SFC prueba su sintaxis, no su UI. Preparar E2E de notas/presentador/audiencia/teclado/fullscreen/cierre y ejecutar después con la guarda de recursos vigente, sin usar producción ni leer .env.

Root conserva docs/ledger, compilación, navegador, PDF y QA nativa. Implementers distintos poseen notas/validadores/tests/OpenAPI y UI/controlador/tests, respectivamente; no se pisan. Se preservan todos los archivos anteriores por bytes antes de modificar y se registra RED antes del código. Revisión adversarial fresca y verificación real antes de aceptar el bloque. Grabación, Office editable, documentos refluibles, pizarras y el resto de T12/23 siguen pendientes: este contrato no los elimina ni cierra T12.
