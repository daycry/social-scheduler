# Webhooks salientes

En el menú de cuenta, **Webhooks** permite configurar receptores de una marca propia. Se puede crear, renombrar, pausar o borrar una suscripción, elegir sus eventos y consultar el estado de su última entrega. Un colaborador aprobador no adquiere permiso para configurar los receptores de la persona propietaria.

El receptor debe ser una URL **HTTPS pública**, sin credenciales en la URL y en los puertos admitidos por `UrlGuard` (80 o 443). Se rechazan direcciones privadas, reservadas, loopback, metadatos, alias numéricos y DNS mixtos con alguna IP no pública. La dirección se valida al crear y de nuevo al entregar; la conexión se fija a la IP validada, sin proxy ni redirecciones. Un receptor que devuelve una redirección queda bloqueado: no se visita su `Location`.

## Crear un receptor

1. Elige la marca y un nombre reconocible.
2. Introduce la URL pública del receptor.
3. Usa **Generar secreto** o uno propio de 32–128 letras ASCII, números, guiones o guiones bajos. El generador utiliza 32 bytes aleatorios del navegador y los presenta como 64 caracteres hexadecimales.
4. Copia el secreto al receptor antes de guardar. Se almacena cifrado con la clave de la aplicación y no vuelve a aparecer en el listado, las respuestas del servidor ni los datos temporales de un formulario rechazado.
5. Selecciona los eventos y crea el webhook. URL y secreto son inmutables; para cambiarlos, crea otra suscripción y elimina la anterior.

Hay un máximo predeterminado de **20 suscripciones por usuario**, entre todas sus marcas, y **30 cambios por minuto por usuario**. Las altas simultáneas se serializan con el lock del usuario para respetar ese máximo. Los límites de una persona no consumen el presupuesto de otra.

## Eventos y cuerpo

| Evento | Cuándo se genera | Campos de `data` |
|---|---|---|
| `post.published` | Un destino propio cambia a publicada | `post_id`, `target_id`, `network`, `status` |
| `post.failed` | Un destino propio pasa a fallo definitivo, incluso si su cuenta fue borrada | `post_id`, `target_id`, `network`, `status` |
| `comment.created` | Se guarda por primera vez un comentario de otra persona en el inbox propio | `comment_id`, `target_id`, `post_id`, `network` |

Una actualización o sincronización repetida de un comentario no crea otro evento; las respuestas propias se excluyen. Los eventos no incluyen credenciales, URLs de subida, texto del comentario, autor ni mensajes privados de error del proveedor. La asociación de usuario, marca, publicación y cuenta se comprueba antes de generar o entregar los datos.

Cada cuerpo JSON contiene:

```json
{
  "id": "4f3d63a0-2f40-4c11-a835-77aab71ba015",
  "event": "post.published",
  "occurred_at": "2026-11-18T12:00:00+00:00",
  "brand_id": 12,
  "data": {"post_id": 31, "target_id": 45, "network": "facebook", "status": "publicada"}
}
```

Los identificadores del ejemplo son ficticios. El cuerpo se persiste una vez y cada intento envía **esos mismos bytes**, sin volver a codificarlos. `occurred_at` corresponde al evento; la marca de tiempo de la firma corresponde al intento.

## Verificar la firma

Las peticiones son `POST`, `Content-Type: application/json`, con estas cabeceras:

| Cabecera | Valor |
|---|---|
| `X-SocialScheduler-Event` | Nombre del evento |
| `X-SocialScheduler-Event-Id` | UUID del evento, estable en los reintentos |
| `X-SocialScheduler-Signature` | `t=<timestamp Unix>,v1=<HMAC-SHA256 hexadecimal>` |

La entrada del HMAC es `timestamp + "." + cuerpo_crudo`. La clave es el secreto compartido. Verifica el cuerpo antes de interpretarlo; no lo decodifiques y vuelvas a generar para calcular la firma. Ejemplo de receptor PHP:

```php
function verifyWebhook(string $rawBody, string $header, string $secret, int $now): array
{
    if (!preg_match('/\At=(\d+),v1=([a-f0-9]{64})\z/D', $header, $parts)
        || abs($now - (int) $parts[1]) > 300) {
        throw new RuntimeException('Firma o marca de tiempo inválida.');
    }
    $expected = hash_hmac('sha256', $parts[1].'.'.$rawBody, $secret);
    if (!hash_equals($expected, $parts[2])) {
        throw new RuntimeException('Firma inválida.');
    }
    return json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
}
```

Mantén sincronizado el reloj del receptor. Después de verificar, valida el esquema y **deduplica por `id`** dentro de tu transacción de procesamiento. Devuelve un `2xx` después de guardar el resultado; para un evento ya procesado, responde también `2xx` sin repetir su efecto. Una petición puede repetirse si se pierde la respuesta o el proceso cae después de que el receptor la acepte. No se garantiza recepción si el receptor permanece indisponible.

## Reintentos y estados

Hasta cinco intentos, con esperas de **1, 5, 15 y 60 minutos** entre fallos transitorios. Un `2xx` confirma la entrega. Se reintentan errores de conexión, `5xx`, `408`, `425` y `429`. Los demás errores HTTP terminan en **Fallida**; cualquier `3xx` o URL que deje de ser segura termina en **Bloqueada**. Al agotar los intentos queda **Reintentos agotados**. No se guarda el cuerpo de respuesta remoto ni las cabeceras de firma en el historial.

El guard trata del mismo modo una dirección privada y un nombre que no resuelve. Por eso **un fallo DNS al entregar, incluso transitorio, termina en Bloqueada sin enviar HTTP**. Esa entrega no se reintenta automáticamente ni vuelve a activarse al corregir el DNS o reactivar el receptor; los eventos nuevos vuelven a validar su dirección. Es la política de bloqueo conservadora del guard existente, sin distinguir públicamente sus motivos internos.

La pantalla muestra estado, intentos, último código HTTP, motivo seguro, fecha de actualización y próximo intento. **Actualizar estado** recarga estas observaciones. Pausar o quitar un evento cancela las entregas pendientes cuando se procesan; reactivar recibe eventos nuevos y no reinicia las canceladas. Borrar un webhook elimina sus entregas; los eventos durables de la marca se conservan mientras exista la marca o su propietario.

El evento y sus entregas se guardan en un outbox. En publicación forman parte de la transacción de estado; al sincronizar un comentario nuevo se confirma o revierte junto con la inserción del comentario. Una caída de la cola después del commit no borra el evento: el barrido programado recupera hasta 500 entregas vencidas por minuto. El lock único del job evita encolados repetidos y el lock de la fila serializa los intentos en MySQL. La resolución y validación DNS ocurre antes de abrir esa transacción; se revalida la propiedad y la suscripción después, antes de HTTP, y se utiliza la IP pública ya fijada para ese intento. El cuerpo mantiene el mismo UUID si hay que repetir una petición ambigua.

El despliegue de producción descrito en `.env.production.example` utiliza MySQL/MariaDB. SQLite se usa en pruebas y desarrollo: escribir antes de HTTP evita envíos duplicados al competir por el lock, pero mantiene su lock global de escritura durante la petición. Un receptor lento puede bloquear otras escrituras hasta el timeout HTTP; SQLite no es una opción adecuada para operar estos webhooks en producción.

## Operación y límites de las pruebas

Debe funcionar el scheduler y un worker de la cola configurada:

| Variable opcional | Por defecto | Efecto |
|---|---|---|
| `WEBHOOK_QUEUE` | `poll` | Cola de entrega y recuperación; una cola distinta requiere su worker |
| `WEBHOOK_MAX_PER_USER` | `20` | Máximo de receptores por usuario, al menos uno |
| `WEBHOOK_SETTINGS_PER_MINUTE` | `30` | Cambios de configuración por usuario/minuto, al menos uno |

La petición HTTP tiene tres segundos para conectar y diez de tiempo total; la respuesta se abre en streaming y se cierra sin consumir su cuerpo. La resolución DNS previa depende del resolver del sistema y no queda limitada por ese timeout HTTP. El timeout del job es veinte segundos; el `retry_after` predeterminado de las conexiones de producción database/redis es 180 segundos. Una entrega no caduca por el tiempo que pasa en cola: puede reanudarse después de una caída larga. El job separa el límite de cinco intentos de entrega del límite de tres excepciones no controladas o ejecuciones interrumpidas detectadas por el worker (`maxExceptions` y `CountCrashesAsExceptions`). Estas últimas pueden terminar en `worker_failed` aunque no hubiera HTTP, por ejemplo si el DNS bloqueó el proceso. El contador de fallos y las marcas de ejecución usan la caché del worker, con una ventana de un día según el framework; requieren una caché compartida persistente, como `CACHE_STORE=database` del despliegue existente. Las respuestas HTTP transitorias controladas consumen intentos de entrega, no excepciones del ejecutor. Una muerte del worker o una excepción inesperada puede dejar una petición ambigua: sigue siendo necesario deduplicar por UUID. Al fallar definitivamente el job se registra el motivo seguro `worker_failed`, incluso si no hubo ningún HTTP por un fallo previo de DNS.

La cola predeterminada `poll` se comparte con otros trabajos: receptores lentos pueden retrasarlos. Una cola `WEBHOOK_QUEUE` dedicada, con su propio worker, permite aislar esa carga. Si hay más de 500 entregas vencidas que ya están encoladas con lock, el barrido puede volver a seleccionar ese primer lote; las posteriores esperan a que progrese el lote o expire su lock. No se afirma latencia garantizada ni capacidad ilimitada.

Si el cron sigue funcionando durante una caída de workers, pueden acumularse barridos al expirar su lock de dos minutos y jobs de entrega duplicados al expirar su lock de dos horas. Al recuperarse los workers aumenta el trabajo de cola y de consultas; la comprobación de la fila y del próximo intento evita repetir una entrega ya confirmada. Estos locks no sustituyen la deduplicación remota por UUID cuando una petición queda ambigua.

Los secretos del webhook están incluidos en `app:reencrypt`: utiliza el procedimiento existente de `APP_PREVIOUS_KEYS`, simulación y recifrado al rotar `APP_KEY`. Perder las claves sin recifrar puede impedir descifrarlos; en ese caso la entrega queda bloqueada y se necesita un receptor nuevo.

Pest usa `Http::fake` y DNS inyectado para probar firma, reintentos, SSRF y privacidad. Playwright activa un receptor virtual únicamente en el bootstrap aislado, con `APP_ENV=e2e`, URL local y bandera dentro de su propio almacenamiento. Ese receptor valida la firma contra un secreto ficticio conocido; no se permite usar una URL privada real ni se desactiva el guard productivo. Las pruebas de interfaz invocan handlers directamente; las regresiones de infraestructura también usan QueueManager/database y Worker::runNextJob reales en el proceso PHP. La prueba de caídas reproduce marcas de caché sin matar un proceso del sistema operativo. No se acreditan un daemon, señales pcntl reales ni un receptor HTTPS externo real.
