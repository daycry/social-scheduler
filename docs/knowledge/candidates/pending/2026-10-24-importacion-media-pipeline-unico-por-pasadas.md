---
proposed_by: architect
category: DECISION
evidencia: human_confirmed_rule
fuentes:
  - docs/roadmap/2026-10-24-metricool-parity-2/design.md (opción B1, validada por el usuario el 2026-10-24)
  - docs/roadmap/2026-10-24-metricool-parity-2/spec.md (§Decisiones de diseño, media remota)
  - docs/modulos/despliegue.md:84-97 y :204 (workers --timeout=120, MEDIA_DISK=local)
  - deploy/hestia/nginx-overrides.conf:15 (client_max_body_size 512m)
  - app/Services/MediaUploadService.php:119 (finalize)
tags:
  - area:media
  - area:seguridad
  - feature:importacion-media
  - iniciativa:2026-10-24-metricool-parity-2
---

# Importación de media desde URL y Drive: un solo pipeline en el servidor, por pasadas de 80 s con Range, punto de control en disco y guardia SSRF con IP fijada

## Contexto

Hay que importar ficheros remotos de hasta 4 GB, desde una URL arbitraria o desde Google Drive con `drive.file`. Los workers de cron de Hestia cortan los jobs a 120 s y `retry_after` vale 180 s. En producción, `MEDIA_DISK=local`. Una URL descargada por el servidor expone SSRF (CWE-918).

## Decisión

**Pipeline y job.**

- `RemoteMediaImporter` trabaja con dos fuentes, `UrlSource` y `DriveFileSource`.
- El job `ImportRemoteMedia` va en la cola `media`. Es `ShouldBeUnique` y `ShouldBeEncrypted`.

**Cada pasada.**

- Descarga con `Range: bytes={offset}-` e `If-Range`, durante un presupuesto de 80 s.
- Se vuelve a despachar hasta terminar.
- El punto de control es el tamaño del `.part` en el disco local.
- Al acabar, llama a `MediaUploadService::finalize()`.

**Guardia SSRF (`UrlGuard`).** Se aplica en el endpoint, en cada pasada y en cada redirección (seguidas a mano, como mucho 5).

- Solo admite `http` y `https` en los puertos 80 y 443.
- Resuelve A y AAAA, y todas las IPs deben ser públicas, IPv6 incluido.
- Fija la conexión a la IP validada con `CURLOPT_RESOLVE`.
- Aplica el tope de bytes reales.

**Drive.**

- El token de GIS se verifica con `tokeninfo`: `aud` y scope.
- Los metadatos se validan antes de crear el asset.
- El token viaja solo en el payload cifrado del job.

## Alternativas descartadas

- **Drive descargado por el navegador y vuelto a subir.** Con el disco local, la subida pasa por PHP-FPM y Nginx la corta a 512 MB. Además, exige la pestaña abierta y duplica el ancho de banda.
- **Worker `media` de vida larga (timeout de 3600 s) sin tramos.** Rompe la regla `--timeout` < `retry_after`. Un `queue:restart` del despliegue corta las descargas, y un job colgado bloquea la cola por `flock`.

## Consecuencias

La subida directa queda intacta. Para la importación, `media_assets` gana `source`, `source_ref` e `import_state`, y Hestia necesita una línea de cron para la cola `media`. Un servidor remoto sin `Range` solo funciona si el fichero cabe en una pasada; si no, el asset queda `invalido` con un mensaje claro. El *append* asume `MEDIA_DISK=local`: con R2, habría que reescribir la escritura como *multipart* por pasada.
