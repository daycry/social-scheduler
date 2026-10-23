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
