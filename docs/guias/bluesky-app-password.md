# Bluesky: crear una app password y conectarla

Social Scheduler conecta Bluesky con una **app password** (contraseña de aplicación), no con tu contraseña de siempre. Esta guía te lleva de cero a una cuenta «Conectada», explica qué hace falta para publicar vídeo y cómo revocarlo todo.

> Esta guía también es el paso previo del smoke real de Bluesky (M-01 del plan de pruebas): hazla antes.

## 1. Antes de empezar

| Qué | Para qué |
|---|---|
| Una cuenta de Bluesky | Su **handle** completo, por ejemplo `tunombre.bsky.social` o tu dominio propio (`tunombre.com`) |
| Tu **email verificado** en Bluesky | Obligatorio para subir **vídeo** (ver más abajo). El texto y las imágenes funcionan sin él |
| La marca de Social Scheduler donde la quieras | La cuenta se conecta a una marca, igual que las demás redes |

## 2. Crear la app password en Bluesky

1. Entra en Bluesky (`bsky.app` o la app móvil) con tu cuenta.
2. Abre **Configuración → Privacidad y seguridad → Contraseñas de aplicación** (en inglés: *Settings → Privacy and security → App passwords*). En el navegador va directo: `https://bsky.app/settings/app-passwords`.
3. Pulsa **Añadir contraseña de aplicación** (*Add App Password*).
4. Ponle un nombre que reconozcas, por ejemplo `Social Scheduler`.
5. **No actives** la opción que da acceso a los mensajes directos: la app no los necesita.
6. Pulsa crear y **copia la contraseña** (formato `xxxx-xxxx-xxxx-xxxx`). Bluesky **solo la muestra una vez**: si la pierdes, crea otra.

> Usa siempre una app password. Si tecleas tu contraseña principal, Bluesky la rechaza y Social Scheduler no te la pide ni la acepta como válida.

## 3. Conectarla en Social Scheduler

1. Entra en **Conexiones** de la marca y busca la tarjeta de **Bluesky**.
2. Pulsa **+ Conectar**.
3. Escribe tu **handle** (sin `@`) y pega la **app password**.
4. Pulsa **Conectar Bluesky**. La cuenta debe aparecer como **Conectada**.

Si algo falla, el mensaje lo explica y **no se guarda nada**:

| Mensaje | Qué hacer |
|---|---|
| «Bluesky rechazó el handle o la app password» | Comprueba que pegaste la app password (no la contraseña principal) y que no se ha revocado. Crea otra |
| «Bluesky no reconoce ese handle» | Escribe el handle completo, con su dominio (`tunombre.bsky.social`) |
| «No se pudo conectar con Bluesky» | Es un fallo temporal: espera unos minutos y reintenta |
| Muchos intentos seguidos | El formulario limita a 10 intentos por minuto; espera un minuto |

## 4. Qué se guarda y cómo se protege

- La app password se guarda **cifrada** en la base de datos (columna `credential_secret`) y **nunca** vuelve al navegador, ni a los registros, ni a las pantallas.
- Se guardan también los tokens de sesión de Bluesky (cifrados) y el identificador permanente de tu cuenta (DID), que no cambia aunque cambies de handle.
- La app renueva la sesión sola. Si Bluesky la invalida, **crea otra sesión con la app password guardada** sin que tengas que hacer nada. Solo si la app password deja de valer, la cuenta pasa a **Requiere reconexión** (tus publicaciones programadas se conservan).

## 5. Publicar vídeo: email verificado y límite diario

- **Email verificado.** Bluesky solo permite subir vídeo con el email de la cuenta verificado. Verifícalo en Bluesky (*Configuración → Cuenta → Email*). Si no lo está, la publicación falla con un mensaje claro y no se sube nada; verifícalo y vuelve a programarla.
- **Límite diario.** Bluesky limita cuántos vídeos y cuántos bytes puede subir una cuenta cada día. Si el cupo se agota, la publicación se **aplaza** hasta que se libere (o falla con motivo «cuota», según la política `PUBLISH_QUOTA_POLICY`). El cupo real lo da Bluesky y se consulta antes de cada subida.
- **Tamaño.** En esta instalación el vídeo admite hasta **50 MB** (`BLUESKY_VIDEO_MAX_BYTES`) y 3 minutos; uno mayor se rechaza al guardar. Las imágenes admiten hasta **2 MB** (`BLUESKY_IMAGE_MAX_BYTES`): una mayor se publica como copia reducida, sin tocar tu original.
- Tras subirlo, Bluesky procesa el vídeo unos minutos: el destino aparece como «Procesando» y pasa a «Publicada» con su enlace.

## 6. Reconectar, cambiar de contraseña o dejar de usarla

- **Reconectar** (cuenta en «Requiere reconexión»): crea una app password nueva y vuelve a pulsar **+ Conectar**. No hay botón «Reconectar» porque no hay pantalla de permisos.
- **Dejar de usar la app:** desconecta la cuenta en **Conexiones** (se borran los tokens y la app password guardada) y **revoca la app password** en Bluesky (*Contraseñas de aplicación → Eliminar*). Revocarla en Bluesky corta el acceso al instante, también si olvidaras desconectar aquí.
- **Cambiaste de handle:** la cuenta sigue funcionando (se identifica por el DID). Si deja de hacerlo, reconéctala.

## 7. Variables de entorno (referencia)

| Variable | Por defecto | Para qué |
|---|---|---|
| `BLUESKY_ENABLED` | `true` | `false` quita Bluesky de Conexiones, compositor y filtros |
| `BLUESKY_IMAGE_MAX_BYTES` | `2000000` | Tope de imagen; bájalo a `1000000` si un PDS antiguo rechaza el blob |
| `BLUESKY_VIDEO_MAX_BYTES` | `52428800` | Tope de vídeo (50 MB) |
| `BLUESKY_UPLOAD_TIMEOUT` | `80` | Segundos de la subida del vídeo (una sola petición dentro del paso de 85 s) |
| `BLUESKY_DEFAULT_LANGS` | `es` | Idiomas (`langs`) de las publicaciones, separados por comas (máximo 3) |
| `BLUESKY_ENTRYWAY`, `BLUESKY_PLC_DIRECTORY`, `BLUESKY_APPVIEW`, `BLUESKY_VIDEO_SERVICE` | hosts públicos de Bluesky | Solo si necesitas apuntar a otro servicio |
| `SOCIAL_REFRESH_MARGIN_BLUESKY_HOURS` | `1` | Margen con que se renueva la sesión antes de caducar |

Detalle técnico: [conexiones](../modulos/conexiones.md#bluesky-handle--app-password), [publicación](../modulos/publicacion.md) y [decisiones por red](../referencia/decisiones-por-red.md).
