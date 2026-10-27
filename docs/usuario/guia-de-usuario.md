# Guía de usuario

Social Scheduler programa tus publicaciones en Instagram, Facebook, YouTube, TikTok y Bluesky, y te muestra cómo van tus cuentas. Está pensado para uso personal.

## Primeros pasos

1. Regístrate y verifica tu correo.
2. Crea una **marca** (por ejemplo, tu proyecto o negocio). Cada marca agrupa sus cuentas, publicaciones y estadísticas.
3. Entra en las **Conexiones** de la marca y pulsa **Conectar** en cada red. Acepta los permisos en la pantalla de la red.

Si tienes varias páginas o canales, la app te pide elegir cuál conectar.

Bluesky no usa una pantalla de permisos: se conecta con tu handle y una contraseña de aplicación (ver [Conectar Bluesky](#conectar-bluesky-con-una-app-password)).

Avisos al conectar:

- **Google/YouTube**: puede aparecer "Google no ha verificado esta aplicación". Es esperado en uso personal: pulsa Continuar.
- **Instagram**: necesita una cuenta profesional enlazada a una Página de Facebook.

## Conectar Bluesky con una app password

Bluesky no tiene el botón «Autorizar» de las otras redes: se conecta con una **app password** (contraseña de aplicación). Guía completa: [Bluesky: crear una app password](../guias/bluesky-app-password.md). Es una contraseña aparte, solo para esta app, que puedes revocar cuando quieras sin tocar tu contraseña de siempre.

**Crear la app password**

1. Entra en Bluesky (web `bsky.app` o la app) con tu cuenta.
2. Abre **Configuración → Privacidad y seguridad → Contraseñas de aplicación** (en inglés, *Settings → Privacy and security → App passwords*; también en `https://bsky.app/settings/app-passwords`).
3. Pulsa **Añadir contraseña de aplicación**, ponle un nombre (por ejemplo «Social Scheduler») y créala.
4. Copia la contraseña (formato `xxxx-xxxx-xxxx-xxxx`). **Bluesky solo te la enseña una vez.** No actives el permiso de mensajes directos: no hace falta.

**Conectarla**

1. En las **Conexiones** de tu marca, ve a la tarjeta de Bluesky y pulsa **+ Conectar**.
2. Escribe tu **handle** completo (por ejemplo `tunombre.bsky.social`, sin la `@`) y pega la app password.
3. Pulsa **Conectar Bluesky**. Si todo es correcto, la cuenta aparece como «Conectada».

La app password se guarda **cifrada** y no se vuelve a mostrar. Si te equivocas, el mensaje te lo dice sin guardar nada.

**Si deja de funcionar**: si la revocas en Bluesky o cambias de handle, la cuenta pasa a «Requiere reconexión» y las publicaciones programadas se conservan. Crea una app password nueva y vuelve a pulsar **+ Conectar** (no hay botón «Reconectar»). Para dejar de usar la app, desconecta la cuenta aquí y **revoca la app password** en Bluesky.

### Qué se puede publicar en Bluesky

- **Publicación:** texto de hasta **300 caracteres** y hasta 4 imágenes. Un emoji compuesto (como una familia) cuenta como 1. Los enlaces, los `#hashtags` y las `@menciones` salen clicables; una mención a un handle que no existe queda como texto normal.
- **Imágenes:** hasta 2 MB cada una. Si una pesa más, se publica una copia reducida (tu original no cambia); si no se puede reducir, la app te lo dice al guardar.
- **Vídeo:** un MP4 de hasta **50 MB** en esta instalación (se rechaza al guardar si pesa más) y hasta 3 minutos. Bluesky pide tener el **email de la cuenta verificado** y limita los vídeos por día; si no se puede, la publicación falla con un mensaje claro. Tras subirlo, Bluesky lo procesa unos minutos y la publicación aparece como «Procesando».
- **Estadísticas:** seguidores y publicaciones de la cuenta, y me gusta, comentarios y compartidos de cada publicación. Bluesky no da visualizaciones ni alcance: se muestran como «no disponible».

## Subir contenido

En la biblioteca de **Media** sube imágenes y vídeos. El archivo va directo al almacenamiento; la app comprueba formato, tamaño, duración y proporción. Instagram solo acepta imágenes JPEG. Si algo no cumple, te dice qué cambiar.

## Crear y programar una publicación

1. Pulsa **Crear publicación** y elige marca y cuentas de destino.
2. Elige el tipo por red (imagen, carrusel, reel, historia, vídeo, short…) en el desplegable que aparece junto a cada red activa: cada opción muestra su icono, su nombre y un subtítulo. Se maneja también con el teclado (flechas, Inicio, Fin, Intro o Espacio para elegir, Escape para cerrar). Debajo de las pastillas, el compositor te avisa de las limitaciones del tipo elegido.
3. Escribe el texto, adjunta la media y elige fecha y hora (zona horaria de la marca).
4. Guarda como **borrador** o **programa**.

Estados que verás: Borrador, Programada, Publicando, Procesando, Publicada y Con error. Mientras sea Borrador o Programada puedes editar, reprogramar o borrar. Una vez en curso o publicada, ya no (el texto, la media y la fecha); las **notas internas** sí se pueden seguir editando, también en una publicación en curso o ya publicada, y no afectan a lo publicado.

### El compositor

El compositor ocupa toda la pantalla. Arriba eliges las redes con pastillas; cada una muestra su contador de caracteres y avisa de sus límites.

- **Texto común y «Editar por red».** Escribe un texto que vale para todas las redes. Con **Editar por red** puedes darle a una red un texto propio (pestañas por red); las demás siguen el texto común. **Volver al texto común** descarta el propio de esa red (pide confirmación).
- **Opciones por red.** YouTube (categoría, etiquetas, público infantil, avisos a suscriptores, contenido sintético…) y TikTok (visibilidad, comentarios, contenido de marca…) tienen su panel. La app **recuerda por marca** los interruptores de cada red y los pone como valores iniciales la próxima vez; si la cuenta o el tipo de contenido no admite una opción, no se ofrece.
- **Notas.** Un campo de notas internas para tu equipo o para ti. Nunca se publican. Se pueden editar también cuando la publicación ya está en curso o publicada (no tocan lo publicado). En el calendario, las publicaciones con notas llevan la insignia **Nota**, para que veas de un vistazo cuáles tienen indicaciones del equipo.
- **UTM.** El botón de enlaces abre un generador: escribes la dirección y los parámetros `utm_` (origen, medio y campaña son obligatorios) y **Añadir** inserta el enlace ya montado en el texto.
- **Emojis y asistente de texto** desde la barra del editor.
- **Hashtags sugeridos.** El botón **Hashtags** de la barra del editor te propone etiquetas a partir de **tu propio historial**: las que ya has usado **al menos 2 veces** en publicaciones de esa marca, ordenadas de mejor a peor según las interacciones que lograron (junto a cada una ves cuántas veces la usaste y cuánto mejoró o empeoró sobre tu media). **No usa inteligencia artificial** ni envía nada a terceros: todo sale de tus datos. Pulsa una etiqueta y se inserta en la posición del cursor, con un espacio a cada lado si hace falta. Si el texto supera el máximo de hashtags de una red (por ejemplo 30 en Instagram) se avisa. Si aún no hay datos suficientes, el menú lo explica: publica con hashtags y espera a tener métricas.
- **Textos guardados.** Guarda firmas, llamadas a la acción o grupos de hashtags y reutilízalos. Créalos en **Textos guardados** (menú de tu usuario, arriba a la derecha): elige el tipo (**Fragmento** o **Grupo de hashtags**), un título, el texto y, si quieres, una marca; sin marca valen para **Todas las marcas**. Puedes buscarlos, filtrarlos por marca, editarlos y borrarlos. En el compositor, el botón **Textos guardados** de la barra muestra los de la marca actual y los de Todas las marcas; al elegir uno se inserta **en la posición del cursor** del campo en el que estabas escribiendo (el texto común o el texto propio de una red) y el contador de hashtags se actualiza. Solo tú ves tus textos.
- **Primer comentario.** En Instagram, Facebook y YouTube puedes escribir un comentario que la cuenta deja justo después de publicar (por ejemplo, el enlace o los hashtags). Aparece en las opciones de cada red, con un contador que respeta el máximo de la red (Instagram 2.200, Facebook 8.000, YouTube 10.000); en TikTok y en las Historias no existe. Tras publicar, el estado se ve en **Estado por red**: **Pendiente**, **Comentando**, **Comentado**, **Fallido** (con el motivo) o **No admitido** (comentarios cerrados o vídeo privado o «para niños»). Si falla, recibes un aviso en la app; pulsa **Editar** para corregir el texto y **Reintentar**. Si el comentario falló porque la app no pudo comprobar si ya estaba en la red (la búsqueda quedó inconclusa) y podría existir ya, **Reintentar** no lo vuelve a crear a ciegas y el aviso ofrece dos salidas: **Ya está publicado** (lo has visto tú en la red; lo cierra como publicado sin tocar la red, así que un «publicado» marcado a mano no queda enlazado al comentario real) y **Publicar igualmente** (lo crea de todos modos, con el riesgo de que quede **duplicado** si ya estaba; solo se envía una vez por clic). La publicación sigue como **Publicada** aunque el comentario falle. El primer comentario no se guarda en la biblioteca. **YouTube:** hay que **reconectar el canal** una vez para dar el permiso de comentarios; hasta entonces el campo sale desactivado con el aviso «Reconecta el canal para comentar». Desde un asistente de IA (MCP) puedes indicarlo al crear o editar, pero no corregir un comentario fallido: eso se hace en la web.
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

Cada subida gasta unas 1.600 de las 10.000 unidades diarias: unas 6 subidas al día. Cada primer comentario gasta 50 más (y 1 la comprobación previa). Para comentar hay que reconectar el canal una vez (permiso de comentarios).

### TikTok

Por ahora el vídeo se envía como **borrador a tu bandeja de TikTok**: recibirás la notificación y terminas de publicarlo en la app. La publicación directa se activará cuando TikTok apruebe la auditoría. En la app aparece como "Publicada" con la nota "revisa tus borradores".

### Bluesky

Texto de hasta 300 caracteres con hasta 4 imágenes, o un vídeo MP4 de hasta 50 MB. Los contadores del compositor cuentan como Bluesky (un emoji compuesto es 1 carácter). Más detalle y cómo conectar la cuenta: [Conectar Bluesky](#conectar-bluesky-con-una-app-password).

## Calendario

Vista de semana o mes (y lista) con el estado por red. Puedes filtrar por red y reprogramar una publicación pendiente moviéndola.

### Vistas guardadas

Si usas siempre los mismos filtros, guárdalos: filtra el calendario por marca, red y estado (y elige Semana, Mes o Lista), abre **Vistas**, escribe un nombre (por ejemplo «Pendientes IG») y pulsa **Guardar vista**. Más tarde, desde cualquier otro filtro, abre **Vistas** y elige esa vista: se vuelven a aplicar la marca, la red, el estado y la vista. Desde el mismo menú puedes **Renombrar**, **Borrar** o **Poner por defecto** una vista: la de por defecto se aplica sola al abrir el calendario sin filtros. Un asistente de IA (MCP) las lee con `list_calendar_views` y las usa con `get_calendar` y `view`.

### Cuadrícula de Instagram

En **Planificación > Cuadrícula** ves cómo quedará el perfil de una cuenta de Instagram de la marca (si hay varias, las eliges arriba). Son tres columnas, como en Instagram: primero lo **programado** (con su fecha; la más tardía primero y un clic abre el compositor) y debajo lo **ya publicado** (un clic abre la publicación en Instagram). Es de solo lectura.

- Las **Stories no aparecen** (no forman parte del perfil) y un Reel programado solo aparece si está marcado **compartir en el feed**.
- Los posts **fijados** en el perfil no se reflejan: aquí se ordena por fecha.
- Las miniaturas se recortan en vertical, así que pueden no coincidir exactamente con el recorte de Instagram. Un carrusel muestra su primera pieza y un vídeo sin miniatura muestra su primer fotograma.
- Lo publicado se lee de Instagram y se **actualiza cada 10 minutos**; una publicación recién hecha puede tardar en salir. Si Instagram no responde o la cuenta necesita reconectarse, verás un aviso y lo programado se sigue mostrando.

## Inbox de comentarios

En **Inbox** ves los comentarios de tus publicaciones de Instagram y Facebook. Además de responder u ocultar, puedes dejar **notas internas** en cada comentario: pulsa **Añadir nota interna**, escribe (hasta 2.000 caracteres) y **Guardar nota**. Las notas solo las ve tu equipo, **no se envían ni se publican en ninguna red**, salen en orden cronológico bajo el comentario y se pueden borrar con **Borrar**. Los comentarios con notas llevan la insignia **con nota** y el filtro **Solo con nota** deja únicamente esos. Las notas no se pierden cuando el inbox se actualiza. Un asistente de IA (MCP) las lee en `list_comments` y añade otra con `add_comment_note`.

## Campañas

En **Planificación > Campañas** agrupas las publicaciones de una marca para medirlas juntas. Crea una campaña con nombre, color y fechas (el fin no puede ser anterior al inicio), edítala, **archívala** para que deje de ofrecerse, o bórrala: sus publicaciones se conservan, sin campaña.

En el compositor, el selector **Campaña** de la cabecera asigna la publicación entera a una campaña de la marca (también puedes crear una al momento con **Nueva campaña**). Duplicar una publicación o guardarla en la biblioteca conserva la campaña. Desde un asistente de IA (MCP) se usa `campaign_id` y `list_campaigns`.

Al abrir una campaña ves publicaciones, visualizaciones, **alcance acumulado**, interacciones, me gusta, comentarios, compartidos, guardados y engagement, con el desglose por red, la evolución diaria y cada publicación. Es la suma del último dato leído de cada destino; lo que no se ha podido medir sale como "No disponible", nunca como 0, y el alcance no cuenta usuarios únicos. Las métricas de una publicación se actualizan durante **30 días** tras publicarla; después quedan congeladas.

## Estadísticas

El **Dashboard** muestra por marca seguidores, alcance, visualizaciones e interacciones, con total y desglose por red. Elige el periodo y compara con otro. Las métricas que una red no ofrece se muestran como "no disponible", nunca como 0. Los datos se actualizan una vez al día (sobre las 12:00 UTC); los de las publicaciones recientes, cada 6 horas.

### Todas las marcas

Si tienes más de una marca, el botón **Todas las marcas** del Dashboard abre la suma de todas ellas: seguidores (acumulado), visualizaciones, alcance e interacciones con su desglose por red, una gráfica y una tabla con una fila por marca. Puedes cambiar el periodo y comparar igual que en una marca.

- Cada cifra suma las marcas que la tienen. Si ninguna la ofrece sale **No disponible**, nunca 0.
- «Acumulado» suma el último dato de cada cuenta: no cuenta personas únicas.
- Los días son días UTC. Con las mismas fechas, las cifras coinciden con las del Dashboard de cada marca.
- Pulsa el nombre de una marca de la tabla para ver su Dashboard.

### Exportar a CSV

- **Publicaciones.** En el **Calendario**, vista **Lista**, filtra por marca, red o estado (o abre una vista guardada) y pulsa **Exportar CSV**. Se descarga un fichero con las publicaciones que ves: fecha, marca, red, cuenta, tipo, estado, texto, enlace y las métricas del último dato (lo que no se puede medir queda vacío, nunca 0). Se exportan como máximo **5.000 filas**; si hay más, la Lista te avisa y puedes filtrar para sacar el resto.
- **Estadísticas.** En el **Dashboard** (de una marca) y en **Todas las marcas**, **Exportar CSV** descarga las cifras del periodo elegido, con la comparación si la activaste.
- **Excel.** El fichero es UTF-8 con BOM y separa con `;`, así que Excel en español lo abre con los acentos bien. Si un texto empieza por `=`, `+`, `-` o `@`, lleva un `'` delante para que Excel no lo ejecute como fórmula: es normal y es por seguridad.

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
