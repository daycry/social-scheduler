# Plan de pruebas — Nuevo compositor de publicaciones

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (`npm run test:e2e`: Playwright levanta `php artisan serve --host=localhost --port=8080` con `APP_ENV=e2e`). Manuales: el entorno del usuario con sus cuentas reales |

> Los E2E corren en los tres proyectos Chromium (`desktop`, `mobile` 375 y `mobile-360`) con los usuarios dedicados `e2e-sec-<proyecto>@example.test` que ya crean `global-setup.ts` y los helpers de `tests/e2e/helpers.ts` (`e2eUser`, `loginAs`, `expectNoOverflow`, `settle`, `stamp`, `borrarMarcasE2e`). **Nunca** se usa al usuario id 28 ni se publica en una red real: los E2E comprueban el estado y la respuesta de la app, no la publicación (eso es M-01). Cada flujo termina con axe (0 violaciones `serious`/`critical`) y con `expectNoOverflow` a 375 y 360 px.
>
> La **doble publicación** (riesgo principal) no se prueba en E2E, porque exige controlar la cola y los publicadores: la fijan los tests Pest `PublishNowDoubleDispatch` (T-15) y `TargetTextIdempotency*` (T-20).

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Compositor a pantalla completa con pastillas de red (CA-01)
- **Objetivo**: el compositor ocupa la pantalla, sin la navegación de la app, con la marca en la cabecera y una pastilla por cuenta operable con teclado.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con una cuenta de Instagram y otra de Facebook de prueba.
- **Pasos**:
  1. Abrir `/brands/{id}/posts/create`.
  2. Activar y desactivar cada pastilla con un clic y con la tecla Espacio.
  3. Elegir un tipo en el desplegable de una cuenta.
- **Aserciones**:
  - [ ] No hay barra de navegación de la app y se ve el nombre de la marca.
  - [ ] Cada pastilla tiene nombre accesible y `aria-pressed` correcto.
  - [ ] El tipo elegido se conserva al activar y desactivar otras cuentas.
  - [ ] Sin desbordamiento a 375 y 360 px.
- **Capturas esperadas**: compositor vacío, con dos redes activas.
- **Cubre tareas**: T-01, T-02, T-07

### E2E-02 — Contadores por red y tope de hashtags (CA-02, CA-03)
- **Objetivo**: los contadores coinciden con el servidor y Instagram rechaza más de 30 hashtags.
- **Precondiciones**: Instagram y Facebook activos.
- **Pasos**:
  1. Escribir un texto con 3 hashtags y un emoji compuesto (por ejemplo, una familia con ZWJ).
  2. Comprobar los contadores.
  3. Escribir un texto con 31 hashtags.
- **Aserciones**:
  - [ ] Se ve «n / 2.200» y «3 / 30 #» en Instagram y «n / 63.206» en Facebook, con el recuento por puntos de código.
  - [ ] Con 31 hashtags aparece un error de Instagram en el panel, con texto (no solo color).
  - [ ] Programar queda deshabilitado y Guardar borrador sigue habilitado.
- **Capturas esperadas**: contadores con emoji, error de hashtags.
- **Cubre tareas**: T-03, T-04, T-07

### E2E-03 — Barra del editor y confirmación al salir (CA-04, CA-05)
- **Objetivo**: emoji, IA y media operables con teclado, y confirmación al cerrar con cambios.
- **Precondiciones**: compositor abierto con una red activa.
- **Pasos**:
  1. Colocar el cursor en medio del texto y abrir el selector de emoji; buscar y elegir uno.
  2. Abrir la IA (`AiSuggest`) y cerrarla con Escape.
  3. Escribir texto y pulsar Cerrar; cancelar el diálogo; volver a pulsar Cerrar y confirmar.
- **Aserciones**:
  - [ ] El emoji se inserta en la posición del cursor y el foco vuelve al botón al cerrar el selector.
  - [ ] Con cambios se pide confirmación; al cancelar se conservan; sin cambios no se pide nada.
- **Capturas esperadas**: selector de emoji, diálogo de confirmación.
- **Cubre tareas**: T-05, T-06, T-07

### E2E-04 — Panel de errores, selector de fecha y botón dividido (CA-24, CA-27)
- **Objetivo**: una sola lista de errores, fecha con la zona de la marca y acciones habilitadas según el estado.
- **Precondiciones**: marca con zona horaria distinta de la del navegador; dos redes activas.
- **Pasos**:
  1. Provocar un error en dos destinos (texto largo) y elegir una fecha pasada.
  2. Mirar el panel y el botón principal; abrir su menú con el teclado.
  3. Abrir el selector de fecha.
- **Aserciones**:
  - [ ] El panel muestra una sola lista con el icono y la cuenta de cada error.
  - [ ] Programar y Publicar ahora están deshabilitados; Guardar borrador y Guardar en biblioteca, habilitados.
  - [ ] El selector muestra la zona de la marca, bloquea los días pasados y guarda el formato `Y-m-d\TH:i`.
  - [ ] El menú se abre con teclado (flechas y Escape).
- **Capturas esperadas**: panel con dos errores, selector de fecha.
- **Cubre tareas**: T-09, T-10, T-11, T-16

### E2E-05 — Publicar ahora desde el compositor (CA-25)
- **Objetivo**: un post válido pasa a «Enviando…» sin esperar al planificador y no se puede enviar dos veces.
- **Precondiciones**: post válido en una cuenta de prueba del entorno `APP_ENV=e2e` (sin red real).
- **Pasos**:
  1. Elegir «Publicar ahora» en el menú.
  2. Intentar un segundo clic inmediato.
- **Aserciones**:
  - [ ] Aparece «Enviando…» y el botón queda bloqueado.
  - [ ] Solo se envía una petición `POST` (se cuenta con `page.route`).
  - [ ] El destino queda en `programada` o `publicando` en el calendario, nunca duplicado.
- **Capturas esperadas**: estado «Enviando…».
- **Cubre tareas**: T-11, T-12, T-16

### E2E-06 — Publicar ahora desde el calendario y la lista (CA-25)
- **Objetivo**: la acción del post pide confirmación y respeta qué posts admiten publicarse.
- **Precondiciones**: un borrador, una programada y un post publicado de la marca `e2e-`.
- **Pasos**:
  1. En la vista semanal, mes y lista, abrir «Publicar ahora» en el borrador y cancelar la confirmación.
  2. Repetir y confirmar en la programada.
  3. Buscar la acción en el post publicado.
- **Aserciones**:
  - [ ] Cancelar no cambia el estado.
  - [ ] Confirmar envía la acción y la tarjeta muestra «Enviando…».
  - [ ] El post publicado no ofrece la acción.
  - [ ] La confirmación es accesible (foco, Escape) y no desborda a 375 y 360 px.
- **Capturas esperadas**: confirmación en el calendario y en la lista.
- **Cubre tareas**: T-13, T-16

### E2E-07 — Guardar en biblioteca sin guardar el post (CA-26)
- **Objetivo**: se crea la plantilla sin crear un post.
- **Precondiciones**: compositor con texto, una media y dos redes; sin post guardado.
- **Pasos**:
  1. Elegir «Guardar en biblioteca», escribir un nombre y confirmar.
  2. Abrir la Biblioteca y el calendario.
- **Aserciones**:
  - [ ] Existe la plantilla con el texto, la media y los destinos.
  - [ ] No aparece ningún post nuevo en el calendario.
- **Capturas esperadas**: plantilla en la Biblioteca.
- **Cubre tareas**: T-14, T-16

### E2E-08 — Texto propio por red (CA-08, CA-09, CA-12)
- **Objetivo**: desvincular, escribir un texto propio, guardar, reabrir y volver a vincular.
- **Precondiciones**: Instagram y Facebook activos con texto común.
- **Pasos**:
  1. Desvincular Facebook y escribir un texto propio.
  2. Comprobar los contadores de ambas redes y guardar como borrador.
  3. Reabrir el post, comprobar el texto y guardar una plantilla en la biblioteca; usarla.
  4. Volver a vincular Facebook y confirmar.
- **Aserciones**:
  - [ ] El contador de Facebook usa su texto y el de Instagram, la plantilla.
  - [ ] Al reabrir se conserva el texto propio, y la plantilla de la biblioteca también.
  - [ ] Tras volver a vincular y confirmar, el texto propio se borra; sin confirmar, se conserva.
  - [ ] Las pestañas se recorren con las flechas del teclado.
- **Capturas esperadas**: pestañas con Facebook desvinculado, confirmación.
- **Cubre tareas**: T-21, T-22, T-24

### E2E-09 — Opciones por red persistentes (CA-14, CA-15, CA-16)
- **Objetivo**: las opciones se recuerdan por marca y red, y solo se ofrece lo que la cuenta admite.
- **Precondiciones**: dos marcas `e2e-` (A y B) con YouTube y TikTok de prueba.
- **Pasos**:
  1. En A, activar YouTube y marcar «No notificar a suscriptores»; escribir título y tags; guardar.
  2. Abrir un post nuevo en A y activar YouTube; repetir en B.
  3. Activar TikTok en modo borrador y en publicación directa.
- **Aserciones**:
  - [ ] En A el interruptor aparece marcado, y los campos de texto, vacíos.
  - [ ] En B el interruptor no está marcado.
  - [ ] Con TikTok en modo borrador no se ofrecen Duet, Stitch ni contenido de marca.
  - [ ] La etiqueta IA solo aparece en las redes que la admiten según T-26.
- **Capturas esperadas**: panel de opciones de YouTube y de TikTok.
- **Cubre tareas**: T-29, T-30, T-32

### E2E-10 — Notas en un post publicado (CA-28)
- **Objetivo**: las notas se guardan aunque el post no sea editable y se ven en el calendario.
- **Precondiciones**: un post publicado de la marca `e2e-`.
- **Pasos**:
  1. Abrir el post publicado y su panel de Notas; escribir una nota y guardar.
  2. Volver al calendario.
- **Aserciones**:
  - [ ] La nota se guarda y los destinos no cambian.
  - [ ] La tarjeta muestra un indicador de nota con texto accesible.
  - [ ] Un texto con HTML (`<script>`) se muestra como texto.
- **Capturas esperadas**: panel de notas, indicador en la tarjeta.
- **Cubre tareas**: T-35, T-37

### E2E-11 — Generador UTM (CA-17)
- **Objetivo**: el modal inserta la URL con los parámetros codificados.
- **Precondiciones**: compositor abierto con texto.
- **Pasos**:
  1. Abrir el modal UTM desde la barra del editor.
  2. Rellenar solo la URL; comprobar el botón. Rellenar `source`, `medium` y `campaign` con un espacio y un `&`.
  3. Pulsar Añadir.
- **Aserciones**:
  - [ ] Sin los obligatorios, Añadir está deshabilitado.
  - [ ] Se inserta en el cursor la URL con los parámetros codificados.
  - [ ] El modal atrapa el foco y se cierra con Escape.
- **Capturas esperadas**: modal relleno, URL insertada.
- **Cubre tareas**: T-36, T-37

### E2E-12 — Barrido de accesibilidad y desbordamiento (CA-32)
- **Objetivo**: ninguna pantalla nueva tiene violaciones graves ni desborda.
- **Precondiciones**: usuario `e2e-sec-*` con sesión; compositor, calendario (con la acción y el indicador) y biblioteca.
- **Pasos**:
  1. Recorrer las páginas del barrido de `pages.spec.ts` con el compositor y el calendario añadidos.
  2. Repetir con la pestaña de texto por red, el panel de opciones y el de notas abiertos.
- **Aserciones**:
  - [ ] axe: 0 violaciones `serious` o `critical`.
  - [ ] `expectNoOverflow` pasa a 375 y 360 px.
  - [ ] Sin errores de consola ni respuestas 5xx (`watch`).
- **Capturas esperadas**: —
- **Cubre tareas**: T-01, T-02, T-07, T-16, T-24, T-32, T-37, T-39

## API (opcional, opt-in — smoke de endpoints, los ejecuta `qa` con curl)

### API-01 — `POST /posts/{post}/publish-now`
- **Método y ruta**: `POST /posts/{id}/publish-now` (relativa a la URL local; con sesión y CSRF de un usuario `e2e-sec-*`)
- **Status esperado**: 302 con un borrador válido; 403 o 404 con un post ajeno; error de validación con un post no editable.
- **Aserción sobre el body**: tras la redirección, el destino sigue `programada` o pasa a `publicando`; un segundo `POST` seguido no duplica.
- **Cubre tareas**: T-13

### API-02 — `PATCH /posts/{post}/notes`
- **Método y ruta**: `PATCH /posts/{id}/notes` (relativa a la URL local)
- **Status esperado**: 302 (o 200 JSON) en un post publicado propio; 422 con más de 5.000 caracteres; 403 o 404 con un post ajeno.
- **Aserción sobre el body**: las filas de `post_targets` no cambian y `posts.notes` guarda el texto plano.
- **Cubre tareas**: T-34, T-37

## Accesibilidad (opcional, opt-in — axe-core vía Playwright)

### A11Y-01 — Compositor
- **Página(s)**: `/brands/{id}/posts/create`, `/posts/{id}/edit`
- **Umbral**: 0 violaciones `serious`/`critical`, también con el selector de emoji, el modal UTM, el menú del botón dividido y el selector de fecha abiertos
- **Cubre tareas**: T-07, T-16, T-39

### A11Y-02 — Calendario con la acción y el indicador
- **Página(s)**: `/calendar` (semana, mes y lista)
- **Umbral**: 0 violaciones `serious`/`critical`, con la confirmación de «Publicar ahora» abierta
- **Cubre tareas**: T-16, T-39

## Manuales (M — los realiza una persona)

### M-01 — Smoke real: una publicación por red
- **Qué revisar**: publicar en YouTube, TikTok (borrador), Instagram y Facebook con las opciones aprobadas, con texto propio en Instagram y Facebook y una «Publicar ahora». Registrar en `testing/smoke-real.md`.
- **Por qué no se automatiza**: usa cuentas, cuotas y APIs reales de terceros.
- **Criterio de aceptación**:
  - [ ] Cada red publica con el texto efectivo y las opciones elegidas.
  - [ ] No hay publicaciones duplicadas en Instagram ni en Facebook (ni tras un reintento forzado).
  - [ ] Las incidencias quedan anotadas.
- **Cubre tareas**: T-41, T-42

### M-02 — Identidad propia y revisión visual
- **Qué revisar**: el compositor, el texto por red y el panel de opciones en el navegador, en escritorio y móvil, frente a Metricool: cercano en uso, con paleta, iconos y textos propios (sin copiar maquetación ni textos).
- **Por qué no se automatiza**: es un juicio visual y de UX.
- **Criterio de aceptación**:
  - [ ] El usuario da el visto bueno visual de cada fase.
  - [ ] No hay textos ni iconos copiados de Metricool.
- **Cubre tareas**: T-02, T-21, T-30, T-42

### M-03 — Teclado y lector de pantalla
- **Qué revisar**: recorrer solo con teclado y con un lector de pantalla las pastillas de red, el selector de emoji, el botón dividido, el selector de fecha, las pestañas por red, el panel de opciones y el modal UTM.
- **Por qué no se automatiza**: axe no detecta el orden de foco ni la calidad de los anuncios.
- **Criterio de aceptación**:
  - [ ] El orden de foco es lógico y los diálogos atrapan y devuelven el foco.
  - [ ] Los errores y los contadores se anuncian.
- **Cubre tareas**: T-02, T-05, T-10, T-11, T-21, T-30, T-36, T-42

### M-04 — Opciones de TikTok y de YouTube en cuentas reales
- **Qué revisar**: que la divulgación de contenido de TikTok y las opciones de YouTube (categoría, hecho para niños, notificar) llegan como se espera, y que lo no confirmado en T-26 sigue oculto.
- **Por qué no se automatiza**: depende de las cuentas reales y de la auditoría de TikTok.
- **Criterio de aceptación**:
  - [ ] Los campos confirmados se ven en la plataforma tras publicar.
  - [ ] Lo no confirmado no se ofrece en la UI.
- **Cubre tareas**: T-26, T-28, T-41, T-42

### M-05 — MCP desde un cliente real
- **Qué revisar**: desde un cliente MCP conectado, `publish_post_now`, `update_post_notes`, y `create_post`, `update_post` y `get_post` con texto por destino, opciones y notas.
- **Por qué no se automatiza**: usa un cliente real y la conexión OAuth.
- **Criterio de aceptación**:
  - [ ] Las herramientas devuelven los mismos errores de validación que la web.
  - [ ] `publish_post_now` no publica dos veces ni posts ajenos.
- **Cubre tareas**: T-13, T-35, T-40, T-42

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-01 | E2E-01, E2E-12 |
| T-02 | E2E-01, E2E-12, M-02, M-03 |
| T-03 | — (Pest: `MediaValidatorHashtags`; la UI en E2E-02) |
| T-04 | E2E-02 |
| T-05 | E2E-03, M-03 |
| T-06 | E2E-03 |
| T-07 | E2E-01, E2E-02, E2E-03, E2E-12, A11Y-01 |
| T-08 | — (revisión de robustez) |
| T-09 | E2E-04 |
| T-10 | E2E-04, M-03 |
| T-11 | E2E-04, E2E-05, M-03 |
| T-12 | — (Pest: `PublishNow`) |
| T-13 | E2E-06, API-01, M-05 |
| T-14 | E2E-07 |
| T-15 | — (Pest: `PublishNowDoubleDispatch`) |
| T-16 | E2E-04, E2E-05, E2E-06, E2E-07, E2E-12, A11Y-01, A11Y-02 |
| T-17 | — (revisión de robustez) |
| T-18 | — (Pest) |
| T-19 | — (Pest: `TargetTextPlanFill`, `TargetTextFreshAttempt`) |
| T-20 | — (Pest: `TargetTextIdempotencyInstagram`, `TargetTextIdempotencyFacebook`) |
| T-21 | E2E-08, M-02, M-03 |
| T-22 | E2E-08 |
| T-23 | — (Pest: `McpTargetText`) |
| T-24 | E2E-08, E2E-12 |
| T-25 | — (revisión de robustez) |
| T-26 | M-04 |
| T-27 | — (Pest: `YouTubeOptions`) |
| T-28 | M-04 |
| T-29 | E2E-09 |
| T-30 | E2E-09, M-02, M-03 |
| T-31 | — (Pest: `McpOptions`) |
| T-32 | E2E-09, E2E-12 |
| T-33 | — (revisión de robustez) |
| T-34 | API-02 |
| T-35 | E2E-10, M-05 |
| T-36 | E2E-11, M-03 |
| T-37 | E2E-10, E2E-11, E2E-12, API-02 |
| T-38 | — (revisión de robustez) |
| T-39 | E2E-12, A11Y-01, A11Y-02 |
| T-40 | M-05 |
| T-41 | M-01, M-04 |
| T-42 | M-01, M-02, M-03, M-04, M-05 |
