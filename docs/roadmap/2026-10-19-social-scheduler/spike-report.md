# Informe del spike de plataformas — Social Scheduler

> Plantilla a rellenar durante T-01..T-05. Pegar evidencia real (IDs, respuestas de API, fechas). Sin datos aún.

## Preparación
| Plataforma | App creada (nombre/ID) | Modo (desarrollo/testing) | Roles/cuentas propias | Dominio HTTPS / redirect URI | Estado |
|---|---|---|---|---|---|
| Meta | «Social Scheduler» (1093743660194956), casos de uso Instagram + Páginas | Desarrollo (sin publicar) | Daycry Jordi (admin); Página Aipocalipsis (portfolio aipocalipsis) + IG @aipocalipsis_oficial | `/meta/callback` en FB Login for Business | listo |
| TikTok | «Daycry Social Scheduler» (7698380837338843143), sandbox «spike» | Sandbox (sin auditar) | target user @marblepartyrace | `/tiktok/callback` | listo |
| Google/YouTube | proyecto `social-scheduler-512115`, cliente web «Social Scheduler spike» | Testing (externo) | test user daycry9@gmail.com; canal «Jordi daycry» | `/google/callback` | listo |

Dominio HTTPS elegido (túnel / VPS Hestia): ngrok dev domain fijo `workable-humorous-rat.ngrok-free.app` (spike); en producción, subdominio del VPS Hestia.

## Meta
- (a) ¿Basta el modo desarrollo para publicar y leer insights en cuentas propias? **OAuth e insights de Página: sí** (2026-10-19, app en modo desarrollo, usuario con rol admin). Publicación: pendiente de prueba.
- (b) ¿Login de IG vía Facebook Login o Instagram Login? Motivo: en curso con Facebook Login for Business (`/dialog/oauth` + `scope` funciona; no exigió `config_id`). Las Páginas de **portfolios empresariales** no salen en `me/accounts`: hay que pedir `business_management` y leer `me/businesses` → `owned_pages`/`client_pages` (así apareció «Aipocalipsis», portfolio aipocalipsis, con IG `aipocalipsis_oficial` enlazada). Página de prueba: Aipocalipsis (1065269806670394).
- (c) ¿Existe `impressions` o solo `views`? Métricas aceptadas/rechazadas (Página, Graph v23.0): aceptadas `page_media_view`, `page_post_engagements`, `page_follows`; **rechazadas** `page_impressions` y `page_fans` (`(#100) must be a valid insights metric`, deprecadas). **IG (cuenta y media): `impressions` ya no existe** (rechazada en cuenta y en media) → usar **`views`**. Aceptadas en cuenta: `views`, `reach`, `profile_views`, `accounts_engaged`; `follower_count` no admite `metric_type=total_value` (pedirla como serie diaria). Aceptadas en media: `views`, `reach`, `likes`, `comments`, `shares`, `saved`, `total_interactions`. `content_publishing_limit` (edge) responde OK.
- Hallazgos de configuración (gotchas para F2): en apps por casos de uso, cada permiso debe **añadirse dentro del caso de uso** («Invalid Scopes» si no); hace falta el dominio en «Dominios de la app» + plataforma Sitio web; el redirect se registra en *Facebook Login for Business → Configurar* (el primer guardado no persistió: verificar recargando).
- Token: larga duración sí; `data_access_expires_at` 2027-01-17 (90 días, renovar con re-login).
- Tipos publicados con evidencia (FB texto/foto, IG imagen/reel/story): **FB foto OK** en Página Aipocalipsis (post_id `1065269806670394_122137968398744480`, 2026-10-19). **IG imagen OK** en @aipocalipsis_oficial (media `18099448502453789` y `18139303639528036` — duplicada por relanzar el comando; flujo contenedor → `media_publish`). Reel/story y FB texto: no probados (sin URL pública de vídeo). ⇒ **El modo desarrollo basta para publicar en cuentas propias.**
- Límites observados (publicaciones/24h, tamaños, caducidad de tokens):
- Veredicto: viable / limitado / no viable

## TikTok
- Permisos y `privacy_level_options` devueltos por `creator_info/query`: scopes `user.info.basic,video.publish,video.upload` concedidos (app «Daycry Social Scheduler», **Sandbox «spike»**, cuenta `marblepartyrace` como target user). `privacy_level_options`: `PUBLIC_TO_EVERYONE, MUTUAL_FOLLOW_FRIENDS, SELF_ONLY`; `max_video_post_duration_sec` 600. Gotcha: `creator_info/query` exige cuerpo JSON objeto `{}` (con `[]` → `invalid_params`).
- ¿Solo `SELF_ONLY` sin auditoría? **Sí, en la práctica**: aunque `creator_info` ofrece `PUBLIC_TO_EVERYONE`, publicar con ella (FILE_UPLOAD, Libre.mp4) devuelve `unaudited_client_can_only_post_to_private_accounts` (log_id 20261019172543DE641D79FF0A7619607D). Sin auditoría solo se puede publicar si **la cuenta de TikTok es privada** → publicación automática pública NO viable sin auditoría.
- ¿PULL_FROM_URL exige dominio verificado?
- Duración access/refresh token: access 86400 s (24 h), refresh 31536000 s (365 días) — medido 2026-10-19.
- Límite de usuarios/día; coste y plazo de la auditoría:
- Veredicto: viable / limitado / no viable

## YouTube
- Visibilidad real del vídeo subido (¿privado forzado?): subido Libre.mp4 (3:48, vertical) pidiendo `public` → video `f-pIxNXaDq0`, `privacyStatus=public` tras `processed` (2026-10-19T17:26Z). **No se ha forzado a privado (provisional)**: la política de Google indica que los proyectos no verificados quedan bloqueados en privado; re-verificar con `--inspect=f-pIxNXaDq0` en 24 h y en la consola de YouTube Studio.
- Unidades de cuota por subida y subidas/día reales vs. volumen del usuario: estimado 1.600 ud/subida → ~6 subidas/día con el cupo por defecto (10.000). Contrastar en la consola de Cloud (Quotas).
- Caducidad del refresh token en Testing (7 días) — fecha de emisión / fecha de fallo: emitido 2026-10-19T17:19:40Z (canal «Jordi daycry», scopes youtube.upload + youtube.readonly). **Re-ejecutar `--check` a partir del 2026-10-26** para confirmar el fallo.
- OAuth en modo Testing: requiere que la cuenta esté en «Usuarios de prueba» (si no: «Acceso bloqueado… no ha completado el proceso de verificación»); con ella dentro, aviso «Google no ha verificado esta aplicación» → Continuar. Gotcha: el alta de test user desde la consola no persistió en los primeros intentos; verificar recargando.
- Proceso de verificación/auditoría de Google (plazo):
- Veredicto: viable / limitado / no viable


## Limpieza y borrado (2026-10-19)
- FB: post de prueba borrado por API con el token de Página (`pages_manage_posts` basta) → `success: true`.
- IG: `DELETE /{ig-media-id}` → `(#10) Insufficient permissions`: borrar exige **`instagram_manage_contents`** (no pedido). Las 2 imágenes se borran a mano.
- YouTube: `videos.delete` → 403 `insufficientPermissions`: `youtube.upload` no permite borrar; hace falta **`youtube`** o `youtube.force-ssl`. Vídeo `f-pIxNXaDq0` se borra a mano.
- ⇒ Para F2/F3: si el producto debe poder borrar o editar publicaciones, añadir esos scopes desde el principio.

## Tabla de viabilidad
| Plataforma | Publicar | Insights | Veredicto | Riesgo principal |
|---|---|---|---|---|
| Facebook | ✅ foto en Página (modo desarrollo) | ✅ `page_media_view`, `page_post_engagements`, `page_follows` | **viable** | Métricas deprecadas (`page_impressions`, `page_fans`); `data_access` caduca cada 90 días |
| Instagram | ✅ imagen (contenedor → `media_publish`) | ✅ cuenta y media con `views` (no `impressions`) | **viable** | Requiere IG profesional enlazada a Página; reel/story no probados |
| TikTok | ❌ Direct Post público: `unaudited_client_can_only_post_to_private_accounts` · ✅ **borrador a bandeja** (`inbox/video/init`) aceptado | n/a (no hay scope de analítica sin auditoría/Research) | **limitado** | Publicación pública exige auditoría de TikTok; vídeos ≥ 23 fps (`frame_rate_check_failed`) |
| YouTube | ✅ vídeo `public` sin forzar a privado | ✅ canal (Analytics API habilitada) | **viable** | Refresh token caduca a 7 días en «Testing»; cuota ~6 subidas/día |

## Decisión G1
Decidida el 2026-10-19 por el orquestador, con delegación expresa del usuario («toma la mejor decisión para TikTok y YouTube»).

- **Meta (FB + IG): continuar** sin cambios de alcance.
- **YouTube → A (continuar tal cual)**, con una medida: pasar la pantalla de consentimiento de Google de «Testing» a **«En producción» sin verificar** (uso personal, < 100 usuarios). Así el refresh token deja de caducar a los 7 días; el coste es el aviso «app no verificada» al conectar, aceptable para uso propio. Cuota: 10.000 ud/día (~6 subidas) basta para el volumen declarado; solicitar ampliación solo si se queda corta. Scopes: `youtube` (incluye borrar/editar) + `youtube.readonly` + `yt-analytics.readonly`.
  - Motivo: la publicación pública funciona sin verificación; el único bloqueo real era la caducidad semanal del token, que se elimina sin trámite.
- **TikTok → D + B (flujo asistido ahora, auditoría en paralelo)**:
  - **Por defecto**: publicar como **borrador en la bandeja de TikTok** (`video.upload`, `inbox/video/init`); el usuario recibe la notificación y termina de publicar en la app. Funciona sin auditoría (verificado).
  - **Direct Post** (`video.publish`) implementado detrás de una opción de configuración por cuenta (`tiktok.direct_post`), desactivada hasta que TikTok apruebe la auditoría; cuando se active, el mismo post pasa a publicarse directamente.
  - El usuario tramita la auditoría de TikTok en paralelo (requiere política de privacidad y condiciones publicadas en el dominio real + vídeo demo de la app).
  - Analítica de TikTok: solo lo que permita `user.info.stats`/`video.list` si se conceden; si no, fuera de v1 (se documenta).
  - Motivo: posponer TikTok (C) dejaría la red fuera; publicar solo en privado (A) no aporta valor; el borrador da el 80 % del valor (programar, subir y avisar) hoy, sin bloquear el resto.
- **Tareas afectadas**: ninguna pasa a `cancelado`. T-14/T-27/T-34 (TikTok) se implementan con el modo borrador por defecto + Direct Post bajo opción. T-13/T-26/T-33 (YouTube) sin cambios de alcance más allá de los scopes.

Meta: confirmación de continuar — **sí**
Decisión TikTok: **D + B** (borrador ahora, auditoría en paralelo, Direct Post bajo opción) · Decisión YouTube: **A** (continuar; consentimiento «En producción» sin verificar)
