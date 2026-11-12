# LinkedIn y Pinterest: apps, permisos y tablero

LinkedIn y Pinterest se conectan por OAuth como las demás redes y publican por la cola `publish`. Cada red solo se ofrece si su app está configurada (`LINKEDIN_CLIENT_ID` o `PINTEREST_APP_ID` no vacíos y `*_ENABLED` distinto de `false`). Están probadas con simulaciones; los smokes reales son CS-M-02 y CS-M-03 en [pendientes manuales](../referencia/pendientes-manuales.md). La analítica de ambas no tiene lector: las métricas salen como «no disponible», nunca como cero.

## LinkedIn

**App.** En LinkedIn Developers, añade los productos «Share on LinkedIn» (permite `w_member_social`) y «Sign In with LinkedIn using OpenID Connect» (`openid`, `profile`). Registra la redirect URI `<APP_URL>/connections/linkedin/callback` y define `LINKEDIN_CLIENT_ID` y `LINKEDIN_CLIENT_SECRET`.

| Interruptor | Defecto | Efecto |
|---|---|---|
| `LINKEDIN_COMMUNITY_MANAGEMENT` | `false` | Pide también `w_organization_social`, `r_organization_social` y `rw_organization_admin` y ofrece las **páginas** que el usuario administra (hasta 20). Solo con la Community Management API aprobada por LinkedIn; sin él solo se conecta el perfil personal |
| `LINKEDIN_R_MEMBER_SOCIAL` | `false` | Pide `r_member_social`. Solo apps aprobadas. Sin él, un perfil personal no tiene búsqueda de idempotencia (un reintento tras un fallo ambiguo no puede comprobar si ya se publicó) ni primer comentario |

**Token.** Dura unos 60 días. Solo las apps con programa de partner reciben `refresh_token`: sin él no se puede renovar y la app avisa «reconecta antes de» `LINKEDIN_RECONNECT_NOTICE_DAYS` (7) días antes de que caduque.

**Contenido** (`config/media_specs.php`):

| Tipo | Reglas |
|---|---|
| `linkedin_text` | Texto de hasta 3000 caracteres |
| `linkedin_image` | 1 imagen JPEG, PNG o GIF de hasta 36 MB, con texto alternativo |
| `linkedin_carousel` | 2 a 20 imágenes (publicación `multiImage`) |
| `linkedin_video` | 1 MP4 de 3 s a 30 min y hasta 500 MB, subido por rangos |

Opciones por destino: `linkedin_visibility` (`PUBLIC` o `CONNECTIONS`; las páginas solo `PUBLIC`) y `linkedin_disable_reshare`. Primer comentario de hasta 1250 caracteres. La portada del vídeo no está soportada. La API REST se versiona por mes (`LINKEDIN_API_VERSION`, por defecto `202610`) y LinkedIn mantiene cada versión como mínimo 12 meses. La `202511` se retira el **16 de noviembre de 2026**; la última publicada a 2026-11-11 es la `202610`. Fuente: https://learn.microsoft.com/en-us/linkedin/marketing/versioning (consultada el 2026-11-11, actualizada por LinkedIn el 2026-10-15).

**Aviso de caducidad.** `php artisan app:health` (y `/health`) incluye la comprobación `linkedin_version`: avisa (estado `degraded`) cuando la versión configurada tiene 10 meses o más (`HEALTH_LINKEDIN_VERSION_WARN_MONTHS`) y la da por retirada a los 12 (`HEALTH_LINKEDIN_VERSION_RETIRED_MONTHS`), o si el valor no tiene formato `AAAAMM`. Para subirla, cambia `LINKEDIN_API_VERSION` y vuelve a probar publicar (smoke CS-M-02): cada versión puede cambiar el comportamiento de una llamada.

## Pinterest

**App.** En Pinterest Developers, crea la app (API v5). Las apps nuevas empiezan con acceso de prueba (sandbox) hasta que Pinterest las aprueba. Scopes: `boards:read`, `pins:read`, `pins:write` y `user_accounts:read`. Redirect URI: `<APP_URL>/connections/pinterest/callback`. Define `PINTEREST_APP_ID` y `PINTEREST_APP_SECRET`.

**Analítica (T-21).** `PinterestInsightsReader` lee `GET /v5/user_account/analytics` (scope `user_accounts:read`) y `GET /v5/pins/{id}/analytics` (`pins:read` + `boards:read`); los cuatro scopes ya se piden al conectar, así que las cuentas existentes no necesitan reconectar. Alimenta el panel de la red, las mejores horas, los informes y el top de publicaciones. Todo lo que Pinterest no da (alcance, comparticiones, minutos reproducidos) es «no disponible», nunca 0.

**Sandbox frente a producción.** Con `PINTEREST_API_HOST` apuntando al sandbox (`https://api-sandbox.pinterest.com`) funcionan los tableros, el perfil (`/v5/user_account`) y publicar, pero **ni `user_account/analytics` ni `pins/{id}/analytics` están disponibles** (`x-sandbox: disabled` en la especificación OpenAPI oficial): la analítica queda «no disponible» hasta tener el acceso estándar de producción (`https://api.pinterest.com`). Hay que verificarlo con una cuenta real (smoke M-01, a cargo del usuario).

**Token.** El acceso dura 30 días y el refresh 60; el refresco rota el `refresh_token`.

**Tablero obligatorio.** Todo pin vive en un tablero: la opción `pinterest_board_id` es **obligatoria** y el destino se rechaza sin ella con el mensaje «Elige el tablero de Pinterest». Al conectar se leen los tableros (`meta.boards`, paginando con `bookmark` de 100 en 100 hasta 500) para elegirlo; si se alcanza el tope, falla una página o se agota el presupuesto de 10 s de la petición, la lista queda marcada como incompleta (`meta.boards_partial`) y Conexiones avisa («si no ves tu tablero, indícalo por su id»); en **Conexiones → Actualizar tableros** se vuelven a leer cuando creas otros en Pinterest; sin `boards:read` la cuenta se conecta igualmente y el tablero se indica por id. Antes de crear un pin se buscan los últimos del tablero para no duplicar un reintento.

| Tipo | Reglas |
|---|---|
| `pinterest_pin` | 1 imagen JPEG o PNG de hasta 20 MB; texto alternativo hasta 500 |
| `pinterest_carousel` | 2 a 5 imágenes |
| `pinterest_video` | 1 MP4 o MOV de 4 s a 15 min y hasta 128 MB (Pinterest admite 2 GB; el tope es de esta instalación), con portada opcional |

Opciones: `pinterest_title` (hasta 100), descripción (el texto, hasta 800) y `pinterest_link` (https, hasta 2048). Pinterest no tiene primer comentario en la API v5.

El vídeo se sube en streaming (nunca entero en memoria) a la URL prefirmada que devuelve `POST /v5/media`, en una sola petición de hasta `PINTEREST_UPLOAD_TIMEOUT` segundos (`100` por defecto), acotada además a lo que queda del presupuesto del paso (85 s del job de 110 s, con el registro del media incluido): sin al menos 30 s de margen el job se reprograma sin registrar nada, y un reintento reutiliza el media ya registrado mientras su URL prefirmada siga vigente (`PINTEREST_UPLOAD_URL_TTL`, `20` min); caducada o rechazada (400/403/404/410), se registra de nuevo una sola vez. Tras subir bien, la URL firmada se borra del progreso del destino. El tope de 128 MB (no 512) sale de que la subida es UNA petición dentro de ese paso: con el margen real de ~55 s en el peor caso necesita ~2,5 MB/s; subir más exigiría un paso propio con otro worker (`--timeout` y `retry_after` mayores que los actuales de 120 s y 180 s), que se descartó por exigir cambios de infraestructura de producción no verificables aquí. Con disco R2/S3 el tamaño real se toma de `Filesystem::size` (el stream remoto no lo declara). Un vídeo de más de 128 MB se rechaza al programar y, si ya estaba programado, falla de forma permanente al publicar («El video supera 128 MB para Pinterest»). Contrato de subida sin verificar contra la documentación de Pinterest (es una SPA sin texto accesible desde aquí): queda para el smoke M-01.

## Fuera de alcance

X (Twitter), Google Business Profile y Twitch no están implementados (deuda registrada en el ledger de la iniciativa). Detalle por red en [decisiones por red](../referencia/decisiones-por-red.md#linkedin-y-pinterest).
