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

## Importar desde una URL (C-04a)

El compositor (sección «Desde URL») y la herramienta MCP `import_media_from_url` importan una imagen o un vídeo de una URL pública. Solo con `MEDIA_DISK=local`: el parcial se anexa en disco y con `r2` responde con un error claro.

| Paso | Código |
|---|---|
| `POST media/import-url` (`throttle:10,1`; 422 si la URL no es segura o ya hay 3 importaciones vivas) | `MediaController::importUrl` → `MediaUploadService::importFromUrl()` |
| Estado sondeable (`bytes` y `total` para la barra de progreso) | `GET media/{asset}` → `MediaAsset::toPublicArray()` |
| Descarga por pasadas en la cola `media` | `ImportRemoteMedia` → `RemoteMediaImporter::run()` |
| Cierre: renombra el `.part` y valida tipo, tamaño y metadatos | `MediaUploadService::finalize()` |

- **Guardia SSRF** (`App\Services\Media\UrlGuard`): solo `http`/`https` en los puertos 80 y 443, sin credenciales; IDN a punycode; se resuelven A y AAAA y **todas** las IPs deben ser públicas (se bloquean privadas, loopback, link-local, metadatos, `100.64/10`, `198.18/15`, multicast y reservadas, y la IPv4 incrustada en `::ffff:0:0/96`, `64:ff9b::/96` y `2002::/16`); se rechazan `localhost` y los literales decimales, octales y hexadecimales. La conexión se fija a la IP validada (`CURLOPT_RESOLVE`) en cada pasada y en cada salto, sin proxy y solo con HTTP/HTTPS; las redirecciones se siguen a mano (máximo 5) revalidando cada `Location`.
- **Pasadas**: cada ejecución del job descarga como mucho `MEDIA_IMPORT_BUDGET_SECONDS` (80 s) con `Range` (y `If-Range` con el ETag o `Last-Modified` de la primera) y se reencola 1 s después. El punto de control es el tamaño del `media/{usuario}/{ulid}.part`. Si el remoto responde 200 a una reanudación, se reinicia una vez y, si vuelve a pasar, el asset queda `invalido`. Sin soporte de `Range` y sin terminar en una pasada: «El servidor no permite descargas por partes; descárgalo y súbelo».
- **Límites**: el `Content-Type` debe estar en `media.allowed_mimes`; se corta en `max_bytes` por los bytes reales aunque `Content-Length` falte o mienta; los primeros bytes se comprueban con `finfo` (un MIME falseado queda `invalido`) y `finalize()` repite la comprobación con el tipo real.
- **Operación**: cola `media` (`MEDIA_IMPORT_QUEUE`) con su worker en el cron `ss-media` ([despliegue](despliegue.md#cron-y-workers)). `QUEUE_NAMES` debe incluir `media` si se define en `.env`. `app:media-prune` borra los `.part` de más de 24 h que ningún asset `pendiente` use.

## Reglas por red y tipo

`config/media_specs.php` (versión `2026-10-19`) define las 13 reglas: número de elementos, texto máximo, MIME, tamaño, resolución, proporción, duración, fps y códecs. `MediaValidator` las aplica; `recommended` solo avisa. Ejemplo (`instagram_image`): solo JPEG, 8 MB, proporción 0,8–1,91, texto de 2.200 caracteres.

La validación en vivo del compositor usa `POST brands/{brand}/posts/validate`. Límite ya medido: TikTok exige >= 23 fps (`frame_rate_check_failed`).

## Límites y limpieza

| Variable | Defecto |
|---|---|
| `MEDIA_MAX_IMAGE_BYTES` | 30 MiB |
| `MEDIA_MAX_VIDEO_BYTES` | 4 GiB |
| `MEDIA_PENDING_TTL_HOURS` | 24 |
| `MEDIA_IMPORT_BUDGET_SECONDS` | 80 |
| `MEDIA_IMPORT_QUEUE` | `media` |

`app:media-prune` (cada hora) elimina subidas `pendiente`/`invalida` abandonadas y aborta sus multiparts. Complemento recomendado en R2: regla de ciclo de vida "Abort incomplete multipart uploads" a 1 día.

## URLs que reciben las redes

Instagram y Facebook descargan por URL firmada (`PUBLISH_MEDIA_URL_TTL`, 720 min). TikTok `PULL_FROM_URL` descarga sin firma desde `TIKTOK_VERIFIED_DOMAIN`: ver [despliegue](despliegue.md#tiktok-media-pública).

## Requisitos de despliegue

`ffprobe` en el PATH del usuario de PHP-FPM y de los workers, y CORS del bucket R2 (`PUT` desde el dominio, cabecera `Content-Type`, exponer `ETag`). Sin CORS la subida falla en el navegador aunque la URL prefirmada sea correcta.
