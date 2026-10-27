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

- **Guardia SSRF** (`App\Services\Media\UrlGuard`): solo `http`/`https` en los puertos 80 y 443, sin credenciales; IDN a punycode; se resuelven A y AAAA y **todas** las IPs deben ser públicas (se bloquean privadas, loopback, link-local, metadatos, `100.64/10`, `198.18/15`, multicast y reservadas, y la IPv4 incrustada en `::ffff:0:0/96`, `64:ff9b::/96` y `2002::/16`); se rechazan `localhost` y los literales decimales, octales y hexadecimales. Se bloquea también `168.63.129.16` (IP virtual de Azure). «Privada» y «no resuelve» dan el mismo mensaje («La URL apunta a una direccion no permitida.»), para que no sirva de oráculo de la red interna (CWE-204). La conexión se fija a la IP validada (`CURLOPT_RESOLVE`, lo único que va en la clave `curl`) en cada pasada y en cada salto, con las **opciones nativas de Guzzle** (`proxy => ''`, `allow_redirects => false`, `protocols => [http, https]`, `decode_content => false`, `progress` para el tope de bytes y el plazo): Guzzle 8 lanza `InvalidArgumentException` si se le pasan por `curl` las opciones que tienen una propia. Las redirecciones se siguen a mano (máximo 5) revalidando cada `Location`. `tests/Feature/Media/UrlSourceRealHandlerTest.php` usa el `CurlFactory` real y un servidor PHP local en `127.0.0.1` para que un cambio de versión de Guzzle no pase desapercibido.
- **Pasadas**: cada ejecución del job descarga como mucho `MEDIA_IMPORT_BUDGET_SECONDS` (80 s) con `Range` (y `If-Range` con el ETag o `Last-Modified` de la primera) y se reencola **sin espera** (con `--stop-when-empty`, una espera de 1 s dejaba unos 40 s muertos entre pasadas); el presupuesto reserva `MEDIA_IMPORT_FINALIZE_RESERVE_SECONDS` (10 s) para `finalize()`. El reencolado sin espera se acota por **ritmo** y no por edad (un remoto que gotea 1 B por pasada provocaba un bucle caliente; un tope fijo de 2 h desde `created_at` mataba importaciones grandes legítimas porque contaba la espera en cola y el reparto del único worker): (1) tres pasadas seguidas que no completan y traen menos de `MEDIA_IMPORT_MIN_PASS_BYTES` (64 KB) dan la importación por fallida («La descarga no avanza»); (2) el **ritmo medio** se mide sobre el tiempo de descarga efectivo (`download_seconds`, la suma de lo que duró cada pasada, sin la espera en cola): pasada la gracia `MEDIA_IMPORT_RATE_GRACE_SECONDS` (300 s) por debajo de `MEDIA_IMPORT_MIN_RATE_BYTES` (16 KB/s) se da por fallida («La descarga es demasiado lenta»); un 4 GiB a 500 KB/s tarda unas 2,4 h y completa; (3) el tope de pasadas es el mayor entre `MEDIA_IMPORT_MAX_PASSES` (200), los tramos que pide el tamaño total (`MEDIA_IMPORT_EXPECTED_CHUNK_BYTES`, 8 MB) y las pasadas que hacen falta al ritmo mínimo prometido (`total / (MEDIA_IMPORT_MIN_RATE_BYTES × 70 s de pasada)`), los dos con ×1,25 + 10: un fichero de 4 GiB permite 4692 pasadas (650 por tramos de 8 MB) y 1 GiB permite 1182, de modo que una descarga sana por encima de 16 KB/s no se corta por pasadas; el límite real de lentitud es el ritmo (2). Una pasada que acaba en error transitorio (5xx, 408/425/429, timeout, cuota de Drive) suma su duración a `download_seconds` y una a `passes` (y `failed_passes`) antes de reintentarse, así que un remoto que intercala 503 lentos acaba cortado por ritmo o por pasadas; un 503 suelto en una descarga sana no la da por fallida. El mensaje es «La descarga es demasiado lenta» (ritmo) o «necesita demasiadas pasadas» (tope). Además, una pasada incompleta más corta que `MEDIA_IMPORT_MIN_PASS_SECONDS` (1 s) espera el resto **dentro del job** (ritmo máximo de reencolado, acota la carga de un remoto que da ≥ 64 KB por pasada sin matar nada). No se vuelve a poner `delay`: es el equilibrio frente a los ~40 s muertos de `--stop-when-empty`. Equilibrio asumido: un remoto que sostenga menos de 16 KB/s de media tras 5 min de descarga efectiva falla a propósito, y el goteo de 1 B por pasada sigue acabando `invalido` en 3 pasadas. Cada pasada deja un latido (`runs`) en cuanto empieza, antes del primer byte, para que la UI distinga una cola parada (0 pasadas empezadas) de una pasada larga; la UI considera «empezada» una importación con bytes o con latido. El punto de control es el tamaño del `media/{usuario}/{ulid}.part`; el `path` final solo se guarda cuando `finalize()` termina. Si el remoto responde 200 a una reanudación, o declara otro total entre pasadas, se reinicia una vez y, si vuelve a pasar, el asset queda `invalido` con el motivo que se sabe: «no permite descargas por partes» (mismo validador) o «cambia durante la descarga» (otro validador o total). Un 4xx del remoto (salvo 408, 425 y 429) es permanente («El servidor remoto respondió 404.»); 5xx y esos tres se reintentan.
- **Límites**: el `Content-Type` debe estar en `media.allowed_mimes`; se corta en `max_bytes` por los bytes reales aunque `Content-Length` falte o mienta; los primeros bytes se comprueban con `finfo` (un MIME falseado queda `invalido`) y `finalize()` repite la comprobación con el tipo real.
- **Operación**: cola `media` (`MEDIA_IMPORT_QUEUE`) con su worker en el cron `ss-media` ([despliegue](despliegue.md#cron-y-workers)). `QUEUE_NAMES` debe incluir `media` si se define en `.env`. `app:media-prune` borra los `.part` de más de 24 h que ningún asset `pendiente` use. `app:queue-probe` sondea también `media`. El límite de 3 importaciones vivas se comprueba dentro de una transacción con la fila del usuario bloqueada.
- **Interfaz**: el sondeo no se solapa (candado) y se espacia de 2 a 15 s mientras no avanza; tolera 2 errores seguidos de consulta; «Cancelar» borra el asset de una importación en curso. Si sigue `pendiente` sin un byte tras 3 minutos, avisa de que «la cola de importación no está procesando», pasa a un sondeo lento cada 20 s (no abandona: si el worker vuelve, la importación se completa y el aviso se retira en cuanto llega un latido o un byte) y «Descartar» borra el asset (libera el cupo).

## Buscador de GIFs de Giphy (C-04b)

El botón **GIF** de «Imágenes y vídeos» abre un buscador de Giphy. Solo aparece con `GIPHY_API_KEY` (sin clave no hay botón y `GET /gifs` y `POST /media/import-gif` responden 404). Ninguna red del proyecto acepta `image/gif`: el GIF elegido se importa como su **MP4** y se valida como cualquier vídeo.

| Paso | Código |
|---|---|
| `GET gifs?q=&offset=` (limitador `giphy`, `SOCIAL_GIPHY_PER_USER_PER_MINUTE` = 20 por usuario y minuto; sin `q` devuelve las tendencias) | `GifController::search` → `GiphyClient::search()/trending()` (el servidor hace de *proxy*: la clave no sale al navegador ni a las props de Inertia, que solo llevan `giphy.enabled`) |
| `POST media/import-gif` (`throttle:10,1`; el servidor NO descarga la URL del navegador: extrae el id, pide el GIF a Giphy y usa el MP4 que Giphy devuelve, rechazando lo que supere `SOCIAL_GIPHY_RATING`; 422 si la URL no es de Giphy o ya hay 3 importaciones vivas) | `GifController::import` → `GiphyClient::mp4ById()` → `MediaUploadService::importFromGiphy()` (`source = giphy`) |
| Descarga en la cola `media` | `ImportRemoteMedia` → `GiphySource` (extiende `UrlSource`) → `RemoteMediaImporter::run()` |

- **Proxy y cupo**: respuestas en caché `SOCIAL_GIPHY_CACHE_MINUTES` (10); las llamadas **reales** a Giphy se cuentan por hora: tope global `SOCIAL_GIPHY_HOURLY_LIMIT` (90 de las 100 de la clave *beta*) y tope por usuario `SOCIAL_GIPHY_PER_USER_HOURLY_LIMIT` (30), para que uno solo no agote el de todos; agotados, agotadas, el usuario ve «Has hecho muchas búsquedas de GIF; prueba en unos minutos.» (también si Giphy responde 429). Una respuesta en caché no gasta cupo (la consulta se normaliza: mayúsculas y espacios no cambian la clave) y una clave de Giphy inválida (401/403) muestra «La clave de Giphy no es válida…», se registra sin la clave y no gasta cupo. Clasificación `SOCIAL_GIPHY_RATING` (`pg-13`). Ningún mensaje de error lleva la clave ni la URL de Giphy.
- **Sin SSRF nuevo**: no se descarga la URL que mande el navegador sin más. `GiphyClient::allowedMediaUrl()` exige `https`, puerto 443, sin usuario ni contraseña, un host `media*.giphy.com` / `i.giphy.com` (`social.giphy.media_host_pattern`) y ruta `.mp4`; el resto se rechaza antes de pedir nada. `GiphySource` repite la de esquema, puerto y host en **cada redirección** (la ruta `.mp4` solo se exige a la URL inicial) y la descarga pasa por `UrlGuard` (IP pública validada y fijada). Los resultados del buscador ya vienen filtrados con la misma regla.
- **Atribución**: el modal muestra siempre «Powered by GIPHY» (obligatorio en las condiciones de Giphy).
- **Compatibilidad por red**: los GIF suelen durar menos de 3 s; `config/media_specs.php` ya exige 3 s como mínimo en Reels de Instagram y Facebook y en TikTok, y el validador los rechaza con el mínimo. Bluesky y Threads lo aceptan como vídeo normal (Bluesky con `BLUESKY_VIDEO_MAX_BYTES`). No hay ffmpeg que los alargue: solo se avisa.
- **Pruebas**: Pest con `Http::fake` (`GiphyProxyTest`, `GiphyImportTest`, `GifCompatibilityTest`, `GiphyFlowTest`, `GifPickerPropsTest`) y E2E `tests/e2e/gif-picker.spec.ts`; nada llega a Giphy.

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
| `MEDIA_IMPORT_MIN_PASS_BYTES` | 65536 |
| `MEDIA_IMPORT_MIN_RATE_BYTES` | 16 KiB/s |
| `MEDIA_IMPORT_RATE_GRACE_SECONDS` | 300 |
| `MEDIA_IMPORT_EXPECTED_CHUNK_BYTES` | 8 MiB |
| `MEDIA_IMPORT_MIN_PASS_SECONDS` | 1 |
| `MEDIA_IMPORT_MAX_PASSES` | 200 |
| `MEDIA_IMPORT_QUEUE` | `media` |

`app:media-prune` (cada hora) elimina subidas `pendiente`/`invalida` abandonadas y aborta sus multiparts. Complemento recomendado en R2: regla de ciclo de vida "Abort incomplete multipart uploads" a 1 día.

## URLs que reciben las redes

Instagram y Facebook descargan por URL firmada (`PUBLISH_MEDIA_URL_TTL`, 720 min). TikTok `PULL_FROM_URL` descarga sin firma desde `TIKTOK_VERIFIED_DOMAIN`: ver [despliegue](despliegue.md#tiktok-media-pública).

## Requisitos de despliegue

`ffprobe` en el PATH del usuario de PHP-FPM y de los workers, y CORS del bucket R2 (`PUT` desde el dominio, cabecera `Content-Type`, exponer `ETag`). Sin CORS la subida falla en el navegador aunque la URL prefirmada sea correcta.
