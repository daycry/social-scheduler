# Sitios: webs de varias páginas en `/s/{slug}`

Cada marca construye una web de varias páginas con el tema de su kit de marca y la publica en `<APP_URL>/s/{slug}`. Es un modelo propio (`App\Models\Site`), distinto de SmartLinks (una sola página de enlaces) y de un diseño del estudio (un lienzo exportable).

## Piezas

| Pieza | Dónde |
|---|---|
| Rutas | `routes/sites.php` (constructor con sesión; públicas sin sesión, cookies de Laravel ni CSRF) |
| Servicios | `app/Services/Sites/{SiteService,SiteAccess,SiteRenderer}.php` |
| Controladores | `SiteController` (constructor), `PublicSiteController` (público) |
| Vistas | `resources/js/Pages/Sites/{Index,Edit}.vue`, `resources/views/sites/` |
| MCP | `manage_site` (`list`, `get_url`, `publish`, `unpublish`) con `sites:read` y `sites:write` |

## Constructor

Hasta 5 sitios por marca, 12 páginas y 40 bloques por página, con menú, reordenado de páginas y bloques y tema del kit (o color propio). Bloques: título, texto enriquecido (marcado propio `**negrita**`, `*cursiva*`, enlaces y listas; nunca HTML), imagen, galería, botón, YouTube (`youtube-nocookie`), formulario de contacto, diseño del estudio, acordeón/FAQ y encuesta con resultados agregados. La vista previa es un iframe móvil, tablet y escritorio que usa el **mismo render** que la ruta pública.

## Publicación

`publish` copia el borrador y el tema a una instantánea: editar solo cambia el borrador hasta volver a publicar (la interfaz muestra «con cambios sin publicar»). `unpublish` da 404 desde la petición siguiente. Las imágenes se sirven por `/s/{slug}/_m/{id}` solo si están en la instantánea.

| Acceso | Comportamiento |
|---|---|
| `public` | Cualquiera con la URL |
| `password` | Contraseña con hash; 5 intentos cada 5 min por IP y sitio |
| `token` | Enlaces con token (SHA-256), con caducidad y revocables |

La contraseña o el token se canjean por una cookie propia firmada; cambiar el modo o «revocar todo» cierra todas las sesiones. `noindex` es opcional; los sitios con contraseña o token nunca se indexan.

## Seguridad

Todo el contenido se escapa; la CSP es estricta (`default-src 'none'`, estilos con nonce, imágenes solo del propio origen, `frame-src` solo YouTube nocookie, `frame-ancestors 'none'`). El formulario de contacto lleva campo trampa, marca de tiempo firmada y límite de envíos, con un tope de 500 mensajes. Las visitas se cuentan por página y día sin guardar IP.

## Límites

Sin dominio propio del cliente (requiere DNS/TLS en el servidor), sin aviso por correo de los mensajes (se leen y borran en el panel), sin edición colaborativa y sin API REST (la paridad es el MCP). Pruebas: `tests/Feature/Sites/SiteTest.php` y `tests/e2e/sites.spec.ts`.
