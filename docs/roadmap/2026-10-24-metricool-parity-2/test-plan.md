# Plan de pruebas — Paridad con Metricool, segunda ola

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (`npm run test:e2e`: Playwright levanta `php artisan serve --host=localhost --port=8080` con `APP_ENV=e2e`). Manuales: el entorno del usuario con sus cuentas reales |

> Los E2E corren en los proyectos Chromium `desktop`, `mobile` (375) y `mobile-360`, con los usuarios dedicados `e2e-sec-<proyecto>@example.test` de `global-setup.ts` y los helpers de `tests/e2e/helpers.ts` (`loginAs`, `expectNoOverflow`, `borrarMarcasE2e`). **Nunca** se usa el usuario id 28 ni se publica, comenta o importa en una red real: los E2E comprueban el estado y la respuesta de la app. Cada flujo termina con axe (0 violaciones `serious`/`critical`) y `expectNoOverflow` a 375 y 360 px.
>
> Lo que exige controlar la cola o redes externas (idempotencia del comentario, SSRF, pasadas de descarga) se fija con Pest y `Http::fake`, no aquí. El smoke real (M-01..M-05) es del usuario.
>
> **Criterios sin interfaz, cubiertos solo por Pest con `Http::fake`:** CA-04 (T-11, T-16), CA-07 (T-15), CA-14 y CA-16 (T-35), CA-17 (T-35). Las tareas de backend sin UI no llevan escenario E2E por diseño.

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Página «Textos guardados» (CA-03)
- **Objetivo**: alta, edición, filtro por marca y borrado de textos guardados.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-`.
- **Pasos**:
  1. Abrir la página de textos guardados.
  2. Crear un fragmento «Todas las marcas» y un grupo de hashtags de la marca.
  3. Editar uno y borrar el otro.
  4. Filtrar por marca.
- **Aserciones**:
  - [ ] La lista refleja cada cambio sin recargar la app.
  - [ ] El filtro por marca oculta los de otra marca y mantiene «Todas las marcas».
  - [ ] Sin desbordamiento a 375 y 360 px y axe limpio.
- **Capturas esperadas**: lista con dos textos; formulario de edición.
- **Cubre tareas**: T-03, T-05, T-50

### E2E-02 — Menú de textos guardados en el compositor (CA-01, CA-02)
- **Objetivo**: insertar un texto guardado en el cursor del campo activo.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con un texto «Todas las marcas» y otro de otra marca, y una cuenta de Instagram de prueba.
- **Pasos**:
  1. Abrir el compositor de la marca.
  2. Escribir texto y colocar el cursor en mitad.
  3. Abrir «Textos guardados» e insertar un grupo de hashtags.
- **Aserciones**:
  - [ ] El menú muestra el de «Todas las marcas» y el de la marca, y no el de otra.
  - [ ] El texto queda insertado en el cursor y el contador de hashtags de Instagram se actualiza.
  - [ ] El menú es operable con teclado y sin desbordamiento.
- **Capturas esperadas**: menú abierto; texto con los hashtags insertados.
- **Cubre tareas**: T-04, T-05, T-50

### E2E-03 — Campo de primer comentario por destino (CA-06)
- **Objetivo**: el campo aparece donde procede y respeta el máximo de la red.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con cuentas de Instagram, TikTok y YouTube de prueba.
- **Pasos**:
  1. Abrir el compositor y activar Instagram y TikTok.
  2. Elegir Story en Instagram.
  3. Escribir un primer comentario que supere el máximo.
- **Aserciones**:
  - [ ] El campo existe en Instagram (post/Reel) y no en TikTok ni en Story.
  - [ ] El contador avisa al superar el máximo y la validación lo rechaza.
  - [ ] Sin desbordamiento y axe limpio.
- **Capturas esperadas**: campo con contador; Story sin campo.
- **Cubre tareas**: T-14, T-17, T-50

### E2E-04 — Estado del primer comentario, edición y Reintentar (CA-05)
- **Objetivo**: un comentario fallido se edita y se reintenta sin tocar el estado del destino.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con un destino publicado sembrado y su comentario en `fallido`.
- **Pasos**:
  1. Abrir el post publicado.
  2. Ver el estado del comentario en `TargetStatusPanel`.
  3. Editar el texto y pulsar Reintentar.
- **Aserciones**:
  - [ ] El destino sigue «Publicada» y el comentario aparece como fallido con el motivo.
  - [ ] Editar solo está disponible en `fallido`; Reintentar lo pasa a pendiente.
  - [ ] Axe limpio y sin desbordamiento.
- **Capturas esperadas**: estado fallido con Reintentar; estado pendiente tras reintentar.
- **Cubre tareas**: T-14, T-17, T-50

### E2E-05 — Cuadrícula de Instagram (CA-08, CA-09, CA-10)
- **Objetivo**: tres columnas con lo programado arriba y la Story excluida.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con una cuenta de Instagram de prueba, dos posts programados y una Story programada (lectura de Graph simulada).
- **Pasos**:
  1. Abrir la cuadrícula de la cuenta.
- **Aserciones**:
  - [ ] Las dos programadas van arriba (la más tardía primero) y después las publicadas, en tres columnas.
  - [ ] La Story programada no aparece.
  - [ ] Sin desbordamiento a 375 y 360 px y axe limpio.
- **Capturas esperadas**: cuadrícula en desktop y a 360 px.
- **Cubre tareas**: T-21, T-22, T-50

### E2E-06 — Gestión de campañas y selector en el compositor (CA-12, CA-13)
- **Objetivo**: crear, archivar y asignar una campaña de la marca.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-`.
- **Pasos**:
  1. Crear una campaña con color y fechas.
  2. Abrir el compositor y elegirla en el selector; crear otra en línea.
  3. Archivar la primera.
- **Aserciones**:
  - [ ] La campaña aparece en la lista y en el selector.
  - [ ] Solo se ofrecen campañas de la marca.
  - [ ] Axe limpio y sin desbordamiento.
- **Capturas esperadas**: lista de campañas; selector en el compositor.
- **Cubre tareas**: T-26, T-27, T-31, T-50

### E2E-07 — Panel de campaña (CA-11)
- **Objetivo**: KPIs con «sin datos» distinto de 0 y aviso del congelado a 30 días.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` con una campaña con dos destinos publicados y snapshots sembrados, uno con una métrica `null`.
- **Pasos**:
  1. Abrir el panel de la campaña.
- **Aserciones**:
  - [ ] Los KPIs suman los últimos snapshots de cada destino.
  - [ ] La métrica sin datos se muestra como «sin datos», no como 0.
  - [ ] Se rotula «alcance acumulado» y se avisa del límite de 30 días; sin desbordamiento y axe limpio.
- **Capturas esperadas**: panel con KPIs, tabla por red y gráfica.
- **Cubre tareas**: T-30, T-31, T-50

### E2E-08 — Importar media desde una URL (CA-15)
- **Objetivo**: una URL a una IP interna se rechaza con un error claro en español.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-`.
- **Pasos**:
  1. Abrir el compositor y la sección de medios.
  2. Pegar `http://127.0.0.1/imagen.jpg` en «Desde URL» e importar.
- **Aserciones**:
  - [ ] Aparece un error en español y no se crea ningún asset.
  - [ ] El campo es accesible por teclado.
  - [ ] Sin desbordamiento a 375 y 360 px y axe limpio.
- **Capturas esperadas**: error de URL rechazada.
- **Cubre tareas**: T-37, T-39, T-50

### E2E-09 — Botón «Google Drive» oculto sin configurar (CA-18)
- **Objetivo**: sin `GOOGLE_PICKER_API_KEY` no se ofrece Drive.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*` con una marca `e2e-` y el servidor de pruebas sin `GOOGLE_PICKER_API_KEY`.
- **Pasos**:
  1. Abrir el compositor y la sección de medios.
- **Aserciones**:
  - [ ] No existe el botón «Google Drive».
  - [ ] «Desde URL» sigue disponible; axe limpio y sin desbordamiento.
- **Capturas esperadas**: sección de medios sin Drive.
- **Cubre tareas**: T-43, T-44, T-46, T-50

## Manuales (M — los realiza una persona)

### M-01 — Primer comentario real en Instagram y Facebook (CA-04)
- **Qué revisar**: Publicar un post de prueba con primer comentario y comprobar que aparece el comentario de la propia cuenta, una sola vez, también tras reintentar.
- **Por qué no se automatiza**: Exige publicar y comentar en redes reales con las cuentas del usuario.
- **Criterio de aceptación**:
  - [ ] Aparece un único comentario de la cuenta en Instagram.
  - [ ] Aparece un único comentario en Facebook.
  - [ ] Forzar un fallo y Reintentar no duplica el comentario.
- **Cubre tareas**: T-14

### M-02 — Reconexión de YouTube y comentario real (CA-04)
- **Qué revisar**: Reconectar el canal con `youtube.force-ssl` y comentar un vídeo propio.
- **Por qué no se automatiza**: Exige OAuth real de Google y un vídeo en YouTube.
- **Criterio de aceptación**:
  - [ ] La reconexión pide el permiso nuevo.
  - [ ] El comentario aparece en el vídeo.
  - [ ] Sin reconectar, el control avisa «Reconecta el canal para comentar».
- **Cubre tareas**: T-07

### M-03 — Cuadrícula con la cuenta real de Instagram (CA-08)
- **Qué revisar**: Comparar la cuadrícula con el perfil real: orden, recorte de teselas y aviso de fijados.
- **Por qué no se automatiza**: Juicio visual contra el perfil real de Instagram.
- **Criterio de aceptación**:
  - [ ] El orden de lo publicado coincide con el perfil (salvo fijados).
  - [ ] Las programadas se distinguen y enlazan al compositor.
  - [ ] El aviso de posts fijados es comprensible.
- **Cubre tareas**: T-21

### M-04 — Importar un vídeo desde Google Drive (CA-18)
- **Qué revisar**: Con Google Cloud configurado (guía de T-48), elegir un vídeo en el Picker e importarlo hasta `listo`.
- **Por qué no se automatiza**: Exige OAuth y el Picker reales de Google.
- **Preparación (la hace el usuario; guía `docs/guias/google-cloud-picker-drive.md`)**:
  1. En Google Cloud: activar Picker API y Drive API, crear la clave de API restringida al dominio y añadir `drive.file` a la pantalla de consentimiento. El cliente OAuth es el que ya usan los conectores (`GOOGLE_CLIENT_ID`) y debe tener el dominio entre los orígenes JavaScript autorizados.
  2. En el `.env` de producción: `GOOGLE_PICKER_API_KEY=` (obligatoria) y `GOOGLE_PICKER_APP_ID=` (número de proyecto); después `php artisan config:cache`.
  3. Comprobar que el cron de la cola `media` de Hestia está activo (`queue:work database --queue=media --stop-when-empty`).
- **Pasos**: abrir un borrador, pulsar «Google Drive», conceder el acceso y elegir un vídeo de más de 100 MB; seguir el progreso hasta `listo`; añadirlo al post.
- **Revisión del token (T-47, hecha por el agente)**: el token viaja solo en el cuerpo de `POST /media/import-drive` y en el payload cifrado (`ShouldBeEncrypted`) del job; no se guarda en `media_assets`, no se devuelve en JSON ni se registra; `tokeninfo` lo recibe en un POST (no en la URL); el `Authorization` solo se envía a `www.googleapis.com` por HTTPS 443 (una redirección a otro host aborta la importación antes de la petición); en `jobs`/`failed_jobs` el payload va cifrado. Caduca en 1 h: si la cola tarda más, el asset queda `invalido` con «Vuelve a elegir el fichero en Drive».
- **Comprobación extra recomendada**: tras el smoke, `grep -c "ya29" storage/logs/*.log` debe dar 0.
- **Criterio de aceptación**:
  - [ ] El botón «Google Drive» aparece con la clave configurada.
  - [ ] El vídeo elegido se importa en varias pasadas y queda `listo`.
  - [ ] La cola `media` de Hestia lo procesa con su cron.
- **Cubre tareas**: T-47

### M-05 — Revisión visual del panel de campaña y de la cuadrícula en móvil
- **Qué revisar**: Mirar a ojo el panel de campaña y la cuadrícula en un móvil real.
- **Por qué no se automatiza**: Juicio visual y de uso que axe y los asserts no cubren.
- **Criterio de aceptación**:
  - [ ] La gráfica y las teselas son legibles.
  - [ ] Nada se corta ni se solapa a 360 px.
  - [ ] Los textos están en español correcto.
- **Cubre tareas**: T-21, T-30

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-03 | E2E-01 |
| T-04 | E2E-02 |
| T-05 | E2E-01, E2E-02 |
| T-07 | M-02 |
| T-14 | E2E-03, E2E-04, M-01 |
| T-17 | E2E-03, E2E-04 |
| T-21 | E2E-05, M-03, M-05 |
| T-22 | E2E-05 |
| T-26 | E2E-06 |
| T-27 | E2E-06 |
| T-30 | E2E-07, M-05 |
| T-31 | E2E-06, E2E-07 |
| T-37 | E2E-08 |
| T-39 | E2E-08 |
| T-43 | E2E-09 |
| T-44 | E2E-09 |
| T-46 | E2E-09 |
| T-47 | M-04 |
| T-50 | E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07, E2E-08, E2E-09 |
