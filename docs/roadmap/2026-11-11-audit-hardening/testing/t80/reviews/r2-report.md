# Revisión adversarial T-80, intento 2/3: reevaluación de R1 y del parche corregido

## Veredicto

**Todavía no se puede cerrar.** Hay dos motivos.

1. **Un Important nuevo (I-4).** La propia corrección de I-3 lo introduce: el `retryUntil` fijo de 3 h convierte una caída o un atasco largo de workers en la pérdida terminal de entregas sin ningún intento HTTP.
2. **Gates pendientes.** Faltan los finales de PHP completo, MySQL 41/279 y navegador sobre el diff corregido. No les atribuyo los resultados de R1 ni el 33/240 previo.

I-1, I-2 e I-3 (en su núcleo) quedan cerrados. Las correcciones M-1, M-2, M-5 (parcial), M-6 y M-8 son correctas.

---

## Reevaluación de los hallazgos de R1

### I-1 · Atomicidad del outbox en publicación: cerrado

El hecho que faltaba se ha aportado.

- `PostTransitions.php:50-58` envuelve la transición en `DB::transaction`, relee con lock y llama a `apply → save`. El observer se dispara dentro de esa transacción.
- `PublishOutcomes::published/failed`, `PollPublishStatus` y `PublishRecovery` pasan todos por `PostService::transition`.
- Las llamadas directas a `apply` de `PostScheduling` están dentro de `DB::transaction`.
- No veo ningún `update()` masivo sobre `status`. `PublishPostTarget.php:120` solo actualiza `progress`, y `:182` solo `status_note`.

La prueba `WebhookDeliveryTest.php:96-116` comprueba que el destino sigue en `Publishing` y que no quedan eventos, entregas ni jobs. Es cobertura válida. No pido RED porque el comportamiento ya era correcto.

Consecuencia aceptable: un fallo del outbox deja el destino en `publicando`. Lo recupera `PublishRecovery::resumeStuck`, que consulta la red antes de republicar (`PublishPostTarget.php:124-126`).

### I-2 · SQLite en producción: cerrado como Minor documentado

- `.env.production.example:14-15` declara MySQL/MariaDB.
- `docs/api/webhooks.md:78` documenta el bloqueo global de escritura de SQLite y desaconseja ese motor para producción.

No es un defecto contra el contrato.

### I-3 · DNS dentro de la transacción y acoplamiento tries/intentos: núcleo cerrado, residuo Minor (M-9)

**DNS fuera de la transacción.**
- `WebhookDeliveryRunner.php:29,85-101` resuelve el DNS antes de abrir la transacción.
- La elegibilidad se reevalúa bajo lock en `:42`.
- La reactivación durante la prelectura no envía una URL sin validar (`:53-55`).
- La prueba `WebhookDeliveryTest.php:44-56` verifica a la vez el nivel de transacción y la pausa durante la resolución.

**`$tries` desacoplado de `MAX_ATTEMPTS`.**
- `DeliverWebhook.php:17` fija `$tries = 0`, y `:31-34` define `retryUntil`.
- El límite de negocio sigue en el runner (`:47, :72`).
- La prueba con `Worker` y `QueueManager` reales (`:58-82`) acredita 1 error inesperado + 5 respuestas 503, con resultado `exhausted/http_error` y 5 intentos.
- Mi lectura de `Worker.php:730-747, 790-801` es coherente con ese resultado.

**`retry_after`.** Es 180 s en `config/queue.php:52,81`, mayor que el `timeout` de 20 s. No hay riesgo de redelivery concurrente por timeout.

### M-1 · Cerrado

`Runner.php:117` captura también la `TransferException` de Guzzle. Capturar `Illuminate\Http\Client\RequestException` es código muerto, porque solo se lanza con `->throw()`, pero es inocuo.

### Resto de hallazgos menores

| Hallazgo | Estado |
|---|---|
| M-2 | Cerrado (`WebhookDomainObserver.php:24,38`) |
| M-3 | Política opcional aceptada |
| M-4 | Política opcional aceptada |
| M-5 | Aceptado como opcional (ver eje E) |
| M-6 | Cerrado |
| M-7 | Cerrado salvo el hueco que abre I-4 |
| M-8 | Cerrado (`webhooks.spec.ts:33`, `artisan` separado y primero) |

---

## Hallazgos nuevos

### I-4 · Important (regresión introducida en R2): una caída de workers de más de 3 h termina entregas sin ningún intento y el barrido no las recupera

**Ubicación:**
- `app/Jobs/DeliverWebhook.php:27,31-34,53-56`
- `app/Services/Webhooks/WebhookDeliveryRunner.php:135-140`
- `vendor/.../Queue/Worker.php:730-747`
- `docs/api/webhooks.md:76,90`

**Escenario:**
1. A las 10:00 se publica un post y se encola `DeliverWebhook`. El payload persiste `retryUntil` = 13:00.
2. El VPS o el supervisor de workers cae a las 10:01. El sistema ya contempla este caso: `PublishRecovery` existe precisamente «al volver de una caída del VPS».
3. Los workers vuelven a las 14:00. Al hacer pop, `markJobAsFailedIfAlreadyExceedsMaxAttempts` detecta `now > retryUntil` y llama a `failJob` sin ejecutar `handle()`.
4. `failed()` → `markFailed` deja la entrega en `exhausted/worker_failed` con `attempts = 0` y `last_http_status = null`.
5. `DispatchPendingWebhooks` solo selecciona `pending`, así que el evento se pierde de forma definitiva. La UI muestra «Reintentos agotados» cuando no hubo ningún reintento.

**Variantes del mismo defecto:**
- **Cola atascada.** Una cola `poll` compartida con más de unas 3 h de cola acumulada tiene el mismo efecto (relacionado con M-4).
- **Caída a mitad del calendario.** Tras el 4.º intento (unos T0+21 min), el release de 3600 s conserva el payload original. Una caída de los workers entre unos T0+80 min y T0+3 h termina la entrega con 4 intentos.

**Por qué es regresión:** con `$tries = 5` (R1) el job, al hacer pop tras la caída, simplemente se ejecutaba.

**Por qué choca con el contrato:**
- El criterio de aceptación pide que los reintentos «se agotan y se registran».
- `docs/api/webhooks.md:76` presenta el outbox como recuperable tras fallos de infraestructura.
- `:90` habla de «fallos de infraestructura durante tres horas», no de una vida máxima de la entrega.

**Corrección propuesta:**
- No convertir en terminal por plazo del job una entrega con `attempts < MAX_ATTEMPTS`.
- En `markFailed`, si la entrega es reciente (por ejemplo, `created_at` posterior a `now - 24 h`, configurable), solo poner `next_attempt_at = now()` y dejarla `pending`. El lock único se libera al fallar el job, así que el barrido la reencola con un plazo nuevo.
- Marcar `worker_failed` solo cuando se supere esa vida máxima de la entrega. Esto mantiene la cota frente a errores deterministas.

**Test que falta:** con el mismo `Worker` real, avanzar `Carbon` 3 h + 1 s antes del primer `runNextJob` y comprobar dos cosas:
- la entrega sigue `pending`;
- tras ejecutar el barrido, se envía 1 HTTP y queda `attempts = 1`.

**Alternativa de política:** si producto decide que la vida total de una entrega es de 3 h, debe:
- documentarlo como tal en `webhooks.md:76,90`;
- usar un motivo distinto de «Reintentos agotados», como `expired`.

En ese caso el hallazgo baja a Minor. Hoy la documentación no lo dice.

### M-9 · Minor (D/C, disponibilidad): más muertes de worker por DNS lento

**Ubicación:**
- `DeliverWebhook.php:17,21`
- `WebhookDeliveryRunner.php:29`
- `UrlGuard.php:290-303`

**Escenario:**
1. Un usuario configura su propio DNS autoritativo para que no responda.
2. Las consultas A y AAAA del stub resolver suman unos 20 s.
3. La alarma pcntl mata el worker. El job no falla, porque `retryUntil` está en el futuro y `failOnTimeout` es falso.
4. Laravel lo reentrega cada 180 s (`retry_after`) durante 3 h: unas 60 muertes por entrega.
5. Con 20 receptores y eventos frecuentes, el worker único de `QUEUE_NAMES` (que también publica) se reinicia de forma continua.

En R1 la cota era de 5 muertes por entrega, de modo que es una amplificación nueva. Ya no hay locks retenidos durante el DNS, así que el impacto se limita a la disponibilidad.

**Corrección:** contar las caídas como excepciones con `maxExceptions` (por ejemplo, 3) más el mecanismo `countCrashesAsExceptions`. `Worker.php:359,656,762-779` lee la clave de payload correspondiente.

**Hecho que falta:** el nombre exacto de la propiedad o atributo del job que activa `countCrashesAsExceptions` en la versión instalada.

**Alternativa:** documentar este coste junto a `webhooks.md:90`.

### N-1 · Nit (documentación)

`docs/api/webhooks.md:96` dice «Los handlers se invocan directamente». Hoy existe una prueba con `Worker::runNextJob` real en proceso. Conviene matizarlo: hay worker en proceso, pero no hay daemon del sistema operativo ni señal pcntl real.

---

## Ejes

### A · Conformidad con el contrato

Se cumplen:
- HMAC sobre los bytes persistidos.
- SSRF con validación al crear y en cada intento.
- IP fijada, sin redirecciones ni proxy.
- Secreto cifrado y en `app:reencrypt`.
- Límites de suscripciones y de cambios por minuto.
- Backoff 60/300/900/3600 s.
- Outbox atómico tanto en publicación (I-1 verificado) como en inbox.

**No se cumple:** los reintentos «se agotan y se registran» bajo una caída larga (I-4).

**Pendiente:** los gates finales de PHP completo, MySQL 41/279 y navegador sobre este diff, y la segunda revisión real. El rollback y la nueva aplicación de las migraciones en MySQL 8.4.3 se aceptan; el error del probe queda explicado y no se cambió código de migración.

### B · Corrección

- La máquina de estados, la idempotencia y la serialización bajo lock son correctas.
- La reentrada mediante `return 1` es acotada.
- Las excepciones no clasificadas ya no consumen intentos de negocio.
- El defecto abierto es I-4.

### C · Seguridad

- Sin Critical ni Important.
- La prelectura fuera de la transacción usa `ownedBy($delivery->user_id)`, y la propiedad y la marca se revalidan bajo lock antes del HTTP.
- La URL es inmutable, así que no hay TOCTOU explotable.
- Solo queda la parte de disponibilidad de M-9.

### D · Rendimiento

- Corregido: ya no hay lock de fila durante el DNS.
- Corregido: menos consultas sin suscriptores.
- Queda M-9.
- La doble comprobación de suscriptores (observer y dispatcher) es una consulta extra aceptable y está justificada.

### E · Calidad del código

Mejoras aplicadas:
- Mejora clara con `prepareUrl`, `eligible`, `send` y `classify`, y con el resultado tipado.
- Casts `brand_id` en ambos modelos.
- Validación HTTPS y límite centralizados.

Puntos menores que quedan:
- `run()` sigue siendo un closure de unas 50 líneas.
- `last_error` se asigna en `:63` y otra vez en `finish()`.

Opcional y aceptado como política:
- enum de estados;
- observers separados;
- `dontFlash`.

---

## Política opcional (no son defectos)

- Retención y poda de eventos y entregas (M-3).
- Cola dedicada por defecto (M-4).
- Vida máxima explícita de una entrega, si se adopta para I-4.
- Reactivar entregas canceladas.
- Rotación del secreto con dos firmas simultáneas.

---

## Para cerrar

1. Corregir I-4 con su test de Worker real, o adoptar y documentar la política alternativa con su propio motivo de estado.
2. Decidir M-9: añadir `maxExceptions` más el conteo de caídas, o documentar el coste.
3. Repetir los gates finales de PHP completo, MySQL 41/279 y navegador sobre el diff definitivo.
4. Hacer la segunda revisión real.