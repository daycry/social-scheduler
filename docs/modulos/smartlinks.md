# SmartLinks (link in bio)

Una página pública por marca en `/l/{slug}` con título, bio, avatar, bloques (enlace / cabecera) y tema. Gestión en `/smartlinks`.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/SmartLinkService.php` |
| Gestión | `SmartLinkController`, `resources/js/Pages/SmartLinks/Index.vue` |
| Pública | `PublicSmartLinkController`, `resources/views/smartlinks/show.blade.php` |
| URLs seguras | `app/Support/SafeUrl.php` |

En `resources/js/Components/SmartLinks/`, `SmartLinkPreview` muestra los cambios del formulario conforme se editan. `SmartLinkSubscribers` reúne el listado, la descarga CSV y las acciones de borrado.

Las visitas y los clics se agrupan por día UTC. `DailyCounter` suma cada evento a su fila de estadísticas.

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

## Acortador propio (T-89)

Enlaces cortos `/s/{código}` que redirigen a una dirección y cuentan clics. Se gestionan en la pantalla de SmartLinks (sección «Enlaces cortos», componente `SmartLinks/ShortLinks.vue`) y desde el generador de UTM del compositor (casilla «Acortar el enlace y contar sus clics», que crea el enlace por la misma ruta).

- **Dónde viven**: modelo propio `ShortLink` (`short_links`, ligado al usuario con `BelongsToUser` y `ShortLinkPolicy`), no dentro de `SmartLink`: un SmartLink es un único microsite por marca, mientras que un acortador es una lista de muchos enlaces por usuario sin relación con la marca. Se incluyen en la exportación de datos (`enlaces-cortos`, `clics-de-enlaces-cortos`).
- **Código**: 8 caracteres de `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (sin 0/O/1/I), el primero siempre una letra, generados con `random_int` (no secuenciales; ~8·10¹¹ combinaciones); índice único y reintento ante colisión. Va en mayúsculas a propósito: la ruta de sitios `/s/{slug}` solo admite minúsculas, así que ambas rutas nunca coinciden (lo comprueba un test).
- **Destino**: solo `http`/`https` validado con `SafeUrl` al guardar y al redirigir; sin credenciales, sin `javascript:`/`data:`/relativas. No se resuelve el destino en el servidor. Máximo 500 enlaces por usuario (se comprueba bajo cerrojo de la fila del usuario, dentro de la transacción de alta); el destino no se edita (se crea otro).
- **Redirección** (`PublicShortLinkController`, sin sesión ni cookies, `throttle:web-public-parity`): 302 con `Cache-Control: no-store` (un 301 se cachearía y se saltaría el recuento y la desactivación), `X-Robots-Tag: noindex` y `Referrer-Policy: no-referrer`; no expone al dueño. Destino con dominio IDN (posible homógrafo): en vez del 302 se muestra una página intermedia (`shortlinks/notice`) con el dominio en punycode y un botón «Continuar»; el clic cuenta igual. Código inexistente: 404. Enlace desactivado: 410 con la página «Este enlace está desactivado», sin redirigir ni contar.
- **Clics**: `short_link_clicks` (`short_link_id`, `date`, `count`; único por enlace y día) con incremento atómico (`DailyCounter`). Sin IP, agente, referer, hash ni tabla de eventos por visita, y **sin deduplicar**: cada GET real cuenta. **HEAD y la precarga** (`Sec-Purpose`/`Purpose: prefetch|prerender`) redirigen pero no cuentan; los robots que hacen GET sí cuentan (no se pueden distinguir sin guardar datos personales).
- **Zona horaria**: el día es **UTC** en la base y en el listado (hoy, 7 días y desglose por día de 30 días).
- **Fuera**: MCP/API para el acortador (no se expone: es una pantalla personal y de bajo riesgo; se añadiría con una herramienta `ManageShortLink` si se pide) y edición del destino.
