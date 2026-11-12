# Pendientes manuales

Lo que requiere cuentas reales, el VPS o juicio del usuario. Fuente: [tasks.md](../roadmap/2026-10-19-social-scheduler/tasks.md) (ledger) y [test-plan.md](../roadmap/2026-10-19-social-scheduler/test-plan.md). Revisión del 2026-10-31: M-01 a M-04 siguen pendientes. M-05 ya estaba resuelto por la decisión G1 registrada en T-05 el 2026-10-19; se corrige su clasificación documental.

## Pruebas manuales M-01..M-05

| Id | Qué hacer | Criterio de aceptación | Tareas |
|---|---|---|---|
| M-01 | Publicar de verdad los 12 tipos originales y sus 13 variantes de media (IG post/carrusel/reel/story de foto y de vídeo, FB texto/foto/vídeo/reel, YouTube vídeo y short, TikTok vídeo y foto) | Cada tipo queda publicado con enlace/ID y visibilidad conforme a G1 | T-21, T-22, T-23, T-26, T-27 |
| M-02 | Conectar IG, FB, YouTube y TikTok reales con ngrok; desconectar, reconectar, renovación de tokens | Sin errores; tokens cifrados en BD | T-12..T-16 |
| M-03 | Revisar dashboards y PDF con datos reales (job diario ~30 días). Diseño y secciones de T-41 aprobados por el usuario el 2026-10-31 sobre el PDF de muestra | Queda la validación de métricas reales para T-37/T-43; T-41 ya está cerrada | T-37, T-39, T-43 |
| M-04 | Comprobar operación en el VPS HestiaCP ([despliegue](../modulos/despliegue.md)); el usuario indica el 2026-10-31 que realizará estas pruebas | HTTPS y healthcheck operativos ya observados; falta inspeccionar cron/Hestia y demostrar reinicio de workers y recuperación tras caída | T-09, T-10 |
| M-05 — completado | Informe del spike y decisión G1 registrados el 2026-10-19; evidencia revisada el 2026-10-31 | [Veredictos y decisión](../roadmap/2026-10-19-social-scheduler/spike-report.md#decisión-g1): YouTube A, TikTok D + B, continuar con Meta; no acredita nuevas pruebas reales | T-01..T-05 |

## Conexión de clientes MCP

T-11 de [social-login-mcp-oauth](../roadmap/2026-10-22-social-login-mcp-oauth/tasks.md) acredita **Claude Code (M-03), Codex (M-05) y OpenCode (M-06)** el 2026-10-31: consentimiento del usuario, OAuth DCR sin API key MCP, callbacks registrados y una llamada real `list_brands` completada desde cada cliente. OpenCode usa su servicio local y el acceso ChatGPT autorizado por el usuario. El [registro y la evidencia saneada](../roadmap/2026-10-22-social-login-mcp-oauth/testing/smoke-ca29.md) conservan resultados y fallos anteriores sin tokens ni nombres de marcas.

Queda **M-04, Claude Desktop, aplazado expresamente por el usuario el 2026-10-31** («lo dejamos de momento»). No se retoma ni se solicita completar el consentimiento mientras siga ese aplazamiento. El formulario local había quedado preparado con OAuth y DCR; el clic del agente en «Añadir» fue rechazado antes de ejecutarse. La app mostraba límite de uso hasta «2 nov a las 7:00», sin zona horaria explícita. El conector previo al VPS permanece intacto. Los tres CLI funcionan sin refresh/CIMD para el flujo inicial probado; la renovación posterior y la conclusión global con Desktop siguen pendientes. T-11 permanece `en-progreso` con 3/4 clientes y conserva la limpieza de los tokens de prueba para el final del smoke completo.

## post-composer

T-41 y el cierre T-42 esperan M-01/M-04: una publicación real por YouTube, TikTok en borrador, Instagram y Facebook; Publicar ahora, ausencia de duplicados y opciones observadas en la plataforma. El [registro del smoke](../roadmap/2026-10-23-post-composer/testing/smoke-real.md) ya está preparado con las tablas de resultados y recuentos. Ninguna publicación real se da por ejecutada.

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
| P3-M-01 | **Smoke real de Bluesky** (test-plan M-01; guía: [app password](../guias/bluesky-app-password.md)). Conectar con una app password; publicar texto con enlace, hashtag y mención, 4 imágenes (una de más de 2 MB), un vídeo (con email verificado), un GIF de Giphy (comprobar que el PDS acepta `presentation: gif` y se ve en bucle) y leer métricas. Resolver los ⚠️ de `aud` del vídeo, `rkey` repetida y AppView público | Texto con enlace y hashtag clicables; 4 imágenes (la grande recomprimida); vídeo publicado o mensaje claro por cupo/email; seguidores y métricas por post con «visualizaciones» no disponibles | T-30 |
| P3-M-02 | **Smoke real de Threads** (test-plan M-02; guía: [app de Meta](../guias/threads-app-meta.md)). Con la cuenta como *tester*: OAuth, texto con emojis al límite (anotar cuánto cuenta Threads frente a `TextLength`), imagen, vídeo, carrusel, cuota y métricas. Una cuenta que no sea *tester* debe ver el mensaje de Ajustes → Permisos del sitio web | Los 4 tipos se publican; el conteo conservador de emojis nunca deja pasar un texto que Threads rechace (si no coincide, ajustar `TextLength.php`, `textLength.js` y el fichero de casos); límites y métricas reales anotados; confirmado que no hace falta App Review | T-64 |
| P3-M-03 | **Giphy: términos y atribución** (test-plan M-03; guía: [Giphy](../guias/giphy-api-key.md)). Leer los términos de Giphy sobre descargar y republicar el MP4 (⚠️ no verificados en la guía) y comprobar «Powered by GIPHY» en móvil | Los términos permiten republicar (si no, dejar `GIPHY_API_KEY` vacío); la atribución es visible y legible a 360 px | T-66, T-75 |
| P3-M-04 | **Revisión visual de la UI nueva** (test-plan M-04), en móvil y escritorio: inbox con notas, vistas del calendario, «Todas las marcas», hashtags, «Escribe cuándo», GIF, formulario de Bluesky y aviso de etiqueta de Threads | Español correcto, sin textos cortados; estados vacío y de error comprensibles; foco visible y orden de tabulación lógico | T-03, T-10, T-17, T-32, T-44, T-50, T-66 |
| P3-M-05 | **Lenguaje natural con la IA real** (test-plan M-05). Probar «mañana a las 9» o «el próximo lunes por la tarde» con la clave de Anthropic y revisar el coste por llamada | Las propuestas son razonables y en la zona de la marca; el límite diario se respeta | T-49, T-50 |

Despliegue de esta iniciativa: rellenar en el `.env` de producción `THREADS_APP_ID`/`THREADS_APP_SECRET` y `GIPHY_API_KEY` (opcionales: sin ellos la red o el botón no aparecen), comprobar `ffprobe` y `ffmpeg` en el PATH de PHP-FPM y de los workers, y ejecutar `php artisan migrate`.

## creative-social-suite

Pruebas con cuentas, claves, el VPS y juicio del usuario de la iniciativa [creative-social-suite](../roadmap/2026-11-03-creative-social-suite/tasks.md) (ledger y smokes detallados por tarea en su `tasks.md`; plan en su [test-plan](../roadmap/2026-11-03-creative-social-suite/test-plan.md)). **Todo lo de esta tabla está probado solo con simulaciones** (`Http::fake`, el Canva falso y los proveedores falsos del servidor E2E): ningún agente ha hablado con las APIs reales ni con el VPS.

| Id | Qué hacer | Criterio de aceptación | Tareas |
|---|---|---|---|
| CS-M-01 | **Smoke real de Canva** ([módulo](../modulos/canva.md)). Registrar la app en Canva Developers (scopes, Redirect URL `<APP_URL>/canva/callback`, Return URL `<APP_URL>/canva/return`), definir `CANVA_CLIENT_ID`/`CANVA_CLIENT_SECRET`, conectar con una cuenta Free y otra Pro, listar y crear diseños, volver desde el editor, exportar PNG/JPG/PDF/MP4, enviar un recurso y desconectar | Funciones según el plan con su motivo; el retorno resalta el diseño; la exportación llega a la biblioteca con «Origen: Canva»; si el host de descarga real no cumple `download_host_pattern`, se ajusta; el token queda revocado en Canva | T-16 |
| CS-M-02 | **Smoke real de LinkedIn** ([guía](../guias/linkedin-pinterest.md)). Registrar la app (Share on LinkedIn y Sign In with LinkedIn using OpenID Connect; Community Management API para páginas), OAuth con perfil y con una página, publicar texto, imagen, varias imágenes y vídeo, primer comentario; probar `LINKEDIN_COMMUNITY_MANAGEMENT` y `LINKEDIN_R_MEMBER_SOCIAL` solo si LinkedIn los aprobó | Publicaciones visibles en LinkedIn; la versión `LINKEDIN_API_VERSION` sigue vigente; sin los interruptores el perfil sigue publicando | T-18 |
| CS-M-03 | **Smoke real de Pinterest** ([guía](../guias/linkedin-pinterest.md)). Registrar la app (acceso de prueba hasta su aprobación), OAuth, publicar un pin de imagen, un carrusel y un vídeo con portada en un tablero real | Pines en el tablero elegido; sin tablero la app rechaza el destino antes de publicar | T-18 |
| CS-M-04 | **IA del estudio con proveedores reales** ([módulo](../modulos/estudio-ia.md)). Con `OPENAI_API_KEY` y `GEMINI_API_KEY`: generar, rellenar una zona con máscara, ampliar el lienzo y crear variaciones con los modelos por defecto `gpt-image-2.5-sunburst` y `gemini-nano-banana-2.1`; probar cuota agotada, clave inválida y descripción rechazada; con `ANTHROPIC_API_KEY`, titulares, relleno de plantilla y texto alternativo | Imágenes guardadas en los recursos de la marca; mensajes de cuota y error claros; un 429 transitorio se reintenta sin duplicar cuota | T-14 |
| CS-M-05 | **Opciones de Instagram**: publicar con `location_id` y con `user_tags` (solo imagen) en una cuenta real | La ubicación y las etiquetas aparecen en la publicación; el error de un id inválido es legible | T-18 |
| CS-M-06 | **Inbox de Threads, YouTube y Bluesky** ([inbox](../modulos/inbox.md)): reconectar para conceder `threads_read_replies`/`threads_manage_replies` y `youtube.force-ssl`, sincronizar y responder desde la app en cada red | Los comentarios se leen y la respuesta aparece en la red; el consumo de cupo de YouTube es el esperado | T-20 |
| CS-M-07 | **Worker `studio` en el VPS** ([despliegue](../modulos/despliegue.md#worker-de-la-cola-studio-mp4-y-gif)): instalar `deploy/systemd/scheduler-studio.service` (cgroupv2 con `Delegate=yes`), Node nativo, FFmpeg/ffprobe y Chromium de Playwright; exportar un MP4 y un GIF reales y comprobar la recuperación tras matar el worker | `systemd-analyze verify` sin errores; el MP4/GIF llega a la biblioteca; el límite de memoria corta el árbol completo | T-07, T-11 |
| CS-M-08 | **Render del estudio, quitar fondo y fuentes en el VPS**: Chromium de Playwright, Python con el modelo `isnet-general-use.onnx` y las tipografías de marca en el servidor | Exportación PNG/PDF y quitar fondo reales; las fuentes subidas se ven en el render | T-07, T-10 |

Despliegue de esta iniciativa: ver [despliegue](../modulos/despliegue.md) (redirect URIs nuevas, colas `media` y `studio`, requisitos de runtime) y las variables nuevas en [configuración](configuracion.md).

## Pendientes del servidor MCP

| Id | Qué hacer |
|---|---|
| M-F6-1 | Crear una key en `/api-keys`, conectar un cliente real (`claude mcp add --transport http scheduler https://<APP_URL>/mcp --header "Authorization: Bearer msk_..."`), probar `list_brands` y `create_post` en borrador y comprobarlo en la web |
| M-F6-2 | Ajustar `MCP_RATE_PER_MINUTE` y `SANCTUM_EXPIRATION` en producción; comprobar que Nginx/Hestia pasa la cabecera `Authorization` a PHP-FPM |

API-01 a API-03 ya tienen una ejecución HTTP automática contra `/mcp` en el entorno desechable de T-43, con key válida, key inválida y marca ajena. El [informe de QA](../roadmap/2026-10-19-social-scheduler/testing/report.md) conserva la evidencia. Esto no acredita la configuración del proxy de producción ni la conexión de un cliente MCP real. La revisión focalizada de cifrado y API keys también está ejecutada; la corrección local del flash cifrado aún no está desplegada.

## E2E con Playwright

Playwright está instalado. Los doce E2E originales, tres bloques API y cuatro guardias del harness pasan en escritorio y ambos tamaños móviles: **57 passed**, sin fallos, flaky ni omitidos. Los conectores externos están simulados; los controladores, jobs, worker, validación de media e informes usan la aplicación real sobre datos desechables. La batería general corregida pasa además **502 casos y omite los dos previstos de 504**, sin fallos, flaky ni casos sin ejecutar por errores. Consulta la [guía y ambos informes](../guias/pruebas-t43.md). Estos resultados automáticos conservan abiertos los manuales y no acreditan publicaciones reales ni el despliegue de los cambios locales.

## Despliegue y servicios externos

| Pendiente | Detalle |
|---|---|
| Despliegue en el VPS | Dominio, TLS, BD, cron, Nginx, PHP-FPM, workers systemd (M-04) |
| Bucket R2 | Crear bucket y credenciales; CORS (`PUT`, `Content-Type`, exponer `ETag`); regla "Abort incomplete multipart uploads" a 1 día |
| Dominio público de media para TikTok | Dominio verificado en TikTok, idealmente bucket separado con borrado a 24 h |
| Páginas `/privacy` y `/terms` | Las rutas públicas y las vistas legales ya existen en la app (`routes/web.php` y `resources/views/legal/`). Queda comprobar sus URLs y contenido publicados en el dominio definitivo, el contacto configurado y las URLs registradas en las consolas de Google/TikTok |
| Auditoría de TikTok | Política de privacidad, condiciones y vídeo demo; luego activar Direct Post ([decisiones por red](decisiones-por-red.md)) |
| Google | Reconectar el canal una vez (tokens emitidos en "Prueba" caducan a 7 días); re-ejecutar `--check` desde el 2026-10-26 |
| Meta | Renovar `data_access` cada 90 días con re-login |
| Borrar contenido de prueba | Las 2 imágenes de IG y el vídeo `f-pIxNXaDq0` de YouTube del spike se borran a mano |

## Decisiones de producto por confirmar

Listadas en [configuración](configuracion.md#resumen-de-decisiones-por-confirmar): `PUBLISH_QUOTA_POLICY`, `max_lateness_hours`, `PUBLISH_RECONNECT_GRACE_HOURS` (72 h), `META_OAUTH_METHOD` y borrador de TikTok = publicada con nota. El diseño y las secciones del informe están aprobados por el usuario desde el 2026-10-31 y T-41 está completada. T-12 sigue `en-progreso` por M-02 y T-37 por la parte de datos reales de M-03.

## Verificación en MySQL de T-06 (referencias de media con FK)

| Pendiente | Detalle |
|---|---|
| Probar la migración `2026_12_18_090000_create_post_target_media_tables` en MySQL 8 | Revisada a mano (nombres de índice y FK por debajo de 64 caracteres, tipos de FK iguales, `dropColumn` de una columna JSON sin índice, `down()` reconstruye el JSON) pero no ejecutada: aquí no hay MySQL. Ejecutar `migrate`, `migrate:rollback --step=1` y `migrate` sobre una copia de la base de datos; revisar el log por «Migracion post_target_media» (destinos marcados fallidos por media inexistente) |
| Borrar una cuenta con media por destino, series y autolistas | Comprobar en InnoDB que `ProfileController::destroy` no choca con las FK `restrict` (la purga previa está en `User::deleting`) |

## Copias de seguridad, GitHub y despliegue (audit-hardening T-09..T-11)

| Pendiente | Detalle |
|---|---|
| Probar `backup.sh` y `restore.sh` contra MySQL/MariaDB real y un destino externo | Solo se ensayaron con un `mysqldump` falso. En el VPS: crear `~/.backup-passphrase`, elegir `BACKUP_DEST` (R2/S3/rsync), programar el cron, restaurar en una BD vacía de prueba y comprobar `php artisan app:backup-verify`. Guía: [`deploy/backup/README.md`](../../deploy/backup/README.md) |
| Guardar `APP_KEY` y la frase de cifrado en un gestor de secretos | Sin ellos una restauración deja ilegibles tokens y 2FA |
| Ejecutar `deploy/github/protect-main.sh` (T-10) | Requiere ser admin del repo; no se ha ejecutado. Comprobar después con `gh api repos/:owner/:repo/branches/main/protection` |
| Probar `deploy/hestia/deploy.sh` y `rollback` en el VPS (T-11) | Solo se probó `--dry-run` y el orden de pasos; un despliegue real y un rollback reales son del usuario. Necesita `gh` autenticado en el servidor (o `--force-unverified`) |

## Verificación real de T-24 y T-25 (competidores y primer comentario)

| Pendiente | Detalle |
|---|---|
| Probar la migración `2026_12_20_090000_add_network_to_competitors` en MySQL 8 | Sube y baja con `php artisan migrate` / `migrate:rollback --step=1` sobre una base con competidores. El orden crea el único `(brand_id, network, username)` antes de borrar el antiguo `(brand_id, username)` (el error 1553 salta si se borra el único que sostiene la FK de `brand_id`); el test solo comprueba el orden. Comprobar también que el índice con `username` de 253 caracteres cabe (utf8mb4) |
| Smoke de competidores de Bluesky y YouTube | Seguir un handle real de Bluesky y un canal real de YouTube; ver snapshots, publicaciones destacadas y el gasto de cuota (`SOCIAL_COMPETITORS_YOUTUBE_UNITS`) |
| Smoke del primer comentario en Threads y Bluesky | Publicar con primer comentario en ambas redes; comprobar que no se duplica en un reintento y que el hilo muestra enlaces y menciones |
