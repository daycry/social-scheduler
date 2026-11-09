# SmartLinks (link in bio)

Una página pública por marca en `/l/{slug}` con título, bio, avatar, bloques (enlace / cabecera) y tema. Gestión en `/smartlinks`.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/SmartLinkService.php` |
| Gestión | `SmartLinkController`, `resources/js/Pages/SmartLinks/Index.vue` |
| Pública | `PublicSmartLinkController`, `resources/views/smartlinks/show.blade.php` |
| URLs seguras | `app/Support/SafeUrl.php` |

- **Editor**: vista previa en vivo del microsite junto al formulario, prefijo visible `/l/` con ayuda para el slug, selector de color (muestra + hex), enlace «Ver página pública» y clics por bloque (con su cuota sobre el total de 30 días). Las visitas son un contador de la página.
- **Página pública**: sin login, sin sesión ni cookies, sin IP ni agente: solo contadores diarios agregados. Los enlaces pasan por `/l/{slug}/go/{bloque}` (cuenta el clic y redirige) con `rel="nofollow noopener"`. Lleva un aviso de contenido externo y el enlace **«Denunciar esta página»** (`mailto:` a `SMARTLINKS_REPORT_EMAIL`, por defecto `MAIL_FROM_ADDRESS`).
- **`SafeUrl::isSafe`**: solo `http`/`https` con host y sin credenciales; rechaza espacios y caracteres de control, los de control/formato Unicode (Cc, Cf, Zl, Zp: saltos, anchura cero, marcas bidi), `%00` (incluida la doble codificación) y hosts con **mezcla de escrituras** en una etiqueta (homógrafos IDN; el punycode se decodifica antes de comprobar). Se valida al guardar y de nuevo al redirigir.
- Abuso: es una instalación de uso personal; si no quieres cuentas nuevas, `REGISTRATION_ENABLED=false`.

## Bloques enriquecidos (T-20)

Tipos: `link` (botón), `header`, `image` (imagen propia con enlace), `youtube` (solo el ID de 11 caracteres de una URL de YouTube exacta; se pinta con `youtube-nocookie.com` y no hay ninguna consulta de red, así que no hay superficie SSRF), `divider`, `social` (iconos como texto accesible, hasta 8), `email` (formulario propio, un máximo por página) y `posts` (últimas publicaciones publicadas de la marca con URL https).

- **Visibilidad programada**: `visible_from`/`visible_until` en la zona horaria de la marca (se guardan en UTC); el bloque oculto no sale en la página ni responde en `go`.
- **Clics por bloque**: total, 7 días y CTR sobre las visitas. `go/{bloque}?i=N` solo elige entre las URL guardadas en el propio bloque (iconos): nunca redirige a un parámetro de la petición.
- **Formulario de email**: `POST /l/{slug}/subscribe/{bloque}` con 5 altas por minuto y origen, campo trampa, respuesta uniforme, tope por bloque (`SMARTLINKS_MAX_SUBSCRIBERS`) y CSV para el propietario (`smartlinks.subscribers`, con neutralización de fórmulas). Se guarda solo el email y la fecha.
- **XSS**: textos saneados al guardar (`strip_tags`, sin caracteres de control/formato) y escapados al pintar; la gestión no usa `v-html`; la página pública lleva `Content-Security-Policy` sin scripts y con `frame-src` solo para YouTube nocookie.
- **Fuera**: paridad API/MCP (SmartLinks no tiene herramientas MCP hoy), vista previa de URL remota (se evitó a propósito) y analítica por botón de cada icono social (se cuenta por bloque).
