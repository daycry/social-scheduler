---
titulo: Verificacion de campos de C-04 contra la documentacion vigente
fecha: 2026-10-23
---

# Verificacion de campos de C-04

Consulta hecha el 2026-10-23 sobre la documentacion oficial vigente. Veredictos: Confirmado, No confirmado o No existe.

| Campo | Veredicto | Fuente (version y fecha) | Decision |
|---|---|---|---|
| YouTube `notifySubscribers` (parametro de query de `videos.insert`, defecto `true`) | Confirmado | https://developers.google.com/youtube/v3/docs/videos/insert · YouTube Data API v3 · pagina actualizada 2026-10-15 | Entra en T-27 como `options.notify_subscribers` |
| YouTube `status.containsSyntheticMedia` (A/S content, en `insert` y `update`) | Confirmado | https://developers.google.com/youtube/v3/docs/videos/insert y /docs/videos · v3 · 2026-10-15 | Entra en T-27 como `options.contains_synthetic_media` |
| YouTube `status.selfDeclaredMadeForKids` | Confirmado | https://developers.google.com/youtube/v3/docs/videos · v3 · 2026-10-15 | Entra en T-27 como `options.made_for_kids` (hoy fijo a `false`) |
| YouTube `snippet.tags[]`: limite | Confirmado: 500 caracteres en total, las comas cuentan y un tag con espacio cuenta 2 mas por las comillas | https://developers.google.com/youtube/v3/docs/videos · v3 · 2026-10-15 | T-27 reserva el hueco de `targetTag` y recorta los tags del usuario con esa regla |
| YouTube `snippet.categoryId` | Confirmado (`snippet.categoryId`, ya usado) | https://developers.google.com/youtube/v3/docs/videos · v3 · 2026-10-15 | Entra en T-27 como `options.category_id` |
| TikTok `post_info.disable_duet` y `disable_stitch` (video) | Confirmado, solo video; el servidor las desactiva si la cuenta es privada o lo pide su privacidad | https://developers.tiktok.com/doc/content-posting-api-reference-direct-post · 2026-08-24 | Entran en T-28, solo video en publicacion directa |
| TikTok `post_info.disable_duet` / `disable_stitch` (foto) | No existe (la referencia de fotos no las lista) | https://developers.tiktok.com/doc/content-posting-api-reference-photo-post · 2026-08-24 | No se envian en fotos |
| TikTok `post_info.brand_content_toggle` y `brand_organic_toggle` (video y foto) | Confirmado; en foto solo con `post_mode = DIRECT_POST` | enlaces de direct-post y photo-post · 2026-08-24 | Entran en T-28 solo en publicacion directa |
| TikTok `is_aigc` (video) | Confirmado dentro de `post_info` | direct-post · 2026-08-24 | Entra en T-28 |
| TikTok `is_aigc` (foto) | Confirmado, pero en el nivel superior del cuerpo, no dentro de `post_info` | photo-post · 2026-08-24 | Entra en T-28 en la posicion que dice la referencia |
| Instagram `is_ai_generated` (Graph API, creacion del contenedor) | Confirmado en la documentacion, pero implementarlo exige tocar `app/Connectors/Meta`, que esta fuera del alcance (ADR-005) | https://developers.facebook.com/docs/instagram-platform/content-publishing y /instagram-graph-api/reference/ig-user/media | No se ofrece (CA-16): queda oculto hasta que se abra el conector de Meta |

## Opciones aprobadas

- **YouTube (T-27):** `category_id`, `tags` (lista), `made_for_kids`, `notify_subscribers`, `contains_synthetic_media`.
- **TikTok (T-28, solo publicacion directa):** `disable_duet`, `disable_stitch` (solo video), `brand_organic_toggle`, `brand_content_toggle`, `is_aigc`.
- **Instagram:** solo `share_to_feed`, ya existente. La etiqueta IA queda oculta.
- **Se recuerdan** (presets): los interruptores `notify_subscribers`, `contains_synthetic_media`, `made_for_kids`, `disable_comment`, `disable_duet`, `disable_stitch`, `brand_organic_toggle`, `is_aigc`, `shorts_hashtag` y `share_to_feed`. No se recuerdan titulo, tags, enlace, categoria ni visibilidad, ni `brand_content_toggle` (la divulgacion comercial debe ser una decision explicita en cada publicacion; decidido en T-29).

## Verificacion de la revision T-33 (2026-10-23)

Consulta hecha el 2026-10-23 sobre la pagina oficial de directrices de uso de la Content Posting API de TikTok (https://developers.tiktok.com/doc/content-sharing-guidelines) y la referencia de Direct Post (https://developers.tiktok.com/doc/content-posting-api-reference-direct-post, vigente en esa fecha).

| Punto | Veredicto | Texto de la fuente | Decision |
|---|---|---|---|
| El contenido de marca (`brand_content_toggle`) no puede ser privado | Confirmado, como requisito de la interfaz del cliente de la API | «Before selecting the privacy/visibility setting, if a user has turned on the commercial content disclosure toggle and checked the branded content option, then the "only me" permission should be disabled, and hovering over it will display a prompt stating, "Branded content visibility cannot be set to private."» (directrices, apartado 3). La referencia de Direct Post no declara el error en el campo, solo describe `brand_content_toggle` como «paid partnership to promote a third-party business» | La regla se mantiene. Ademas el publicador **nunca** envia la combinacion: si la visibilidad resuelta es `SELF_ONLY` (pedida, o el respaldo cuando la cuenta no ofrece la pedida) con la marca activa, falla con el error permanente `marca_privada` y un mensaje claro |
| Etiqueta de «Your brand» (`brand_organic_toggle`) | Confirmado | «Your Brand: You are promoting yourself or your own business. This content will be classified as Brand Organic... a prompt should state: "Your photo/video will be labeled as 'Promotional content'"» | La pista de `composerOptions.js` usa «Your brand» y «Promotional content» |
| Etiqueta de «Branded content» (`brand_content_toggle`) | Confirmado | «Branded Content: You are promoting another brand or a third party. This content will be classified as Branded Content... "Your photo/video will be labeled as 'Paid partnership'"». Si se eligen ambas, la etiqueta es «Paid partnership» | La pista usa «Branded content» y «Paid partnership» |
| Declaracion previa al boton de publicar | Confirmado | Siempre: «By posting, you agree to TikTok's Music Usage Confirmation». Solo marca ajena o ambas: «By posting, you agree to TikTok's Branded Content Policy and Music Usage Confirmation» | Las pistas nombran la Confirmacion de uso de musica y la Politica de contenido de marca. **Pendiente M-xx:** el compositor muestra las pistas junto a los interruptores, pero no una declaracion literal junto al boton de publicar ni exige elegir al menos una opcion al activar la divulgacion; son requisitos de la auditoria de TikTok para publicar con visibilidad publica, que se revisan en el smoke real (T-41) y en la solicitud de auditoria |
