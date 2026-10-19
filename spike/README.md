# Spike de viabilidad de plataformas (Fase 0, puerta G1)

Scripts PHP desechables (sin Laravel ni composer; solo `ext-curl`). Los hallazgos van a
`docs/roadmap/2026-10-19-social-scheduler/spike-report.md`. Versiones de API, scopes y nombres
de metricas cambian: lo marcado "verificar" hay que contrastarlo en la consola/docs oficiales.

## 0. Prerrequisitos comunes
- PHP >= 8.1 con `curl` (Laragon: `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`).
- `cp spike/.env.example spike/.env` y rellenar. `spike/.env` y `spike/.tokens-*.json` estan en `.gitignore`.
- **Dominio HTTPS** para los redirect URIs (las tres plataformas lo exigen; Google admite ademas `http://127.0.0.1`):
  - **Opcion A, tunel**: `cloudflared tunnel --url http://127.0.0.1:8080` (da `https://xxxx.trycloudflare.com`) o `ngrok http 8080`. La URL cambia en cada arranque salvo tunel con nombre: hay que actualizar los redirect URIs y `SPIKE_BASE_URL`.
  - **Opcion B, VPS con Hestia** (recomendada para TikTok, que exige dominio/prefijo verificado): crear `spike.tudominio.com` en Hestia con SSL Let's Encrypt y apuntarlo al puerto local mediante tunel SSH inverso (`ssh -R 8080:127.0.0.1:8080 vps`) con proxy Nginx, o copiar `spike/` al VPS y ejecutar ahi el probe. Los ficheros de media para PULL_FROM_URL (TikTok) e IG (descarga desde URL) deben estar en una URL publica HTTPS de ese dominio.
- Callback: en otra terminal `php -S 127.0.0.1:8080 spike/<plataforma>_probe.php` (el probe atiende `/<plataforma>/callback`, canjea el `code` y guarda el token en `spike/.tokens-<plataforma>.json`). Alternativa sin servidor: `php spike/<p>_probe.php --code=XXXX` con el `code` copiado de la URL de redireccion.

## 1. Meta (Facebook + Instagram)
1. https://developers.facebook.com/apps > **Create app** > caso de uso "Other" > tipo **Business**. Dejar en **Development mode** (no pasar a Live).
2. Productos: **Facebook Login (for Business)** e **Instagram** (API con Facebook Login; probar tambien "Instagram Login" para la pregunta (b)).
3. Facebook Login > Settings > **Valid OAuth Redirect URIs**: `<SPIKE_BASE_URL>/meta/callback` (= `META_REDIRECT_URI`), p. ej. `https://spike.example.com/meta/callback`.
4. App roles: tu cuenta como Admin/Developer/Tester. Cuenta IG **Business o Creator** enlazada a una **Pagina de Facebook** de la que seas admin.
5. Scopes que pide el probe (en modo desarrollo funcionan sin App Review para usuarios con rol): `pages_show_list, pages_read_engagement, pages_manage_posts, read_insights, instagram_basic, instagram_content_publish, instagram_manage_insights` (+ `business_management` si la pagina cuelga de un Business Manager).
6. App ID / App Secret (Settings > Basic) a `META_APP_ID` / `META_APP_SECRET`.
7. Media de prueba: JPG y MP4 vertical en URL publica HTTPS.

## 2. TikTok
1. https://developers.tiktok.com > Manage apps > **Connect an app** (app sin auditar). Si la consola ofrece **Sandbox**, crearlo y anadir tu cuenta como target user.
2. Productos: **Login Kit** y **Content Posting API** (activar **Direct Post**).
3. Login Kit > **Redirect URI**: `<SPIKE_BASE_URL>/tiktok/callback` (= `TIKTOK_REDIRECT_URI`), HTTPS, sin query ni fragmento, p. ej. `https://spike.example.com/tiktok/callback`.
4. Scopes: `user.info.basic, video.publish, video.upload`.
5. **URL prefix / dominio verificado** (para PULL_FROM_URL): en la Content Posting API, "Verify URL properties": registrar el prefijo `https://spike.tudominio.com/media/` (o el dominio) y publicar el fichero/meta de verificacion que indique TikTok. Las URL de `TIKTOK_TEST_VIDEO_URL`/`TIKTOK_TEST_PHOTO_URL` deben empezar por ese prefijo.
6. Client key / Client secret a `.env`.
7. Sin auditoria TikTok limita a posts `SELF_ONLY` y a pocos usuarios/dia: el spike debe medirlo y anotar plazo y requisitos de la auditoria (apartado "Audit" de la app).

## 3. YouTube (Google Cloud)
1. https://console.cloud.google.com > nuevo proyecto.
2. **APIs & Services > Library**: habilitar **YouTube Data API v3** y **YouTube Analytics API**.
3. **OAuth consent screen**: External, estado **Testing**; tu cuenta en **Test users**. Anotar la fecha: en Testing el refresh token caduca a los 7 dias.
4. **Credentials > OAuth client ID > Web application**; **Authorized redirect URI**: `<SPIKE_BASE_URL>/google/callback` (= `GOOGLE_REDIRECT_URI`), p. ej. `https://spike.example.com/google/callback` (o `http://127.0.0.1:8080/google/callback`).
5. Scopes: `https://www.googleapis.com/auth/youtube.upload` y `https://www.googleapis.com/auth/youtube.readonly` (para leer visibilidad del video subido).
6. Proyectos sin *API Services Audit*: los videos subidos via API quedan **privados** (verificar). El probe lo comprueba con `videos.list`.
7. Cuota: consola > YouTube Data API v3 > Quotas; `videos.insert` cuesta 1600 unidades (verificar) con cupo por defecto 10 000/dia.

## 4. Ejecutar los probes
Sin credenciales todos salen con exit 2 indicando la variable que falta.

| Paso | Meta | TikTok | YouTube |
|---|---|---|---|
| URL de login | `php spike/meta_probe.php --auth` | `php spike/tiktok_probe.php --auth` | `php spike/youtube_probe.php --auth` |
| Callback | `php -S 127.0.0.1:8080 spike/<p>_probe.php` o `--code=XXX` | idem | idem |
| Publicar | `--publish=fb_text\|fb_photo\|ig_image\|ig_reel\|ig_story` | `--publish=video_file\|video_url\|photo` | `--publish=video\|short` |
| Lectura | `--insights` | `--creator-info`, `--status=<publish_id>` | `--inspect=<videoId>` |
| Resumen | `--check` | `--check` | `--check` |

`--check` imprime la tabla que pide la Verificacion del ledger (exit 0 solo si hay token valido y la consulta responde); pega su salida en `spike-report.md`.
Cada accion de publicacion/lectura guarda su resultado en `spike/.results-<p>.json` para que `--check` lo resuma.
