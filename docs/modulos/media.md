# Media y validación

Dos modos según `MEDIA_DISK`. Con **`local`** (producción actual) el navegador envía el fichero a la app (`PUT media/{asset}/upload`), se guarda en `storage/app/private` y las redes lo descargan por URL firmada y temporal de la app. Con **`r2`** el navegador sube el binario directo a Cloudflare R2 con URLs prefirmadas y PHP-FPM nunca recibe el vídeo. Detalle, límites y diferencias entre `local` y `public` en [Despliegue → Almacenamiento de media](despliegue.md#almacenamiento-de-media).

## Flujo de subida

| Paso | Ruta HTTP | Código |
|---|---|---|
| Iniciar (valida MIME y tamaño) | `POST media` | `MediaUploadService::initiate()` |
| Subida (R2 prefirmado; en local `PUT media/{asset}/upload`) | — | `MediaController::upload` |
| Completar | `POST media/{asset}/complete` | `complete()` → `finalize()` |
| Borrar | `DELETE media/{asset}` | `delete()` |

A partir de `MEDIA_MULTIPART_THRESHOLD` (32 MiB) se usa multipart con partes de `MEDIA_PART_SIZE` (16 MiB). En `complete` se compara el objeto real con el tamaño declarado y con `max_bytes`; si excede, se borra.

`MediaProbe` ejecuta `ffprobe` (duración, fps, códec). En R2 baja solo los primeros y últimos `MEDIA_PROBE_EDGE_BYTES` (16 MiB), donde vive el atom `moov`. Un MP4 fragmentado se baja entero hasta `MEDIA_PROBE_FRAGMENTED_MAX_BYTES` (256 MiB); por encima se rechaza pidiendo `faststart`.

## Reglas por red y tipo

`config/media_specs.php` (versión `2026-10-19`) define las 13 reglas: número de elementos, texto máximo, MIME, tamaño, resolución, proporción, duración, fps y códecs. `MediaValidator` las aplica; `recommended` solo avisa. Ejemplo (`instagram_image`): solo JPEG, 8 MB, proporción 0,8–1,91, texto de 2.200 caracteres.

La validación en vivo del compositor usa `POST brands/{brand}/posts/validate`. Límite ya medido: TikTok exige >= 23 fps (`frame_rate_check_failed`).

## Límites y limpieza

| Variable | Defecto |
|---|---|
| `MEDIA_MAX_IMAGE_BYTES` | 30 MiB |
| `MEDIA_MAX_VIDEO_BYTES` | 4 GiB |
| `MEDIA_PENDING_TTL_HOURS` | 24 |

`app:media-prune` (cada hora) elimina subidas `pendiente`/`invalida` abandonadas y aborta sus multiparts. Complemento recomendado en R2: regla de ciclo de vida "Abort incomplete multipart uploads" a 1 día.

## URLs que reciben las redes

Instagram y Facebook descargan por URL firmada (`PUBLISH_MEDIA_URL_TTL`, 720 min). TikTok `PULL_FROM_URL` descarga sin firma desde `TIKTOK_VERIFIED_DOMAIN`: ver [despliegue](despliegue.md#tiktok-media-pública).

## Requisitos de despliegue

`ffprobe` en el PATH del usuario de PHP-FPM y de los workers, y CORS del bucket R2 (`PUT` desde el dominio, cabecera `Content-Type`, exponer `ETag`). Sin CORS la subida falla en el navegador aunque la URL prefirmada sea correcta.
