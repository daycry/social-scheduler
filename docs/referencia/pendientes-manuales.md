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
| P2-M-01 | **Primer comentario.** Publicar un post de prueba con primer comentario en Instagram, Facebook y YouTube (este último tras reconectar el canal con `youtube.force-ssl`); forzar un fallo y reintentar | Un único comentario de la propia cuenta en cada red, también tras reintentar; sin reconectar, YouTube avisa «Reconecta el canal para comentar» |
| P2-M-02 | **Picker real de Drive.** Con `GOOGLE_PICKER_API_KEY` y `GOOGLE_PICKER_APP_ID`, pulsar «Google Drive», conceder `drive.file` y elegir un vídeo de más de 100 MB | El botón aparece, el vídeo se importa en varias pasadas hasta `listo` por el cron `ss-media`, y `grep -c "ya29" storage/logs/*.log` da 0 |
| P2-M-03 | **Importación real por URL.** Importar con «Desde URL» una imagen y un vídeo públicos reales (con y sin `Accept-Ranges`) y una URL que redirija | Quedan `listo`; una URL a una IP interna se rechaza con el mensaje en español; el progreso y el aviso de cola parada se ven bien |
| P2-M-04 | **Campañas con datos reales.** Crear una campaña, asignar publicaciones ya publicadas y abrir su panel tras el snapshot diario | Las cifras agregadas coinciden con las de las publicaciones; la gráfica y el panel son legibles a 360 px |
| P2-M-05 | **Cuadrícula de Instagram.** Comparar la cuadrícula con el perfil real | El orden de lo publicado coincide con el perfil (salvo fijados); las programadas se distinguen y enlazan al compositor; el aviso de fijados es comprensible |

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
