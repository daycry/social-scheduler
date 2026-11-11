# T12/P3: pantalla completa desde la ventana de audiencia

2026-11-07. Aclaración de integración UI; no modifica la API de los núcleos ni sus criterios aprobados. Continúan pendientes la revisión integral, compilación y aceptación nativa P2/P3.

## Base y límite de evidencia

La [especificación Fullscreen](https://fullscreen.spec.whatwg.org/#dom-element-requestfullscreen) exige activación transitoria del objeto global relevante. La [notificación de activación de HTML](https://html.spec.whatwg.org/multipage/interaction.html#activation-notification) comprende el contexto del gesto y sus navegables ancestros/descendientes pertinentes; una ventana auxiliar superior no es simplemente uno de ellos. Estas reglas motivan ofrecer un gesto en la propia audiencia. No se afirma haber reproducido un rechazo nativo en todas las versiones del navegador ni haber ejecutado todavía estos nuevos casos.

La UI1 prepara un caso positivo sobre el popup, pero su puente sólo llamaba requestFullscreen desde el botón del presentador. Esta fase añade una entrada propia y verificable, sin relajar la comprobación de fullscreen ni cambiar permisos o configuración del navegador.

## Comportamiento autorizado

- Doble clic confiable sobre el canvas o F sin Ctrl/Alt/Meta y sin repetición solicita fullscreen de ese canvas **en la misma llamada del gesto**, sin esperar una promesa o timer antes de solicitarlo. Shift-F también funciona.
- Si ya está fullscreen ese canvas, el mismo gesto solicita salir. Escape sigue disponible como mecanismo nativo y conserva la lógica anterior de presentación.
- El canvas puede recibir foco de teclado y tiene una indicación accesible/title con «Doble clic o F para pantalla completa». La ventana conserva sólo ese canvas, sin copiar notas, documento o HTML del presentador.
- Otras teclas, punteros y sus cancelaciones siguen el puente existente. El acceso por teclado no debe disparar además comandos del presentador.
- Se añade un callback opcional onError al puente; el Dialog lo conecta a su aviso visible. Un rechazo actual se informa con una instrucción comprensible. No hay rechazo de promesa sin manejar.
- close/pagehide retiran listeners de doble clic y teclado junto a los existentes. Un request pendiente que resuelva o falle tras cerrar no vuelve a abrir, pintar ni publicar avisos de una ventana antigua.
- El método público fullscreen() y los consumidores anteriores siguen siendo compatibles. Puede seguir intentando la solicitud desde el botón del presentador; si el navegador la rechaza, el mensaje explica el gesto disponible en audiencia. No se simula éxito ni se fuerza activación.

## Pruebas

Nuevo RED funcional antes del código, sobre el puente real: faltan las entradas propias, no por fallo de importación. UI compilada/ejecutada y funciones reales, canvas/documento controlados. Verificar llamada síncrona, doble clic/F/Shift-F, rechazo de eventos sintéticos y modificados/repetidos, salida, delegación de otras teclas, compatibilidad, errores visibles y cleanup con promesa pendiente. No atribuir esas pruebas puras a un navegador.

El primer caso E2E de herramientas conserva su fullscreen principal, y usa doble clic real en el canvas del popup para el positivo de audiencia; comprueba document.fullscreenElement real y conserva las comprobaciones de píxeles/privacidad. Puede comprobar F para salir/reentrar con un gesto real adicional. Ningún skip ni cambio de aserción a «rechazo esperado» sustituye al positivo.

No cambia el total previsto de tres escenarios P3. Los24casos P2 permanecen intactos; el recuento definitivo exige colección readonly posterior. No ejecutar ni reiniciar el perfil3 vencido. Root conserva el único slot pesado y toda autorización de QA/build. La nueva integración requiere revisión y compilación correspondiente antes de usar el navegador.
