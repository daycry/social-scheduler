# Decisiones por red (Meta, YouTube, TikTok)

Resumen del spike de plataformas (2026-10-19) y de la decisión G1. Fuente completa y evidencia: [spike-report.md](../roadmap/2026-10-19-social-scheduler/spike-report.md).

## Veredictos

| Red | Publicar | Insights | Veredicto | Riesgo principal |
|---|---|---|---|---|
| Facebook | foto en Página (modo desarrollo) | `page_media_view`, `page_post_engagements`, `page_follows` | viable | métricas deprecadas; `data_access` caduca cada 90 días |
| Instagram | imagen (contenedor → `media_publish`) | cuenta y media con `views` | viable | exige IG profesional enlazada a una Página; reel y story no probados en el spike |
| YouTube | vídeo `public` sin forzar a privado | canal (Analytics API) | viable | refresh token caduca a 7 días en "Testing"; ~6 subidas/día |
| TikTok | Direct Post público bloqueado sin auditoría; borrador a bandeja aceptado | sin scope de analítica sin auditoría | limitado | publicar en abierto exige auditoría; vídeos >= 23 fps |

## Decisión G1 (2026-10-19)

Tomada por el orquestador con delegación expresa del usuario. Ninguna tarea se canceló.

| Red | Decisión | Consecuencia en el código |
|---|---|---|
| Meta | Continuar sin cambios | `MetaConnector`, publicadores de IG y FB |
| YouTube | **A**: continuar con consentimiento "En producción" sin verificar | El refresh token deja de caducar a 7 días; se acepta el aviso "app no verificada" al conectar. Scopes `youtube`, `youtube.readonly`, `yt-analytics.readonly` |
| TikTok | **D + B**: borrador ahora, auditoría en paralelo | Por defecto `inbox/video/init`; Direct Post tras opción por cuenta `meta.direct_post` |

## Meta (Facebook + Instagram)

Verificado en el spike (app en modo desarrollo, usuario admin):

- Basta el modo desarrollo para publicar en cuentas propias: FB foto OK (`post_id` `1065269806670394_122137968398744480`) e IG imagen OK.
- Facebook Login for Business funciona con `/dialog/oauth` + `scope`, sin `config_id`.
- Las Páginas de portfolios empresariales **no** salen en `me/accounts`: hay que pedir `business_management` y leer `me/businesses` → `owned_pages`/`client_pages`.
- En apps por casos de uso, cada permiso se añade dentro del caso de uso.
- Métricas: `page_impressions` y `page_fans` rechazadas (`(#100) must be a valid insights metric`); en IG `impressions` ya no existe, se usa `views`.
- Token de larga duración OK; `data_access_expires_at` a 90 días, se renueva con re-login.
- Borrar un medio de IG exige `instagram_manage_contents`; por eso está en los scopes de `config/social.php`.

Limitaciones: la cuenta debe estar en modo desarrollo con roles propios mientras no pase App Review; ⚠️ verificar publicación real de reel, story y FB texto (M-01).

## YouTube

- Subida de `Libre.mp4` pidiendo `public`: quedó `public` tras `processed`, sin forzarse a privado. ⚠️ Re-verificar a las 24 h con `--inspect` y en YouTube Studio.
- Cuota: 10.000 ud/día, ~1.600 por subida (≈ 6 subidas/día). La cuota es por proyecto de Google.
- En "Testing" hace falta estar en "Usuarios de prueba"; el alta de test user desde la consola no persistió en los primeros intentos: recargar y comprobar.
- `youtube.upload` no permite borrar (403 `insufficientPermissions`): por eso se pide el scope `youtube`.
- Consentimiento pasado a "En producción" el 2026-10-19; requiere página principal, `/privacy` y `/terms` publicadas (ver [pendientes](pendientes-manuales.md)). Los tokens emitidos en "Prueba" siguen caducando a 7 días: reconectar una vez.

## TikTok

- Scopes concedidos en sandbox: `user.info.basic`, `video.publish`, `video.upload`. `creator_info/query` devuelve `PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, `SELF_ONLY`.
- `creator_info/query` exige cuerpo JSON objeto `{}`; con `[]` devuelve `invalid_params`.
- Publicar con `PUBLIC_TO_EVERYONE` sin auditoría falla con `unaudited_client_can_only_post_to_private_accounts`. Sin auditoría solo se publica si la cuenta es privada.
- Tokens: access 24 h, refresh 365 días.
- Vídeos < 23 fps: `frame_rate_check_failed`.
- Analítica: sin `user.info.stats`/`video.list` concedidos, queda fuera de v1.

### Cómo funciona hoy

`TikTokPublisher` sube el vídeo a la bandeja (`/v2/post/publish/inbox/video/init/`, `FILE_UPLOAD` por trozos). El destino queda `publicada` con una nota; el usuario termina de publicar desde la app de TikTok. Las fotos/carrusel solo se ofrecen con `TIKTOK_VERIFIED_DOMAIN`.

### Activar Direct Post tras la auditoría

1. Presentar la auditoría de TikTok con política de privacidad y condiciones en el dominio real, y un vídeo demo.
2. Esperar la aprobación y confirmar que `video.publish` figura concedido.
3. Reconectar la cuenta si el scope cambió.
4. En *Conexiones* de la marca, activar la opción Direct Post de la cuenta (`PATCH brands/{brand}/connections/{account}/direct-post`; guarda `meta.direct_post = true`).
5. Publicar una prueba. Desde ese momento el mismo post se publica directamente, sin pasar por la bandeja.

Si TikTok responde `unaudited_client_can_only_post_to_private_accounts`, el destino falla de forma permanente: desactiva la opción.
