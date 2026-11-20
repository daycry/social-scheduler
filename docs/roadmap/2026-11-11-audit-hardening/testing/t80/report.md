# T-80 cerrada: webhooks salientes

Integrada tras tres revisiones reales de **Claude Code Opus 5.5**, con aceptación, corrección, seguridad, rendimiento y calidad del código. El informe final y las respuestas están en `reviews/`.

- PHP completo: **4,441 pruebas pasan, 9 omitidas y 29,815 aserciones**.
- SQLite y MySQL 8.4.3: **44 pruebas y 293 aserciones** por motor, incluyendo recifrado de secretos.
- Cinco rondas de dos procesos PHP reales: una petición simulada y un intento confirmado por ronda. Rollback y reaplicación de las dos migraciones comprobados en MySQL propio.
- Node **789/789**; TypeScript, Pint, ESLint, Prettier y build pasan.
- Navegador ampliado **45/45** y focal final **6/6** en tres tamaños; ambos `qa-gate` VERDE. Los casos se solapan y no se suman. Capturas de escritorio y 360px inspeccionadas y archivadas.
- **1.832 huellas** verificadas y **37 archivos** integrados con bytes exactos; parche reversible.

El outbox guarda eventos y entregas en la misma transacción que la publicación o el comentario. La firma usa timestamp y los bytes persistidos, con UUID estable para deduplicar en el receptor. El secreto permanece cifrado y privado, con rotación cubierta por el comando existente. UrlGuard comprueba todas las IP, fija la conexión y bloquea redes privadas, proxies y redirecciones.

La revisión separó DNS del bloqueo de entrega y detectó una regresión al fijar un plazo de tres horas en el job. Se eliminó esa caducidad: una entrega puede reanudarse después de una parada larga. Los cinco intentos de negocio se separan del límite de tres excepciones o ejecuciones interrumpidas del worker, mediante la API nativa del framework. Las regresiones con Worker/QueueManager reales en proceso comprueban cuatro horas de parada, una excepción seguida de cinco respuestas503 y el corte por fallos repetidos. Las marcas de caída se simulan: no se afirma haber probado una señal pcntl o un daemon.

También se simplificó el runner en preparación, elegibilidad, transporte y clasificación; se centralizaron HTTPS y el límite de receptores, se añadieron casts y se redujeron consultas sin suscriptores. Los botones identifican el receptor para lectores de pantalla, se toleran mensajes de error iguales y la limpieza E2E retira su bandera antes de borrar jobs.

La producción documentada usa MySQL/MariaDB. SQLite mantiene un bloqueo global durante HTTP y se limita a desarrollo/pruebas para esta funcionalidad. El contador de fallos del worker requiere una caché compartida persistente y tiene la ventana de un día del framework. Cola compartida, retención de eventos y posible espera del barrido están documentadas. No se promete recepción exactamente una vez ni latencia ilimitada.

Después de R3/global solo se sustituyó la fixture original del Worker por el helper con caché y se aclararon las políticas DNS/cola propuestas por el revisor;44/293 en SQLite y MySQL verifican esa fixture final. Todos los bytes productivos permanecen idénticos. La suite amplia de navegador precede solo metadatos del job, pruebas PHP y documentación; el focal final cubre la versión definitiva. Los JS/TS no cambiaron después de los789tests Node. No se usaron receptores/proveedores externos, configuración privada, base de producción ni usuario28. Sin commit, push o merge.
