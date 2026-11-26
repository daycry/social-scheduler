# Exportar mis datos

Descarga una copia completa de tus datos en un ZIP. Entra en **Perfil → Exportar mis datos** (también en el menú de tu cuenta).

## Flujo

1. **Pide la exportación.** Se prepara en segundo plano (puede tardar según el tamaño de tu biblioteca). Solo puedes tener **una exportación en curso** a la vez; al pedir otra, sustituye a la anterior y borra su ZIP.
2. **Te avisamos.** Al terminar recibes un aviso en la aplicación («Tu exportación de datos está lista») que enlaza a la página (el éxito solo avisa en la aplicación). Si falla (o supera el tope de tamaño) recibes un aviso con el motivo, también por correo si tienes activados los avisos por correo, y no queda ningún ZIP a medias.
3. **Descarga.** Pulsa **Descargar ZIP**. Cada pulsación genera un enlace firmado nuevo que caduca a los 10 minutos y solo funciona con **tu** sesión: un enlace reenviado o filtrado no sirve a otra persona.

## Qué incluye

Un fichero JSON legible por tipo de dato en `datos/`, los ficheros originales en carpetas y, en la raíz, `LEEME.txt` y `manifest.json` (fecha, versión del formato y la lista de ficheros con su tamaño y su **SHA-256**; compruébalos con `sha256sum` o `Get-FileHash`).

| Carpeta o fichero | Contenido |
|---|---|
| `datos/` | Tu perfil (nombre, correo, aviso por correo), marcas, cuentas conectadas (solo nombre, red y estado), publicaciones con sus destinos y textos, series, plantillas, campañas, autolistas, objetivos, textos guardados, vistas del calendario, ajustes del compositor, competidores que sigues, tus respuestas, notas y etiquetas del buzón, informes (definiciones, plantillas y programaciones), biblioteca de media (metadatos), diseños del Estudio y sus exportaciones, kits de marca, tipografías, tablas de datos, sitios, smart links y sus bloques, webhooks (nombre y eventos) las visitas y clics de smart links y sitios, y las métricas diarias de cuentas y publicaciones. |
| `medios/` | Los ficheros originales de tu biblioteca, como `<id>-<nombre>`. |
| `informes/` | Los informes PDF o CSV que ya estaban generados (los informes se conservan 30 días). |
| `estudio/` | El documento JSON de cada diseño, sus exportaciones renderizadas, tus tipografías y la versión vigente de tus tablas de datos. |

Si un fichero ya no está en el almacenamiento, se omite y queda anotado en `skipped` del manifiesto.

## Qué no incluye

- **Credenciales y secretos:** tokens de acceso y de renovación de las cuentas sociales, contraseñas de aplicación, claves de API y tokens, hash de tu contraseña, secreto y códigos de recuperación del doble factor, passkeys, claves de las notificaciones push, secretos y URL de los webhooks, la contraseña de acceso de los sitios y la clave de la aplicación. Tendrás que volver a conectar las cuentas al restaurar.
- **Datos de otras personas:** las marcas de las que solo eres colaborador, los diseños que te han compartido, los colaboradores de tus marcas, los suscriptores de tus smart links, los mensajes recibidos en tus sitios y los enlaces de acceso a tus diseños.
- **Comentarios de terceros del buzón:** son datos de quienes los escribieron en tus cuentas. Sí van tus respuestas, tus notas y tus etiquetas.
- **Comentarios de aprobación** de tus publicaciones y diseños: los escribe un colaborador. Sí va el estado de aprobación.
- **Datos públicos de los competidores** (publicaciones y seguidores): solo va la lista que has configurado.
- **Registros técnicos:** intentos de publicación, entregas de webhooks, actividad y auditoría, sesiones, trabajos de IA, imágenes y audio del Estudio, y el historial de revisiones de los diseños (va la versión actual). Las miniaturas se vuelven a generar.

## Límites y caducidad

| Dato | Valor (configurable en `config/exports.php`) |
|---|---|
| Tamaño máximo del ZIP | 20 GB (`EXPORTS_MAX_BYTES`); admite ficheros de más de 4 GB y miles de ficheros (ZIP64). Si se supera, la exportación termina en error y no se guarda nada: el aviso te propone pedirla **sin incluir los archivos de media**. |
| Conservación del ZIP | 72 horas desde que está listo (`EXPORTS_RETENTION_HOURS`); después se borra solo. |
| Validez del enlace de descarga | 10 minutos (`EXPORTS_LINK_MINUTES`); pulsa Descargar para obtener otro mientras el ZIP exista. |
| Ritmo | 3 peticiones de exportación por minuto. |

## Con o sin media

La casilla **Incluir archivos de media** (activada por defecto) controla si el ZIP lleva los ficheros originales de la biblioteca, los informes ya generados, las exportaciones del Estudio y tus tipografías. Sin ella solo salen los datos en JSON, los documentos de los diseños y el manifiesto: es mucho más pequeño y rápido, y sirve si la exportación completa supera el tope. Si ya tienes una copia lista, pedir otra te lo confirma: la actual sigue disponible hasta que la nueva esté lista y entonces se sustituye.

Para operar el servidor (cola, programación de la limpieza y disco) consulta [Despliegue](../modulos/despliegue.md#exportación-completa-de-datos-t-84).
