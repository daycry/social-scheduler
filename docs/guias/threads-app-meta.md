# Threads: crear la app de Meta (ID, secreto y tester)

Social Scheduler conecta Threads por OAuth con una **app de Meta propia**. Esta guía te lleva de cero a una cuenta «Conectada»: añadir el caso de uso Threads, anotar el ID y el secreto **de Threads**, registrar la URI de redirección e invitarte como *tester*. Los valores de Threads salen de la documentación oficial de Meta consultada el 2026-10-27 (resumen en [decisiones por red](../referencia/decisiones-por-red.md#threads)).

> Esta guía es el paso previo del smoke real de Threads (M-02 del plan de pruebas): hazla antes. Mientras no haya `THREADS_APP_ID`, la red **no aparece** en ninguna pantalla.

## 1. Antes de empezar

| Qué | Para qué |
|---|---|
| Una cuenta de Threads (la tuya, con su perfil) | Es la que publicará. Puede ser pública o privada |
| Un usuario de [developers.facebook.com](https://developers.facebook.com) | Dueño de la app de Meta |
| La URL pública de la app (`APP_URL`) | Meta redirige ahí al terminar; con `https` y sin barra final, por ejemplo `https://midominio.com` |
| Almacenamiento público de la media (`MEDIA_DISK`) | Threads **descarga** las imágenes y vídeos de una URL firmada temporal; tiene que ser accesible desde internet |

## 2. Añadir el caso de uso Threads

1. En developers.facebook.com abre **Mis apps** y elige la app que ya usas para Meta o crea una nueva. Con una app existente: **Casos de uso → Añadir caso de uso → Acceder a la API de Threads**. Con una app nueva, elige ese caso de uso al crearla.
2. Meta crea **dos pares de ID y secreto** en la app (uno de Meta y otro de Threads). **Social Scheduler usa el de Threads**, no el de `META_APP_ID`. Ahí está la trampa más común.
3. ¿Puedo usar la app de Meta que ya tengo para Facebook e Instagram? Se puede **añadir** el caso de uso a la misma app (el diseño lo mantiene aparte a propósito: Threads usa otro ID, otro secreto y otro host de autorización), pero **no mezcles los permisos**: pedir un permiso no activado rompe todo el login de Meta («Invalid Scopes»). Si dudas, crea una app aparte solo para Threads; el código no cambia.

## 3. Permisos

En el caso de uso de Threads activa estos tres, que son los que pide Social Scheduler:

| Permiso | Para qué |
|---|---|
| `threads_basic` | Obligatorio en todas las llamadas (perfil y lectura de publicaciones) |
| `threads_content_publish` | Publicar texto, imagen, vídeo y carrusel |
| `threads_manage_insights` | Leer las métricas de la cuenta y de cada publicación |

No actives `threads_read_replies` ni `threads_manage_replies`: la app no los usa.

## 4. URI de redirección

En la configuración del caso de uso de Threads (apartado de **URI de redirección de OAuth válidos** / *Redirect Callback URLs*) añade exactamente:

```
<APP_URL>/connections/threads/callback
```

Por ejemplo `https://midominio.com/connections/threads/callback`. Debe coincidir **carácter por carácter** con la que envía la app (Meta puede añadir una barra final al guardarla: comprueba la lista). Una por entorno que uses (el dominio de pruebas y el de producción).

## 5. Anotar el ID y el secreto de Threads

1. En **Configuración de la app → Básica** busca **Threads App ID** y **Threads App secret** (muestra el secreto con tu contraseña).
2. Ponlos en el `.env`:

```dotenv
THREADS_ENABLED=true
THREADS_APP_ID=<Threads App ID>
THREADS_APP_SECRET=<Threads App secret>
```

3. Limpia la caché de configuración si la usas (`php artisan config:clear`). En **Conexiones** debe aparecer la tarjeta de **Threads**.

`THREADS_ENABLED=false` retira la red de todas partes (Conexiones, compositor, filtros, MCP y reprogramación) sin borrar nada; sus cuentas siguen conectadas, pero no se pueden usar.

## 6. Invitarte como *tester*

Mientras la app no esté aprobada, **solo los *testers* de la app pueden conceder los permisos** y publicar:

1. En la app: **Roles de la app → Roles → Añadir personas → Tester de Threads** y escribe tu usuario de Threads.
2. En Threads (web o móvil): **Ajustes → Cuenta → Permisos del sitio web** (*Website permissions*) y **acepta la invitación**.
3. Hasta que la aceptes, el inicio de sesión de Threads dará error de permisos.

## 7. Conectar en Social Scheduler

1. Abre **Conexiones** de la marca y la tarjeta de **Threads**.
2. Pulsa **+ Conectar**: te lleva a `threads.com`, inicia sesión y acepta los tres permisos.
3. Al volver, la cuenta debe aparecer como **Conectada**.

Qué se guarda: el token de **larga duración** (60 días) cifrado, con su caducidad; el ID de usuario de Threads y tu nombre de usuario. No hay contraseña ni `refresh_token`: la app **renueva sola** el token antes de que caduque (el margen es de 7 días, `SOCIAL_REFRESH_MARGIN_THREADS_HOURS`) y, si Threads lo invalida, la cuenta pasa a **Requiere reconexión** y tus publicaciones programadas se conservan.

Si algo falla, el mensaje lo explica y no se guarda nada:

| Mensaje | Qué hacer |
|---|---|
| «La autorización fue cancelada o rechazada» | Vuelve a pulsar **+ Conectar** y acepta los permisos |
| «Threads rechazó la conexión» | Comprueba la URI de redirección (sección 4), el ID y el secreto **de Threads** y que aceptaste la invitación de *tester* |
| «No se pudo contactar con Threads» | Fallo temporal: espera unos minutos y reintenta |

## 8. Límites que conviene saber

- **Texto:** 500 caracteres; **cada emoji cuenta sus bytes UTF-8** (un cohete cuenta 4). El compositor lo cuenta igual que el servidor.
- **Carrusel:** de 2 a 20 imágenes o vídeos. **Imagen:** JPEG o PNG de 8 MB. **Vídeo:** MP4 o MOV, H.264 o HEVC, de 23 a 60 fps, hasta 5 minutos y 1 GB.
- **Cuota:** 250 publicaciones cada 24 horas (un carrusel cuenta 1). Con el límite agotado, la publicación se **aplaza** o falla con motivo «cuota», según `PUBLISH_QUOTA_POLICY`.
- **Enlaces:** máximo 5 distintos por publicación de texto.
- Threads procesa el vídeo tras recibirlo: el destino aparece como «Procesando» y pasa a «Publicada» con su enlace.

## 9. ¿Y si Meta exigiera App Review?

La documentación de Meta dice que, **sin App Review, solo se publica y se leen datos de los *testers*** de la app. Para una cuenta tuya (o las de pocas personas que invites como *tester*) no hace falta revisión. Si el smoke (T-64) demostrara que Meta la exige también para tu caso, o si más adelante quieres conectar cuentas de terceros que no son *testers*:

1. Pide **acceso avanzado** a `threads_content_publish` (y a `threads_basic` y `threads_manage_insights`) en **Revisión de la app**, con un vídeo del flujo y las URL de `/privacy` y `/terms`.
2. Cuando Meta apruebe la revisión, **publica la app** (modo *Live*).
3. Hasta entonces no cambies nada en el código: **deja `THREADS_APP_ID` vacío (o `THREADS_ENABLED=false`) y la red desaparece** sin afectar a Facebook, Instagram ni al resto. Las cuentas ya conectadas se conservan.

Los permisos concedidos por usuarios con perfil público duran **90 días** y se extienden al renovar el token; en un perfil **privado** no se pueden extender y habrá que conceder el permiso otra vez.

## 10. Dejar de usarla

- **En la app:** desconecta la cuenta en **Conexiones** (se borran los tokens).
- **En Threads:** **Ajustes → Cuenta → Permisos del sitio web** y retira la app. Corta el acceso al instante.
- **En la app de Meta:** quita el rol de *tester* si ya no lo necesitas.
