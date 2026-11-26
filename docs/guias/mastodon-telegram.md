# Mastodon y Telegram: conectar y publicar

Social Scheduler conecta **Mastodon** con la dirección de tu instancia y un **token de acceso personal**, y **Telegram** con el token de un **bot** y el canal donde publica. Ninguna de las dos usa la ventana de «Autorizar» de otras redes: se pegan unos datos en **Conexiones → + Conectar**. Los tokens se guardan cifrados, no se vuelven a mostrar y puedes revocarlos desde cada servicio cuando quieras.

> Esta guía también es el paso previo del smoke real de Mastodon y Telegram (M-10 del plan de pruebas): la app se ha probado solo con simulaciones.

## Mastodon

### 1. Crear el token

1. Entra en tu instancia (por ejemplo `https://mastodon.social`) y abre **Preferencias → Desarrollo → Nueva aplicación**.
2. Ponle un nombre que reconozcas (`Social Scheduler`).
3. En **Permisos** deja marcados solo `write:statuses`, `write:media` y `read:accounts` (puedes quitar el resto, incluido `read`).
4. Guarda y abre la aplicación creada: copia **«Tu token de acceso»**.

### 2. Conectarlo

1. En **Conexiones** de la marca, tarjeta de **Mastodon**, pulsa **+ Conectar**.
2. En **Instancia de Mastodon** escribe la dirección de tu servidor (`mastodon.social`). Tiene que usar **https** y ser pública; no escribas ruta, puerto ni parámetros.
3. Pega el **Token de acceso** y pulsa **Conectar Mastodon**. La cuenta aparece como `tunombre@instancia`.

### 3. Qué se publica y con qué límites

- **Texto y de 0 a N imágenes** (hasta las que admita tu instancia, 4 por defecto), con el **texto alternativo** de cada imagen. Una imagen que la instancia aún procesa se espera unos segundos; si tarda, el envío se reintenta.
- Los límites son **los de tu instancia** (caracteres, número de imágenes, tamaño y formatos, longitud del texto alternativo). Se leen al conectar y se vuelven a leer solos antes de publicar si tienen más de un día. Si no se pudieron leer, se usan 500 caracteres, 4 imágenes y 8 MB. El compositor y la validación al guardar avisan con el límite de **esa** cuenta.
- **Visibilidad** (Ajustes de Mastodon, en cada destino): pública (por defecto), pública sin listar, solo seguidores o solo las personas mencionadas.
- Un reintento tras un corte no duplica la publicación (clave de idempotencia de Mastodon).
- Sin comentarios ni analítica de Mastodon en la app.

## Telegram

### 1. Crear el bot y hacerlo administrador del canal

1. En Telegram abre **@BotFather**, envía `/newbot`, elige nombre y usuario y copia el **token** que te da (`123456789:AAA…`).
2. Abre tu **canal** → **Administradores → Añadir administrador**, busca tu bot y actívale **Publicar mensajes**. Sin ese permiso la app no puede conectarlo.
3. Anota el canal: su **@usuario** público (`@micanal`) o, si es privado, su **id numérico**.

### 2. Conectarlo

1. En **Conexiones**, tarjeta de **Telegram**, pulsa **+ Conectar**.
2. Pega el **Token del bot** y escribe el **Canal** (`@micanal` o su id).
3. Pulsa **Conectar Telegram**. La app comprueba el bot, que el chat sea un **canal** y que el bot pueda publicar; si no, te dice qué falta.

### 3. Qué se publica y con qué límites

- **Mensaje de texto**: hasta **4096** caracteres, como texto plano (los `*`, `_` o `<` se publican tal cual, sin formato).
- **Foto**: **una sola**, JPEG o PNG de hasta 10 MB, con el texto como **pie de foto de hasta 1024** caracteres. Si tu texto es más largo, elige «Mensaje de texto» o acórtalo: el compositor lo avisa antes de publicar.
- Si el canal tiene @usuario público, la publicación enlaza a `https://t.me/<usuario>/<mensaje>`; en un canal privado no hay enlace público.
- Solo **canales**: no se pueden conectar grupos, supergrupos ni chats privados.
- Telegram no permite comprobar si un mensaje ya salió: si una publicación se corta tras enviar (tiempo agotado), **no se reintenta** y queda fallida con «revisa el canal»; mira el canal y, si el mensaje no está, reprograma.
- Sin comentarios ni analítica de Telegram en la app.

## Cuando algo falla

| Mensaje | Qué hacer |
|---|---|
| «Mastodon rechazó el token» | Comprueba que creaste el token **en esa instancia** y con los tres permisos; si lo revocaste, crea otro y vuelve a conectar. |
| «Esa instancia de Mastodon no es válida o no es pública» | Escribe solo el dominio con https; no se admiten direcciones internas, puertos ni rutas. |
| «Telegram no encuentra ese canal» | Revisa el @usuario o el id y que el bot ya esté dentro del canal. |
| «El bot no puede publicar en ese canal» | Hazlo administrador con el permiso **Publicar mensajes** (ver arriba). |
| Cuenta en «Requiere reconexión» | El token se revocó, el bot salió del canal o perdió el permiso: vuelve a **+ Conectar** con datos válidos; las publicaciones programadas se conservan. |
| «Telegram no confirmó el envío» | Mira el canal: si el mensaje salió, no hagas nada; si no, vuelve a publicar. |
| «Se reintentará» / límite temporal | Mastodon o Telegram pidieron esperar; la app reintenta sola cuando se libera el límite. |

## Revocar

Quita el acceso desde **Conexiones → Desconectar** (borra el token de la app) y, además, revoca el token en Mastodon (Preferencias → Desarrollo → la aplicación → Eliminar) o con `/revoke` en @BotFather.

## Límites conocidos

- Mastodon cuenta cada **URL como 23 caracteres** (el valor de tu instancia) y una mención remota `@usuario@dominio` como `@usuario`; la app cuenta igual, así que un enlace largo con parámetros (UTM) no te quita caracteres de más. Las reglas de conteo de la instancia que no estén en la documentación leída (por ejemplo, cómo trata las URL sin `http`) no están verificadas.
- Los permisos del token de Mastodon no se pueden comprobar al conectar: si faltan, el primer envío deja la cuenta en «Requiere reconexión».
- Formatos de foto de Telegram, ids numéricos de canal y los detalles de las APIs reales están **sin verificar con cuentas reales** hasta el smoke.
