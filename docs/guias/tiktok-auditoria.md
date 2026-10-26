# TikTok: solicitud de auditoría (Content Posting API · Direct Post)

Este material sirve para que TikTok audite la app y deje publicar directamente en el perfil (*Direct Post*). Hoy, sin auditoría, solo se mandan borradores a la bandeja. **Lo envías tú** desde [TikTok for Developers](https://developers.tiktok.com) → *Manage apps* → la app → *App review*. Aquí tienes los textos en inglés para pegar, el guion del vídeo y lo que hay que dejar listo antes.

> ⚠️ **Verifica en el portal.** Los nombres de los campos, los requisitos y las directrices («Content Sharing Guidelines») salen de cómo funciona la revisión según lo que sé, no se han leído en tu portal. Si un campo no existe o pide otra cosa, manda el portal.

## 1. Antes de enviar: lo que tiene que estar listo

| # | Requisito | Estado en Social Scheduler | Qué hacer |
|---|---|---|---|
| 1 | Términos y privacidad en el dominio de la app | ✅ `https://social-scheduler.daycry.ovh/terms` y `/privacy` | Pon esas URLs en la app (y no las de ngrok) |
| 2 | Redirect URI de producción | Revisa | Añade `https://social-scheduler.daycry.ovh/connections/tiktok/callback` en Login Kit |
| 3 | Dominio o URL prefix verificado | Pendiente (aplazado) | Solo hace falta para fotos y carruseles (`PULL_FROM_URL`). Para vídeo con `FILE_UPLOAD` no. Si TikTok lo pide, verifica `social-scheduler.daycry.ovh` |
| 4 | Visibilidad elegida por el usuario, con las opciones de `creator_info` | ✅ El desplegable solo ofrece las de `privacy_level_options`, sin valor por defecto. Programar o publicar sin elegirla da error (web y MCP) | — |
| 5 | Nombre o avatar de la cuenta que publica, visible al publicar | ✅ «Se publicará en <nickname>» con el avatar, sacados de `creator_info` | — |
| 6 | Interacciones (comentarios, Duet, Stitch) | ✅ Interruptores; los que la cuenta tiene desactivados en TikTok salen marcados y bloqueados | ⚠️ Verifica en las directrices si piden las interacciones **sin permitir** por defecto: hoy «Desactivar…» sale apagado (interacción permitida) salvo preset |
| 7 | Divulgación de contenido comercial | ✅ «Your brand» y «Branded content», con su etiqueta y el bloqueo de «privado» | Comprueba en la demo que se ven los avisos |
| 8 | Texto de consentimiento **visible siempre antes de publicar** | ✅ Junto al botón, con enlace a la Music Usage Confirmation; con «Branded content» añade la Branded Content Policy | — |
| 9 | Duración máxima del vídeo según `max_video_post_duration_sec` | ✅ Se lee en el conector | — |
| 10 | Avisar de que el vídeo puede tardar en aparecer | ✅ Estado del destino («Publicando…», enlace al terminar) | — |
| 11 | La app no añade marcas de agua ni texto promocional | ✅ | — |

Los puntos 4, 5, 6 y 8 se resolvieron en la PR «TikTok: compositor conforme a las Content Sharing Guidelines». Despliégala antes de grabar la demo.

## 2. Textos para el formulario (inglés)

### App name / description

> **Social Scheduler** is a personal social media scheduling tool. The account owner connects their own Instagram, Facebook, YouTube and TikTok accounts, writes a post once, adapts the text per network and schedules it or publishes it immediately. For TikTok, the user uploads a video, chooses the privacy level and interaction settings offered by TikTok for their account, discloses commercial content when applicable, and the app publishes it to the user's own TikTok profile at the chosen time. The app also shows the publishing status and a link to the published video. It is used only by its owner to manage their own accounts; it does not access or post to other people's accounts.

### Website / Terms / Privacy

- Website: `https://social-scheduler.daycry.ovh`
- Terms of Service: `https://social-scheduler.daycry.ovh/terms`
- Privacy Policy: `https://social-scheduler.daycry.ovh/privacy`

### Products and scopes: why each one is needed

| Scope | Justification (paste) |
|---|---|
| `user.info.basic` | Used to identify the connected TikTok account (open_id, display name and avatar) so the user can see which account they are posting to and select it in the composer. |
| `video.upload` | Used to upload the user's video to TikTok with the Content Posting API (FILE_UPLOAD). Without Direct Post approval, videos are sent to the user's TikTok inbox as drafts so they can finish posting in the TikTok app. |
| `video.publish` | Used to publish the user's own video directly to their TikTok profile (Direct Post) at the time they scheduled, with the privacy level, interaction settings and commercial content disclosure they selected in the app. Posting only happens after an explicit action by the account owner (Schedule or Publish now). |

### Use case / How will you use the Content Posting API

> The account owner creates a post in the composer, selects their connected TikTok account and uploads a video. Before posting, the app queries creator_info and shows the TikTok account's nickname, the privacy levels available for that account (the user must choose one; there is no default), and the comment/Duet/Stitch settings (disabled when the account does not allow them). The user can disclose commercial content ("Your brand" or "Branded content"); branded content cannot be set to private. The consent text "By posting, you agree to TikTok's Music Usage Confirmation" (and the Branded Content Policy when applicable) is shown next to the Publish button. When the user schedules or publishes, a background job uploads the video with FILE_UPLOAD, polls the publish status and shows the result and the link to the post. The app never posts without the user's explicit action and never adds watermarks or promotional content.

### Testing instructions (si las piden)

> Log in at https://social-scheduler.daycry.ovh with the test credentials provided in this form. Go to Brands → (brand) → Connections and connect TikTok. Then go to Calendar → Create post, select the TikTok account, upload a video, choose a privacy level and click "Publish now". The status panel shows the progress and the link to the video once TikTok finishes processing.

> Si piden credenciales de prueba: crea un usuario de demo que **no sea tu cuenta**, sin datos personales y con una marca de prueba, y pon sus credenciales **solo en el formulario de TikTok**, nunca en el repositorio.

## 3. Guion del vídeo de demostración (2 a 4 min)

Grábalo en **producción** (`social-scheduler.daycry.ovh`, no ngrok), con la UI en un tamaño legible. En Windows: Win + Alt + R, o Recortes → Grabar. Sin cortes en los pasos de OAuth y de publicación.

1. **Inicio (5 s):** URL visible en la barra, con la sesión iniciada en el panel.
2. **Conectar TikTok (30 s):** Marca → Conexiones → «Conectar TikTok». Se ve la pantalla de autorización de TikTok **con los scopes** y vuelves a la app con la cuenta conectada (nombre y avatar).
3. **Activar Direct Post** en esa cuenta (la casilla «Publicar directamente (Direct Post)»).
4. **Crear el post (60 s):** Calendario → Crear publicación → elegir la cuenta de TikTok → subir un vídeo propio (sin música con copyright) → texto con un hashtag.
5. **Opciones de TikTok (45 s):** se ve el nombre de la cuenta de destino, se abre «Visibilidad» y se elige una (sin valor por defecto). Enseña comentarios, Duet y Stitch, y activa y desactiva la divulgación comercial para que se vean los avisos («Your brand» o «Branded content»: con este último no se puede elegir «Solo yo»).
6. **Consentimiento:** detente un segundo en el texto «By posting, you agree to TikTok's Music Usage Confirmation» junto al botón.
7. **Publicar (30 s):** «Publicar ahora». El estado cambia a «Publicando…» y luego a «Publicada» con el enlace.
8. **Resultado en TikTok (20 s):** abre el enlace o la app de TikTok y enseña el vídeo publicado con la visibilidad elegida.
9. **Desconexión (opcional, 10 s):** Conexiones → desconectar TikTok, para mostrar que el usuario controla el acceso.

Sube el vídeo (MP4) en el formulario o como enlace no listado, según lo que pida el portal.

## 4. Después de la aprobación

- Activa Direct Post por cuenta en Conexiones (por defecto sigue en borrador).
- Haz una publicación real en público para cerrar M-01 de TikTok.
- Si la auditoría da un límite diario de posts o de creadores, anótalo en `docs/referencia/decisiones-por-red.md`.
