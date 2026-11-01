# Añadir audio a un Reel de Instagram

La [tabla de audio por red](../roadmap/2026-10-31-metricool-composer-parity/testing/audio-by-network.md) recoge las seis conexiones actuales. Instagram ofrece selección de catálogo; TikTok ofrece música recomendada para fotos/carruseles; las demás conexiones publican el sonido incluido en el vídeo.

## En el compositor

1. Selecciona tu cuenta de Instagram y el formato **Reel**; adjunta el vídeo.
2. En las opciones de esa cuenta, abre **Audio del Reel → Añadir audio**.
3. Escribe el título/artista: la búsqueda se actualiza al dejar de escribir durante un instante. También puedes pulsar Enter o **Buscar audio**. Deja el texto vacío para ver tendencias y filtra música o audio original.
4. Escucha la pista cuando haya preescucha y pulsa **Elegir audio**.
5. Ajusta el volumen de la canción y del vídeo original (1–100). Guarda como borrador, programa o publica desde las acciones habituales.

**Cambiar audio** abre el catálogo. **Quitar audio** elimina la asociación de catálogo; el sonido que ya contiene tu archivo de vídeo permanece en el archivo. Al reabrir el borrador se recuperan la pista y los volúmenes. Cambiar a un formato distinto de Reel elimina la selección incompatible.

Si Meta devuelve otra página, aparece **Cargar más audios** y el número de pistas cargadas. Cada pulsación añade resultados sin repetir los ya presentes. Cambiar texto o filtro inicia una búsqueda nueva. Si falla una página, se mantienen las pistas anteriores y puedes reintentar.

## Qué cuenta y qué canciones sirven

Se necesita una cuenta profesional de Instagram conectada mediante Facebook, con el acceso de usuario vigente y permisos de publicación. Si falta ese acceso, el compositor pide reconectar y no busca con credenciales de otra cuenta. Meta puede limitar el catálogo según permisos, cuenta o territorio; no es toda la biblioteca de la app de Instagram.

La pista empieza desde el principio. La preescucha reproduce la pista sola; Meta no ofrece una previsualización de la mezcla final. Los volúmenes admitidos por el contrato actual son 1–100, no un interruptor de silencio. Si una URL temporal caduca, **Actualizar preescucha** consulta una nueva; la URL no se guarda en la publicación. Algunas pistas solo ofrecen escucha en Instagram o no tienen preescucha.

Si Meta rechaza la pista al publicar, el destino falla con su motivo: no se publica silenciosamente sin la canción. Los fallos temporales conservan los reintentos habituales. El resultado real depende del acceso de la aplicación a Meta y debe comprobarse con un smoke propio antes de darlo por validado en producción.

## Desde un agente MCP

El servidor anuncia 27 herramientas en una página (capacidad de página 30). Las dos nuevas son de lectura y requieren `brands:read`:

- `search_instagram_audio`: `account_id`, `q` opcional (máximo 200 caracteres), `type` opcional (`all`, `music`, `original_sound`) y `cursor` opcional. Devuelve `results` con el ID de pista, título, artista, duración y preescucha cuando existe, además de `next_cursor` y `has_more`.
- `get_instagram_audio`: `account_id`, `audio_id`. Recupera la pista y renueva su preescucha temporal. Un enlace a un Reel no es un identificador de audio.

Para continuar, llama de nuevo a `search_instagram_audio` pasando `next_cursor` como `cursor`, con la misma cuenta, `q` y `type`. Repite mientras `next_cursor` no sea `null`, reuniendo las pistas por `audio_id` para evitar duplicados. El cursor caduca a los 30 minutos; si cambias cuenta, texto o filtro, omítelo para empezar de nuevo. No uses URLs de paginación del proveedor.

Usa primero `list_accounts` para obtener una cuenta propia y consultar `instagram_audio.available` y su `reason`. Tras elegir una pista de los resultados, pasa la selección a `create_post` o `update_post` (que siguen requiriendo `posts:write`):

```json
{
  "account_id": 17,
  "content_type": "instagram_reel",
  "options": {
    "instagram_audio": {
      "audio_id": "587784541076604",
      "audio_volume": 80,
      "video_volume": 25
    }
  }
}
```

Es un elemento de `targets`, no una llamada completa. Los identificadores son ilustrativos: usa los de tu cuenta y del catálogo consultado. Los volúmenes omitidos valen 100 al publicar. `get_post` devuelve la selección guardada; `instagram_audio: null` la quita al editar. No envíes títulos, enlaces o URLs de descarga en lugar de `audio_id` ni guardes URL temporales dentro de `options`.

## Música recomendada en fotos de TikTok

Con una cuenta en publicación directa y un dominio de fotos verificado, elige **Fotos (carrusel)** y activa **Añadir música recomendada (TikTok elige la canción)**. Se decide por publicación y no se recuerda entre borradores nuevos. El MCP usa `targets[].options.auto_add_music: true` (o `false` para desactivarlo). No se acepta para vídeos ni para envíos a la bandeja de TikTok.

Esta opción no selecciona una canción concreta ni su fragmento. El catálogo de canciones de cuentas TikTok Business documentado por Metricool necesita otra integración; no está habilitado por este ajuste de Content Posting API. [Contrato oficial de fotos de TikTok](https://developers.tiktok.com/docs/en/content-posting-api-reference-photo-post).

Si falla la consulta de los permisos de tu cuenta, se muestra el motivo y **Reintentar consulta de TikTok**. El compositor conserva el contenido y espera tu acción; no lanza peticiones repetidas automáticamente.

## Si no aparece una canción

Prueba por separado el título y el artista, manteniendo el filtro **Todos** para incluir música y audio original. Los resultados son los que devuelve Meta para la cuenta elegida; no equivalen al catálogo completo de Instagram. Que una canción exista en Instagram o en una plataforma de música no garantiza que la búsqueda del API la devuelva.

La [comprobación de Aipocalipsis / Libre](../roadmap/2026-10-31-metricool-composer-parity/testing/catalogo-aipocalipsis-libre.md) no devolvió esa pista en las consultas concretas realizadas. La revisión ampliada sí detectó un defecto general: se ignoraban cursores que Meta devuelve sin URL de página siguiente. Se corrigió y se comprobó con 50 pistas distintas de una búsqueda real. Esa corrección no garantiza que una canción concreta aparezca: compara el resultado de la búsqueda exacta y la cuenta elegida antes de atribuirlo al catálogo.

Si la pista solo está disponible dentro de Instagram, puede añadirse desde la propia app al publicar allí. Si dispones del archivo y los permisos de uso, también puedes subir un vídeo que ya incluya el sonido; eso no garantiza que Instagram lo asocie a una pista nativa.

## Comprobaciones reales pendientes

La suite automática usa respuestas simuladas; el diagnóstico anterior añade consultas de catálogo reales, sin publicar. El [ledger](../roadmap/2026-10-31-metricool-composer-parity/tasks.md) mantiene abierto el smoke real: buscar una pista con tu conexión, guardar/reabrir y publicar una vez, comprobando en Instagram que se asoció la pista correcta; comprobar también música recomendada de fotos TikTok con una conexión elegible. En Facebook, YouTube, Bluesky y Threads se conserva el audio del vídeo: no se promete un selector de catálogo que nuestra conexión no ofrece.

Contrato consultado el 2026-10-31: [Instagram Audio API de Meta](https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api/). Referencia de producto: [audio en Metricool](https://help.metricool.com/es/como-anadir-audio-a-tus-instagram-reels-desde-metricool-2o2ks).
