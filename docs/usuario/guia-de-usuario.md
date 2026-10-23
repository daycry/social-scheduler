# Guía de usuario

Social Scheduler programa tus publicaciones en Instagram, Facebook, YouTube y TikTok, y te muestra cómo van tus cuentas. Está pensado para uso personal.

## Primeros pasos

1. Regístrate y verifica tu correo.
2. Crea una **marca** (por ejemplo, tu proyecto o negocio). Cada marca agrupa sus cuentas, publicaciones y estadísticas.
3. Entra en las **Conexiones** de la marca y pulsa **Conectar** en cada red. Acepta los permisos en la pantalla de la red.

Si tienes varias páginas o canales, la app te pide elegir cuál conectar.

Avisos al conectar:

- **Google/YouTube**: puede aparecer "Google no ha verificado esta aplicación". Es esperado en uso personal: pulsa Continuar.
- **Instagram**: necesita una cuenta profesional enlazada a una Página de Facebook.

## Subir contenido

En la biblioteca de **Media** sube imágenes y vídeos. El archivo va directo al almacenamiento; la app comprueba formato, tamaño, duración y proporción. Instagram solo acepta imágenes JPEG. Si algo no cumple, te dice qué cambiar.

## Crear y programar una publicación

1. Pulsa **Crear publicación** y elige marca y cuentas de destino.
2. Elige el tipo por red (imagen, carrusel, reel, historia, vídeo, short…). El compositor te avisa de las limitaciones de cada tipo.
3. Escribe el texto, adjunta la media y elige fecha y hora (zona horaria de la marca).
4. Guarda como **borrador** o **programa**.

Estados que verás: Borrador, Programada, Publicando, Procesando, Publicada y Con error. Mientras sea Borrador o Programada puedes editar, reprogramar o borrar. Una vez en curso o publicada, ya no.

### El compositor

El compositor ocupa toda la pantalla. Arriba eliges las redes con pastillas; cada una muestra su contador de caracteres y avisa de sus límites.

- **Texto común y «Editar por red».** Escribe un texto que vale para todas las redes. Con **Editar por red** puedes darle a una red un texto propio (pestañas por red); las demás siguen el texto común. **Volver al texto común** descarta el propio de esa red (pide confirmación).
- **Opciones por red.** YouTube (categoría, etiquetas, público infantil, avisos a suscriptores, contenido sintético…) y TikTok (visibilidad, comentarios, contenido de marca…) tienen su panel. La app **recuerda por marca** los interruptores de cada red y los pone como valores iniciales la próxima vez; si la cuenta o el tipo de contenido no admite una opción, no se ofrece.
- **Notas.** Un campo de notas internas para tu equipo o para ti. Nunca se publican.
- **UTM.** El botón de enlaces abre un generador: escribes la dirección y los parámetros `utm_` (origen, medio y campaña son obligatorios) y **Añadir** inserta el enlace ya montado en el texto.
- **Emojis y asistente de texto** desde la barra del editor.
- **Programar, borrador y Publicar ahora.** El botón principal programa; el menú que lo acompaña (**Más acciones de publicación**) guarda como borrador, guarda en la biblioteca o **publica ahora**. Publicar ahora no espera a la fecha: pide confirmación y envía la publicación enseguida. Durante unos segundos el estado se ve «Programada» hasta que se empieza a publicar. También lo ofrecen el calendario y la lista. Si sales con cambios sin guardar, la app te avisa.
- **Biblioteca.** Guarda una publicación como plantilla desde el menú del compositor o desde la lista del calendario; en **Biblioteca**, **Usar** crea un borrador a partir de ella.

Aviso: si publicas en la misma cuenta dos destinos con exactamente el mismo texto, la app puede confundirlos al comprobar duplicados; usa textos distintos.

### Qué pasa si algo falla

- Un error temporal se reintenta solo (hasta 4 intentos).
- Si una cuenta necesita reconectarse, la publicación se conserva y sale al reconectar (con un plazo máximo; por defecto 72 h).
- Si el servidor estuvo caído, lo vencido se publica al volver solo si lleva menos de 24 h de retraso (valor por defecto, por confirmar).
- Si una red agota su cupo diario, por defecto se aplaza hasta que lo libere.
- Cuando una publicación termina con error recibes un aviso en la app y por correo, con el motivo.

### YouTube

Cada subida gasta unas 1.600 de las 10.000 unidades diarias: unas 6 subidas al día.

### TikTok

Por ahora el vídeo se envía como **borrador a tu bandeja de TikTok**: recibirás la notificación y terminas de publicarlo en la app. La publicación directa se activará cuando TikTok apruebe la auditoría. En la app aparece como "Publicada" con la nota "revisa tus borradores".

## Calendario

Vista de semana o mes (y lista) con el estado por red. Puedes filtrar por red y reprogramar una publicación pendiente moviéndola.

## Estadísticas

El **Dashboard** muestra por marca seguidores, alcance, visualizaciones e interacciones, con total y desglose por red. Elige el periodo y compara con otro. Las métricas que una red no ofrece se muestran como "no disponible", nunca como 0. Los datos se actualizan una vez al día (sobre las 12:00 UTC); los de las publicaciones recientes, cada 6 horas.

## Informes

En **Informes** elige marca, rango y formato (PDF, CSV o ambos) y, si quieres, un periodo de comparación. Se generan en segundo plano; cuando estén listos pulsa Descargar (el enlace caduca en 5 minutos, pero se genera uno nuevo cada vez). Se conservan 30 días y puedes tener hasta 5 en curso.

## API keys y asistentes de IA (MCP)

En **API keys** crea una clave para que un asistente (como Claude) programe y consulte por ti. La clave empieza por `msk_` y se muestra **una sola vez**: cópiala. Elige qué puede hacer (leer marcas, leer o escribir publicaciones, estadísticas, informes) y cuándo caduca. Puedes revocarla en cualquier momento. Detalles técnicos: [servidor MCP](../modulos/mcp.md).

**Sin clave, con el navegador.** Algunos asistentes (Claude Code, Claude Desktop, Codex, OpenCode) pueden conectarse sin que copies ninguna clave: al conectar, se abre una pantalla de esta app donde inicias sesión, ves qué permisos pide el asistente (por defecto solo lectura) y pulsas **Autorizar**. Tú eliges cuánto dura el acceso en la propia pantalla (30 días, 90 por defecto, 6 meses o 1 año) y aparece en **API keys** con el origen «OAuth · nombre del asistente»: desde ahí lo puedes revocar igual que una clave. Las claves manuales siguen apareciendo como «Manual» y funcionando como siempre. Pasos por asistente en [servidor MCP](../modulos/mcp.md#conectar-un-cliente).

## Entrar con Google o Facebook

Si el administrador ha activado el acceso con Google o Facebook, la pantalla de entrada muestra los botones **Continuar con Google** y **Continuar con Facebook**. Solo funcionan para cuentas que ya existen: no se crean cuentas nuevas.

- **Google:** la primera vez se vincula solo si el correo de tu cuenta de Google coincide con el de tu cuenta aquí y ambos están verificados.
- **Facebook:** vincúlalo antes en **Perfil → Cuentas vinculadas** (te pedirá la contraseña) y después ya puedes entrar con el botón.
- Si tienes el doble factor activado, después del proveedor se te pide el código como siempre.
- En **Perfil → Cuentas vinculadas** ves las cuentas vinculadas y puedes **desvincularlas**. Una cuenta desvinculada no vuelve a vincularse sola: si quieres volver a usarla, vincúlala tú desde ahí.
- Si ves «No hay ninguna cuenta vinculada a esta identidad», esa cuenta de Google o Facebook no está vinculada a ningún usuario de aquí: entra con tu contraseña y vincúlala desde el perfil.

## Seguridad de la cuenta: doble factor y passkeys

En **Perfil → Seguridad** puedes proteger tu cuenta con dos extras opcionales. Sigues pudiendo entrar con tu correo y contraseña.

### Doble factor (código de tu móvil)

Además de la contraseña, al entrar te pedimos un código de 6 dígitos que cambia cada 30 segundos y te da una app de autenticación (Google Authenticator, Authy, 1Password, Aegis…).

**Activarlo**

1. En Perfil → Seguridad pulsa **Activar doble factor** y confirma tu contraseña.
2. Escanea el código QR con la app. Si no puedes escanearlo, copia la **clave de configuración** y escríbela a mano en la app.
3. Escribe el código de 6 dígitos que muestra la app y pulsa **Confirmar y activar**.

**Guarda tus códigos de recuperación.** Al activarlo te mostramos 8 códigos **una sola vez**. Cópialos o descárgalos y guárdalos en un lugar seguro (un gestor de contraseñas o impresos), **no** en el mismo móvil. Cada código sirve **una vez**. Más adelante puedes generar otros 8 con **Generar códigos nuevos** (los anteriores dejan de valer).

**Entrar con doble factor.** Escribe tu correo y contraseña y, en la pantalla «Verificación en dos pasos», el código de la app. Tras 5 intentos fallidos esperas un minuto antes de volver a probar. Si no tienes la app a mano, pulsa **Usar un código de recuperación**.

**Desactivarlo.** En Perfil → Seguridad pulsa **Desactivar doble factor** y escribe tu contraseña y un código (de la app o de recuperación).

### Passkeys (entrar con huella, cara o PIN)

Una passkey te deja entrar con la huella, la cara o el PIN de tu dispositivo, **sin escribir contraseña ni código del doble factor**. Cada passkey pertenece a un dispositivo o a tu gestor de contraseñas (iCloud, Google, 1Password…).

- **Añadir:** Perfil → Seguridad → **Añadir passkey**, confirma tu contraseña, ponle un nombre (por ejemplo «Portátil de casa») y sigue las indicaciones de tu dispositivo. Te enviaremos un correo avisándote de que se añadió.
- **Entrar:** en la pantalla de acceso pulsa **Entrar con passkey** (o elígela en el campo del correo si tu navegador la ofrece).
- **Renombrar o eliminar:** desde la misma tarjeta. Una passkey eliminada deja de servir al instante.
- Las passkeys solo funcionan en una conexión segura (https). Si ves «Las passkeys requieren HTTPS», entra con tu contraseña.
- Si la dirección (dominio) de la aplicación cambia, las passkeys dejan de valer y tendrás que añadirlas otra vez; con tu contraseña sigues entrando.

### ¿Y si pierdo el móvil?

1. **Tienes una passkey** en otro dispositivo: entra con ella (no pide el código) y, en Perfil → Seguridad, genera códigos de recuperación nuevos (no pide código). Para pasar el doble factor al móvil nuevo, desactívalo con uno de esos códigos y vuelve a activarlo escaneando el QR con el móvil nuevo.
2. **Tienes tus códigos de recuperación:** en la pantalla de verificación pulsa **Usar un código de recuperación** y escribe uno (sirve una sola vez). Después, genera códigos nuevos y activa el doble factor en el móvil nuevo.
3. **No tienes ni passkey ni códigos:** contacta con la persona que administra la aplicación. Tras comprobar que eres tú, desactivará tu doble factor; entrarás solo con tu contraseña y podrás activarlo otra vez.

Las API keys del asistente de IA (MCP) no se ven afectadas por el doble factor.

## Avisos

El icono de avisos lista fallos de publicación y cuentas que necesitan reconexión. Reconectar conserva tus publicaciones pendientes. Desconectar una cuenta hace fallar las que tuviera pendientes.
