# Pendientes manuales

Lo que no puede hacer un agente: requiere cuentas reales, el VPS o juicio del usuario. Fuente: [tasks.md](../roadmap/2026-10-19-social-scheduler/tasks.md) (ledger) y [test-plan.md](../roadmap/2026-10-19-social-scheduler/test-plan.md). Estado a 2026-10-26: ninguno ejecutado.

## Pruebas manuales M-01..M-05

| Id | Qué hacer | Criterio de aceptación | Tareas |
|---|---|---|---|
| M-01 | Publicar de verdad los 13 tipos (IG post/carrusel/reel/story, FB texto/foto/vídeo/reel, YouTube vídeo y short, TikTok vídeo y foto) | Cada tipo queda publicado con enlace/ID y visibilidad conforme a G1 | T-21, T-22, T-23, T-26, T-27 |
| M-02 | Conectar IG, FB, YouTube y TikTok reales con ngrok; desconectar, reconectar, renovación de tokens | Sin errores; tokens cifrados en BD | T-12..T-16 |
| M-03 | Revisar dashboards y PDF con datos reales (job diario ~30 días); aprobar diseño y secciones del informe | El usuario aprueba; cierra T-37 y T-41 | T-37, T-39, T-41 |
| M-04 | Desplegar en el VPS HestiaCP ([despliegue](../modulos/despliegue.md)) | `/up` 200 bajo HTTPS; un worker caído se levanta solo; una publicación vencida durante la caída sale al volver | T-09, T-10 |
| M-05 | Revisar el informe del spike y la decisión G1 | Veredicto por plataforma con evidencia y decisión G1 por red | T-01..T-05 |

## metricool-parity-2

Pruebas con cuentas, datos y servicios reales de la iniciativa [metricool-parity-2](../roadmap/2026-10-24-metricool-parity-2/tasks.md) (el detalle está en su [test-plan](../roadmap/2026-10-24-metricool-parity-2/test-plan.md)). Antes del smoke de Drive y de YouTube, sigue la [guía de Google Cloud](../guias/google-cloud-picker-drive.md). Ninguno ejecutado.

| Id | Qué hacer | Criterio de aceptación |
|---|---|---|
| P2-M-01 | **Primer comentario real en Instagram y Facebook** (test-plan M-01). Publicar un post de prueba con primer comentario; forzar un fallo y pulsar Reintentar | Un único comentario de la cuenta en Instagram y otro en Facebook; Reintentar no duplica el comentario |
| P2-M-02 | **Reconexión de YouTube y comentario real** (test-plan M-02). En Conexiones pulsar «+ Conectar» en la tarjeta de YouTube (actualiza la cuenta; «Reconectar» solo sale si no está activa), aceptar `youtube.force-ssl` y comentar un vídeo propio | La reconexión pide el permiso nuevo; el comentario aparece en el vídeo; sin reconectar, el control avisa «Reconecta el canal para comentar» |
| P2-M-03 | **Cuadrícula con la cuenta real de Instagram** (test-plan M-03). Comparar la cuadrícula con el perfil real | El orden de lo publicado coincide con el perfil (salvo fijados); las programadas se distinguen y enlazan al compositor; el aviso de fijados es comprensible |
| P2-M-04 | **Importar un vídeo desde Google Drive** (test-plan M-04). Con `GOOGLE_PICKER_API_KEY`, `GOOGLE_PICKER_APP_ID` y `GOOGLE_CLIENT_ID` (sin cualquiera de los tres no hay botón), pulsar «Google Drive», conceder `drive.file` y elegir un vídeo de más de 100 MB. Tras el smoke, `grep -c "ya29" storage/logs/*.log` | El botón aparece; el vídeo se importa en varias pasadas hasta `listo` por el cron de la cola `media`; el grep da 0 |
| P2-M-05 | **Revisión visual del panel de campaña y la cuadrícula en móvil** (test-plan M-05). Mirarlos en un móvil real | Gráfica y teselas legibles; nada se corta ni se solapa a 360 px; textos en español correcto |

Importación por URL real (no figura en el test-plan, que la cubre con loopback): importar con «Desde URL» una imagen y un vídeo públicos reales y una URL que redirija; una IP interna se rechaza con el mensaje en español. **Por diseño**, un vídeo sin `Accept-Ranges` solo queda `listo` si cabe en una sola pasada (80 s de presupuesto); si no, acaba `invalido` con «El servidor no permite descargas por partes».

## metricool-parity-3

Pruebas con cuentas, claves y juicio del usuario de la iniciativa [metricool-parity-3](../roadmap/2026-10-26-metricool-parity-3/tasks.md) (pasos completos en su [test-plan](../roadmap/2026-10-26-metricool-parity-3/test-plan.md)). Ningún agente puede hacerlas: exigen la app password de Bluesky, la app de Meta con la cuenta como *tester*, la clave de Giphy o la clave de Anthropic.

| Id | Qué hacer | Criterio de aceptación | Tareas |
|---|---|---|---|
| P3-M-01 | **Smoke real de Bluesky** (test-plan M-01; guía: [app password](../guias/bluesky-app-password.md)). Conectar con una app password; publicar texto con enlace, hashtag y mención, 4 imágenes (una de más de 2 MB), un vídeo (con email verificado) y leer métricas. Resolver los ⚠️ de `aud` del vídeo, `rkey` repetida y AppView público | Texto con enlace y hashtag clicables; 4 imágenes (la grande recomprimida); vídeo publicado o mensaje claro por cupo/email; seguidores y métricas por post con «visualizaciones» no disponibles | T-30 |
| P3-M-02 | **Smoke real de Threads** (test-plan M-02; guía: [app de Meta](../guias/threads-app-meta.md)). Con la cuenta como *tester*: OAuth, texto con emojis al límite (anotar cuánto cuenta Threads frente a `TextLength`), imagen, vídeo, carrusel, cuota y métricas. Una cuenta que no sea *tester* debe ver el mensaje de Ajustes → Permisos del sitio web | Los 4 tipos se publican; el conteo conservador de emojis nunca deja pasar un texto que Threads rechace (si no coincide, ajustar `TextLength.php`, `textLength.js` y el fichero de casos); límites y métricas reales anotados; confirmado que no hace falta App Review | T-64 |
| P3-M-03 | **Giphy: términos y atribución** (test-plan M-03; guía: [Giphy](../guias/giphy-api-key.md)). Leer los términos de Giphy sobre descargar y republicar el MP4 (⚠️ no verificados en la guía) y comprobar «Powered by GIPHY» en móvil | Los términos permiten republicar (si no, dejar `GIPHY_API_KEY` vacío); la atribución es visible y legible a 360 px | T-66, T-75 |
| P3-M-04 | **Revisión visual de la UI nueva** (test-plan M-04), en móvil y escritorio: inbox con notas, vistas del calendario, «Todas las marcas», hashtags, «Escribe cuándo», GIF, formulario de Bluesky y aviso de etiqueta de Threads | Español correcto, sin textos cortados; estados vacío y de error comprensibles; foco visible y orden de tabulación lógico | T-03, T-10, T-17, T-32, T-44, T-50, T-66 |
| P3-M-05 | **Lenguaje natural con la IA real** (test-plan M-05). Probar «mañana a las 9» o «el próximo lunes por la tarde» con la clave de Anthropic y revisar el coste por llamada | Las propuestas son razonables y en la zona de la marca; el límite diario se respeta | T-49, T-50 |

Despliegue de esta iniciativa: rellenar en el `.env` de producción `THREADS_APP_ID`/`THREADS_APP_SECRET` y `GIPHY_API_KEY` (opcionales: sin ellos la red o el botón no aparecen), comprobar `ffprobe` y `ffmpeg` en el PATH de PHP-FPM y de los workers, y ejecutar `php artisan migrate`.

## Pendientes del servidor MCP

| Id | Qué hacer |
|---|---|
| M-F6-1 | Crear una key en `/api-keys`, conectar un cliente real (`claude mcp add --transport http scheduler https://<APP_URL>/mcp --header "Authorization: Bearer msk_..."`), probar `list_brands` y `create_post` en borrador y comprobarlo en la web |
| M-F6-2 | Ajustar `MCP_RATE_PER_MINUTE` y `SANCTUM_EXPIRATION` en producción; comprobar que Nginx/Hestia pasa la cabecera `Authorization` a PHP-FPM |

También quedan API-01..API-03 con `curl` contra `/mcp` (API-01 y API-03 ya cubiertos en Pest) y la revisión de cifrado de tokens y API keys.

## E2E con Playwright

Playwright no está instalado: no hay `playwright.config`, ni `tests/e2e/`, ni `node_modules/.bin/playwright`. Falta instalarlo y escribir E2E-01..E2E-12 (cada uno mapea a un criterio CA). Los E2E deben usar conectores simulados (`fake connector`). Hasta entonces la cobertura es solo Pest (669 tests al último cierre).

## Despliegue y servicios externos

| Pendiente | Detalle |
|---|---|
| Despliegue en el VPS | Dominio, TLS, BD, cron, Nginx, PHP-FPM, workers systemd (M-04) |
| Bucket R2 | Crear bucket y credenciales; CORS (`PUT`, `Content-Type`, exponer `ETag`); regla "Abort incomplete multipart uploads" a 1 día |
| Dominio público de media para TikTok | Dominio verificado en TikTok, idealmente bucket separado con borrado a 24 h |
| Páginas `/privacy` y `/terms` | Google ("En producción") y TikTok (auditoría) las exigen en el dominio real. **No existen en `routes/web.php`**: la ruta `/` solo sirve `Welcome`. Hoy están provisionales en el dominio ngrok del spike (⚠️ verificar), y hay que servirlas desde la app y cambiarlas en las consolas |
| Auditoría de TikTok | Política de privacidad, condiciones y vídeo demo; luego activar Direct Post ([decisiones por red](decisiones-por-red.md)) |
| Google | Reconectar el canal una vez (tokens emitidos en "Prueba" caducan a 7 días); re-ejecutar `--check` desde el 2026-10-26 |
| Meta | Renovar `data_access` cada 90 días con re-login |
| Borrar contenido de prueba | Las 2 imágenes de IG y el vídeo `f-pIxNXaDq0` de YouTube del spike se borran a mano |

## Decisiones de producto por confirmar

Listadas en [configuración](configuracion.md#resumen-de-decisiones-por-confirmar): `PUBLISH_QUOTA_POLICY`, `max_lateness_hours`, `PUBLISH_RECONNECT_GRACE_HOURS` (72 h), `META_OAUTH_METHOD`, borrador de TikTok = publicada con nota y secciones del informe. Además, T-12 sigue `en-progreso` por M-02, T-37 por M-03 y T-41 por la aprobación del diseño.
