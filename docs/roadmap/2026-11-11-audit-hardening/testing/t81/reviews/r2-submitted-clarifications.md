# Aclaraciones del paquete R2 (sin cambios de código)

El paquete R2 es inmutable y se conserva con su SHA. La tabla enviada incluye dos expresiones demasiado amplias que se precisan aquí:

- Las rutas profile mostradas tienen auth y verified. No se afirma un middleware MFA adicional sobre esas rutas: no aparece en el grupo ni en bootstrap/app.php. R1 pedía confirmar auth/verified, y eso sí está en el código adjunto. No se ha añadido ni relajado un middleware.
- Las notificaciones que guardan en BD usan la cadena database en via(); PasskeyAdded y QueueJobFailedAlert son de mail solamente y el listener las omite. No se afirma que todos los avisos de la app tengan canal database.

El lock incorpora seis paquetes nuevos en total: minishlink/web-push y cinco transitivos. Laravel y la app requieren PHP^8.3; WebPush>=8.2, JWT>=8.2 y phpseclib>=8.1 son compatibles con ese mínimo. Estas precisiones no alteran el código, los tests ni los criterios.
