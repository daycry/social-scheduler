# Plan de pruebas — Paridad con Metricool, tercera ola

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (`npm run test:e2e`: Playwright levanta `php artisan serve --host=localhost --port=8080` con `APP_ENV=e2e`). Manuales: el entorno del usuario con sus cuentas reales |

> Los E2E corren en los proyectos Chromium `desktop`, `mobile` (375) y `mobile-360`, con los usuarios dedicados `e2e-sec-<proyecto>@example.test` de `global-setup.ts` y los helpers de `tests/e2e/helpers.ts` (`loginAs`, `axeSeriousCritical`, `expectNoOverflow`, `borrarMarcasE2e`). **Nunca** se usa el usuario id 28. Las redes externas (Bluesky, Threads, Giphy, Anthropic) se simulan; la regresión final usa el servidor propio de Playwright, sin `E2E_NO_SERVER`.
>
> **Criterios sin interfaz, cubiertos solo por Pest con `Http::fake`:** CA-02 a CA-09 (Bluesky y Threads: T-21..T-27, T-57..T-61). Las tareas de backend sin UI no llevan escenario E2E por diseño.
>
> **Bloques opcionales no incluidos:** API-xx (smoke de endpoints con curl) y A11Y-xx dedicados; la accesibilidad ya se comprueba con axe dentro de cada E2E. Pídelos si los quieres.

## Automáticos (E2E — los ejecuta `qa` con Playwright)


### E2E-01 — Notas del inbox (CA-17)
- **Objetivo**: Alta, borrado, insignia «con nota» y filtro; la nota sobrevive a un resync.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Marca `e2e-` con un comentario sembrado (`seedInboxComment`).
- **Pasos**:
  1. Abrir el inbox.
  2. Añadir una nota al comentario y comprobar la insignia.
  3. Filtrar por «con nota».
  4. Borrar la nota.
- **Aserciones**:
  - [ ] La nota aparece bajo el comentario y la insignia es visible.
  - [ ] El filtro deja solo comentarios con nota.
  - [ ] 0 violaciones axe serious/critical y `expectNoOverflow` en 375 y 360 px.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-03, T-06

### E2E-02 — Vistas de calendario guardadas (CA-11)
- **Objetivo**: Guardar «Pendientes IG», elegirla desde otro filtro, renombrar y borrar.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Marca `e2e-` con publicaciones de varias redes y estados (`seedComposerPosts`).
- **Pasos**:
  1. Filtrar por marca, red y estado.
  2. Pulsar «Guardar vista» y nombrarla.
  3. Cambiar el filtro y elegir la vista guardada.
  4. Renombrar y borrar.
- **Aserciones**:
  - [ ] Los tres filtros se reaplican al elegir la vista.
  - [ ] Renombrar y borrar se reflejan en el selector.
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-10, T-13

### E2E-03 — Bluesky: conexión y superficies (CA-01, CA-10)
- **Objetivo**: Conectar con handle y app password (API simulada) y ver el icono y filtros de Bluesky.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Marca `e2e-`; Bluesky simulado en el entorno e2e.
- **Pasos**:
  1. Abrir Conexiones de la marca.
  2. Rellenar handle y app password y enviar.
  3. Abrir el calendario y el dashboard.
- **Aserciones**:
  - [ ] La cuenta aparece conectada y la contraseña no se muestra.
  - [ ] Icono y filtro de Bluesky visibles.
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-20, T-28

### E2E-04 — Todas las marcas (CA-12)
- **Objetivo**: Totales, tabla por marca y gráfica de la analítica entre marcas.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Dos marcas `e2e-` con *snapshots* sembrados.
- **Pasos**:
  1. Abrir el dashboard y elegir «Todas las marcas».
  2. Cambiar el periodo.
- **Aserciones**:
  - [ ] Cada métrica es la suma de las marcas con dato; `null` se muestra como «no disponible».
  - [ ] Hay una fila por marca.
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-32, T-35

### E2E-05 — Exportar CSV (CA-13)
- **Objetivo**: Descarga de la lista filtrada con BOM, `;` y neutralización.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Publicaciones sembradas, una con texto que empieza por `=`.
- **Pasos**:
  1. Abrir la vista Lista con un filtro activo.
  2. Pulsar «Exportar CSV».
- **Aserciones**:
  - [ ] El fichero tiene BOM, `;` y exactamente las publicaciones del filtro.
  - [ ] El texto con `=` sale neutralizado.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-39, T-41

### E2E-06 — Hashtags desde el histórico (CA-14)
- **Objetivo**: Sugerencias ordenadas, inserción en el cursor y aviso del máximo por red.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Marca `e2e-` con publicaciones, hashtags y métricas sembrados.
- **Pasos**:
  1. Abrir el compositor.
  2. Abrir las sugerencias de hashtags.
  3. Pulsar un chip.
- **Aserciones**:
  - [ ] Solo hashtags con ≥ 2 usos, ordenados por rendimiento.
  - [ ] El chip se inserta en el cursor.
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-44, T-47

### E2E-07 — Programar en lenguaje natural (CA-16)
- **Objetivo**: Propuesta de fecha y redes, aplicada solo al confirmar.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. IA simulada en el entorno e2e; `AI_ASSISTANT_ENABLED=true`.
- **Pasos**:
  1. Abrir el compositor.
  2. Escribir «el viernes a las 18 en Instagram y Bluesky» en «Escribe cuándo».
  3. Confirmar o descartar la propuesta.
- **Aserciones**:
  - [ ] La propuesta se ve antes de aplicarse.
  - [ ] Al confirmar se rellenan fecha y redes.
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-50, T-52

### E2E-08 — Threads y Bluesky en calendario y dashboard (CA-10)
- **Objetivo**: Iconos, filtros y métricas de las dos redes.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Cuentas y posts de Bluesky y Threads sembrados.
- **Pasos**:
  1. Abrir el calendario.
  2. Abrir el dashboard y la pestaña de cada red.
- **Aserciones**:
  - [ ] Iconos y filtros de las dos redes visibles.
  - [ ] Métricas con `null` mostrado como «no disponible».
  - [ ] axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-60, T-62

### E2E-09 — Buscador de GIFs (CA-15)
- **Objetivo**: Buscar, elegir e importar un GIF con la atribución visible.
- **Precondiciones**: app en `http://localhost:8080`; usuario `e2e-sec-*`. Giphy simulado en el entorno e2e.
- **Pasos**:
  1. Abrir el compositor y el buscador de GIFs.
  2. Buscar «gato» y elegir un resultado.
- **Aserciones**:
  - [ ] Se muestra «Powered by GIPHY».
  - [ ] El MP4 queda como media del post.
  - [ ] Foco gestionado, axe y sin desborde.
- **Capturas esperadas**: estado inicial y resultado final del flujo en `desktop` y `mobile-360`.
- **Cubre tareas**: T-66, T-70

## Manuales (M — los realiza una persona)

### M-01 — Smoke real de Bluesky
- **Qué revisar**: Con la cuenta real: conectar con app password, publicar texto con enlace y hashtag, 4 imágenes (una > 2 MB), un vídeo (con email verificado) y leer métricas. Resolver los ⚠️ de `aud`, `rkey` repetida y AppView.
- **Por qué no se automatiza**: Exige la cuenta real y credenciales del usuario.
- **Criterio de aceptación**:
  - [ ] Texto con enlace y hashtag clicables.
  - [ ] 4 imágenes publicadas (la grande recomprimida).
  - [ ] Vídeo publicado, o mensaje claro si el cupo o el email lo impiden.
  - [ ] Seguidores y métricas por post visibles, con `views` «no disponible».
- **Cubre tareas**: T-30
- **Preparación (la hace el usuario; la guía de la app password es la de [Bluesky: app password](../../guias/bluesky-app-password.md), T-73 adelantada):**
  1. En Bluesky: *Configuración → Privacidad y seguridad → Contraseñas de aplicación* → crear una (sin mensajes directos) y copiarla.
  2. Tener a mano: una imagen de más de 2 MB, otras 3 imágenes pequeñas, un MP4 de menos de 50 MB (y de menos de 3 min) y una cuenta con el **email verificado** (Bluesky → Configuración → Cuenta → Email).
  3. Usar una marca propia de pruebas (no el usuario 28 ni datos de clientes). `php artisan migrate` aplicada y `npm run build` hecho; con la cola corriendo en tu entorno (`queue:work`) y el scheduler, o lanzar `php artisan schedule:run` a mano.
- **Pasos:**
  1. **Conectar.** Conexiones → Bluesky → «+ Conectar» → handle + app password. Esperado: «Conectada». Probar antes con una app password incorrecta: error claro y nada guardado.
  2. **Texto.** Programar (o «Publicar ahora») `Prueba https://example.com #pruebas @tu.handle` con un emoji compuesto. Esperado: enlace, hashtag y mención clicables en bsky.app; el contador del compositor coincide con Bluesky. Anotar el enlace del post.
  3. **4 imágenes.** Una de más de 2 MB (se recomprime: avisa en el compositor) y tres pequeñas. Esperado: 4 imágenes en el post y la original intacta en la biblioteca.
  4. **Vídeo.** Un MP4 < 50 MB. Esperado: destino «Procesando» y luego «Publicada» con enlace. Probar también uno > 50 MB (rechazo al guardar) y, si se puede, con el email sin verificar (mensaje claro).
  5. **Idempotencia.** Cortar la red un instante durante «Publicar ahora» (o detener la cola a mitad) y reintentar: no debe haber dos posts iguales.
  6. **Métricas.** Esperar al job de snapshots (o `php artisan tinker`: `app(App\Analytics\SnapshotCollector::class)->collectAccount($cuenta, App\Analytics\Days::closed($cuenta->network))` y `collectPosts($cuenta, 48)`). Esperado: seguidores y posts de la cuenta, y me gusta/comentarios/compartidos por post; «No disponible» en visualizaciones.
  7. **Renovación de sesión.** Con la cuenta conectada, ejecutar `php artisan tinker` → `app(App\Connectors\Bluesky\BlueskySession::class)->refresh($cuenta)`; esperado: nuevos tokens y la cuenta sigue «Conectada». Revocar la app password en Bluesky y repetir: la cuenta debe pasar a «Requiere reconexión».
- **Hallazgos a resolver (⚠️ del diseño):**
  | ⚠️ | Qué comprobar | Resultado (rellenar) |
  |---|---|---|
  | `aud` de `getServiceAuth` | El vídeo se sube con `aud = did:web:<host del PDS>` y `lxm = com.atproto.repo.uploadBlob`. ¿Lo acepta `video.bsky.app`? Si no, ajustar `BlueskyPublisher::publishVideo()` | |
  | `rkey` repetida | Error real del PDS al crear con una `rkey` ya usada (el código ya confirma con `getRecord` ante cualquier error) | |
  | AppView público | ¿`public.api.bsky.app` devuelve perfil y `getPosts` sin sesión? Si no, ajustar `BLUESKY_APPVIEW` | |
  | Límites reales | `getUploadLimits` (vídeos/día y bytes/día) y duración máxima real | |
  | Cupo y `JOB_STATE_*` | Estados reales de `getJobStatus` y mensaje de error de un trabajo fallido | |
- **Resultado (rellenar por el usuario):** fecha · cuenta · cada paso OK/KO · enlaces de los posts · hallazgos.

### M-02 — Smoke real de Threads
- **Qué revisar**: Con la app de Meta del usuario y su cuenta como *tester*: OAuth, texto (con emojis al límite), imagen, vídeo y carrusel; cuota y métricas. Confirma que no hace falta App Review.
- **Por qué no se automatiza**: Exige la app de Meta y la cuenta reales.
- **Criterio de aceptación**:
  - [ ] OAuth completo y token de 60 días guardado.
  - [ ] Los 4 tipos se publican.
  - [ ] El conteo de emojis coincide con el de Threads.
  - [ ] Límites y métricas reales anotados.
- **Cubre tareas**: T-64

### M-03 — Giphy: términos y atribución
- **Qué revisar**: Leer los ToS de Giphy sobre descargar y republicar el MP4 y comprobar visualmente «Powered by GIPHY».
- **Por qué no se automatiza**: Es una decisión legal y de juicio visual.
- **Criterio de aceptación**:
  - [ ] Los términos permiten republicar.
  - [ ] La atribución es visible y legible en móvil.
- **Cubre tareas**: T-66, T-75

### M-04 — Revisión visual de la UI nueva
- **Qué revisar**: Revisar en móvil y escritorio: inbox con notas, vistas, «Todas las marcas», hashtags, «Escribe cuándo», GIF y formulario de Bluesky.
- **Por qué no se automatiza**: Juicio visual y de UX (LES-009).
- **Criterio de aceptación**:
  - [ ] Español correcto, sin textos cortados ni desbordes.
  - [ ] Estados vacío y de error comprensibles.
  - [ ] Foco visible y orden de tabulación lógico.
- **Cubre tareas**: T-03, T-10, T-17, T-32, T-44, T-50, T-66

### M-05 — Lenguaje natural con la IA real
- **Qué revisar**: Probar frases reales («mañana a las 9», «el próximo lunes por la tarde») contra la clave de Anthropic del usuario y revisar el coste por llamada.
- **Por qué no se automatiza**: Usa la IA real y gasta cupo.
- **Criterio de aceptación**:
  - [ ] Las propuestas son razonables y en la zona de la marca.
  - [ ] El límite diario se respeta.
- **Cubre tareas**: T-49, T-50

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-03 | E2E-01, M-04 |
| T-06 | E2E-01 |
| T-10 | E2E-02, M-04 |
| T-13 | E2E-02 |
| T-17 | M-04 |
| T-20 | E2E-03 |
| T-28 | E2E-03 |
| T-30 | M-01 |
| T-32 | E2E-04, M-04 |
| T-35 | E2E-04 |
| T-39 | E2E-05 |
| T-41 | E2E-05 |
| T-44 | E2E-06, M-04 |
| T-47 | E2E-06 |
| T-49 | M-05 |
| T-50 | E2E-07, M-04, M-05 |
| T-52 | E2E-07 |
| T-60 | E2E-08 |
| T-62 | E2E-08 |
| T-64 | M-02 |
| T-66 | E2E-09, M-03, M-04 |
| T-70 | E2E-09 |
| T-75 | M-03 |
