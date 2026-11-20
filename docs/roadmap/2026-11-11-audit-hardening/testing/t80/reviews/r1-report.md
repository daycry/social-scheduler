# Revisión adversarial T-80 (intento 1/3)

**Veredicto: no se puede cerrar todavía.** No encuentro ningún Critical confirmado. Hay dos hallazgos Important confirmados por análisis estático: el I-2, cuya severidad depende del motor de base de datos en producción, y el I-3. Un tercero (I-1) depende de un hecho que falta en el material. Además, los gates de MySQL y navegador sobre los padres finales siguen pendientes, y el propio contrato impide atribuirles el 39/39 anterior.

---

## A · Conformidad con el contrato

**Se cumple, según el material aportado:**
- Firma HMAC sobre `t.cuerpo` con los bytes persistidos (`WebhookTransport.php:21-28`).
- ID estable por evento y endpoint y secreto inmutables (`WebhookSubscriptions.php:78-79`, regla `prohibited`).
- Eventos definidos explícitamente (`Webhook::EVENTS`).
- Secreto cifrado y en `$hidden`, incluido en `app:reencrypt`.
- Límites de 20 webhooks, 30 cambios/minuto y lote de 500.
- Reintentos 60/300/900/3600 s.
- UrlGuard validado al crear y en cada intento, con IP fijada, sin proxy, sin redirecciones y cierre del stream.
- Comentario y outbox en la misma transacción (`InboxService.php:247`).
- Barrido periódico del outbox.
- La documentación no promete recepción exactamente una vez.

**Queda pendiente:**
- Repetir los gates de MySQL y navegador sobre los padres finales.
- La afirmación documental de que el outbox de publicación «forma parte de la transacción de estado» (`docs/api/webhooks.md:76`) no se puede verificar con lo aportado (ver I-1).

## B · Corrección

Están bien resueltos:
- Máquina de estados.
- Idempotencia por `(webhook_id, webhook_event_id)`.
- `markFailed` limitado a filas `pending`.
- Cancelación al pausar o desuscribir.
- `afterCommit` + lock único + liberación del lock en `PendingDispatch:312-317`.

Los problemas están en los bordes temporales y en el acoplamiento entre los intentos del job y los del runner (I-3, M-1).

## C · Seguridad

- SSRF correcto: todas las IP validadas, IP fijada, URL canónica, HTTPS revalidado en el envío.
- El secreto no se re-flashea en `_old_input` (probado).
- La propiedad se comprueba en la ruta, en el servicio, en el observer y en el runner.
- Sin fugas del cuerpo remoto ni de textos de excepción.

No hay hallazgos de seguridad Critical/Important, salvo la parte de disponibilidad de I-3.

## D · Rendimiento

El punto débil es mantener transacción y lock durante la resolución DNS y el HTTP (I-2, I-3). Hay además consultas superfluas sin suscriptores (M-2) y crecimiento de tablas sin poda (M-3, política).

## E · Calidad del código

Legible en general y con responsabilidades razonables (observer → evento → dispatcher → job → runner → transporte). Puntos de mejora:
- `run()` es un closure de unos 70 líneas que mezcla elegibilidad, transporte y clasificación de resultados.
- Estados como literales de cadena repetidos.
- Observer con tipo unión y guardas `instanceof`.
- Mutación del request para evitar el flash del secreto.

Detalle en M-5.

---

## Hallazgos accionables

### I-1 · Important (condicional, falta un hecho) — atomicidad del outbox en publicación
**Ubicación:** `app/Observers/WebhookDomainObserver.php:16-29`, `app/Services/Webhooks/WebhookDispatcher.php:32-57`, `docs/api/webhooks.md:76`.

**Escenario:** `PublishPostTarget::handle` llama a `$outcomes->published(...)` fuera del `DB::transaction` de `claim()` (`PublishPostTarget.php:137`). Si `PublishOutcomes::published`/`PostTransitions::transition` no envuelven el `save()` en una transacción:
1. El destino queda confirmado como `publicada`.
2. Después el dispatcher abre su propia transacción. Una caída o un error de base de datos en ese tramo pierde el evento de forma definitiva: el barrido solo recupera entregas que ya existen.
3. Si el dispatcher lanza la excepción, esta acaba en el `catch (Throwable)` de `PublishPostTarget.php:146`, que solo registra en el log. El destino ya está publicado y no se reconcilia, así que el evento no se emite nunca.

**Evidencia:** el test «real publication transition queues one» pasa en ambos casos, porque sin transacción `DB::afterCommit` se ejecuta en el acto.

**Hecho que falta:** el fuente de `PostTransitions::transition` y de `PublishOutcomes::published/failed`, y de `PollPublishStatus`/`PublishRecovery` si cambian `status` mediante Eloquent. También hay que confirmar que ninguna ruta pasa a `Published`/`Failed` con `update()` masivo, que no dispara observers.

**Corrección si se confirma:** envolver las transiciones terminales en `DB::transaction` y añadir un test que provoque una excepción en el listener y compruebe que la fila de `PostTarget` no queda `publicada`.

### I-2 · Important si SQLite es un motor soportado en producción (Minor si no) — lock global de escritura durante el HTTP
**Ubicación:** `app/Services/Webhooks/WebhookDeliveryRunner.php:27-55`, `docs/api/webhooks.md:76`.

**Escenario:** en SQLite, `$delivery->save()` (línea 53) toma el lock RESERVED de toda la base de datos y lo mantiene durante la resolución DNS y la petición (hasta 10 s, más el DNS; ver I-3). Mientras tanto se bloquean todas las escrituras de la aplicación: publicación, inbox, sesiones si usan base de datos, `jobs` si la cola es `database`.

Cualquier usuario puede apuntar sus 20 webhooks a un receptor que responda en 9,9 s y provocar eventos. Con dos o más workers, las entregas compiten entre sí, agotan el `busy_timeout` o el `timeout` del job (20 s) y consumen intentos (ver I-3).

**Evidencia:** las 5 rondas de 2 procesos usaron un HTTP falso instantáneo, así que no ejercitan receptores lentos. El motivo aprobado (evitar dos HTTP al reintentar el lock) es válido, pero su coste de disponibilidad no está analizado ni documentado.

**Hecho que falta:** ¿SQLite es un despliegue soportado en producción? `.env.production.example` no se aportó completo.

**Corrección:** si SQLite es soportado, reclamar el intento con una transacción corta (`attempts++` y una marca `claimed_at`/token mediante `UPDATE … WHERE status='pending' AND claimed_at IS NULL`), confirmarla y hacer el HTTP fuera de la transacción, cerrando el resultado en una segunda transacción. Si no lo es, documentar que solo MySQL está soportado para webhooks.

### I-3 · Important — DNS sin cota dentro de la transacción y acoplamiento `tries` = `MAX_ATTEMPTS`
**Ubicación:** `app/Services/Webhooks/WebhookTransport.php:17` (que llama a `UrlGuard::validate` → `dnsResolver`, `UrlGuard.php:290-303`), `app/Services/Webhooks/WebhookDeliveryRunner.php:53-55`, `app/Jobs/DeliverWebhook.php:15,17,43-46`.

**Escenario:**
1. Un usuario crea un webhook hacia su propio dominio; el DNS resuelve a una IP pública en el momento del alta.
2. Después vuelve lento su servidor DNS autoritativo.
3. `dns_get_record` para A y AAAA no tiene cota de tiempo desde PHP: el resolver del sistema aplica típicamente 5 s × 2 intentos por consulta, unos 20 s en total.
4. Se supera `$timeout = 20` del job y el worker muere por pcntl. La transacción se revierte junto con `attempts++`, mientras el lock de fila (y el global en SQLite) se mantuvo todo ese tiempo.
5. Laravel cuenta un intento de job. Tras 5 muertes, `failed()` marca `exhausted/worker_failed` con `attempts = 0`. Por tanto los reintentos no quedan registrados, en contra del contrato.
6. Cada entrega mata hasta 5 procesos de worker de la cola compartida `poll`.

**Variante del mismo acoplamiento:** cualquier excepción no clasificada, como un `QueryException` por contención (SQLITE_BUSY o un deadlock en MySQL), consume un `try` sin consumir un intento del runner. Una entrega puede acabar `worker_failed` con menos de 5 HTTP.

**Corrección:**
- Resolver y validar la URL antes de abrir la transacción, pasando el `ValidatedUrl` al transporte. Se siguen validando todas las IP y se fija la IP en cada intento.
- Desacoplar `$tries` (por ejemplo, un valor alto con `retryUntil()` de unas 3 h) del límite de negocio `MAX_ATTEMPTS`, que ya hace cumplir el runner.
- Añadir un test que lance una excepción no clasificada y compruebe que el estado final no es `worker_failed` antes de 5 intentos HTTP.

**Hecho que falta:** el `retry_after` de la conexión de cola. Si es menor de unos 25 s, existe además riesgo de entregas duplicadas.

### M-1 · Minor — excepciones HTTP no clasificadas
**Ubicación:** `WebhookDeliveryRunner.php:74-84`.

**Escenario:** un certificado TLS inválido (cURL 60) produce en Guzzle un `RequestException` sin respuesta. Según la versión de Laravel, puede no convertirse en `ConnectionException`. En ese caso se revierte el intento y se consume el presupuesto del job (ver I-3).

**Hecho que falta:** la versión exacta de `illuminate/http`.

**Corrección:** capturar también `Illuminate\Http\Client\RequestException` y `GuzzleHttp\Exception\TransferException` como `connection_error`.

### M-2 · Minor (D) — consultas con cero suscriptores
**Ubicación:** `WebhookDomainObserver.php:21-22, 34-38`; `WebhookDispatcher.php:24-28`.

**Escenario:** cada comentario nuevo de cualquier usuario ejecuta 5 consultas (cuenta, destino, post, marca, hooks), aunque el usuario no tenga webhooks configurados. Cada transición terminal de un destino ejecuta 4. En sincronizaciones grandes del inbox esto multiplica la carga sin aportar nada.

**Corrección:** comprobar primero `Webhook::ownedBy($uid)->where('brand_id', …)->where('enabled', true)->whereJsonContains('events', $name)->exists()`. También vale una caché por petición o job.

### M-3 · Minor / política opcional — crecimiento sin poda
**Ubicación:** `database/migrations/2026_11_18_092100_create_webhook_events_and_deliveries.php:11-35`.

**Escenario:** `webhook_events` y `webhook_deliveries` crecen indefinidamente. Al borrar un webhook se eliminan sus entregas, pero los eventos quedan huérfanos con su `body` mientras exista la marca.

El contrato no fija retención, así que esto **no es un defecto contra el contrato**. Se sugiere un `model:prune` de eventos sin entregas y de entregas terminales con más de N días.

### M-4 · Minor / política operativa — cola compartida y barrido
**Ubicación:** `config/webhooks.php:4`, `.env.example` (`WEBHOOK_QUEUE=poll`), `DispatchPendingWebhooks.php:29-30`.

**Escenarios:**
- Receptores lentos (hasta 10 s, o más con DNS lento; ver I-3) ocupan los workers de `poll`, compartidos con otros jobs, y retrasan esos trabajos.
- Con un atasco de más de 500 entregas `pending` vencidas que ya están en cola con lock, el barrido vuelve a seleccionar cada minuto las mismas 500 primeras. Las entregas huérfanas situadas detrás esperan hasta que se vacíe el atasco o expire `uniqueFor` (7200 s).

**Sugerencia:** usar una cola dedicada por defecto o documentar el riesgo. Es opcional.

### M-5 · Minor (E) — calidad y mantenibilidad

**`WebhookDeliveryRunner.php:27-94`.** Un único closure combina la carga, la elegibilidad, el envío y la clasificación del resultado.
- Extraer `classify(int $status): array{state, error, retry}` y un método `eligible()`.
- `last_error` se escribe en tres sitios distintos (líneas 51, 68 y 83), lo que dificulta seguir el flujo.

**Estados como literales.** `'pending'`, `'delivered'`, etc. están dispersos en el runner, el job de barrido y el modelo.
- Un enum respaldado (`WebhookDeliveryStatus`) eliminaría la duplicación con `WebhookDelivery::STATES` y `DeliveryState` en TypeScript.

**`WebhookDeliveryRunner.php:37`.** `$hook->brand_id !== $event->brand_id` compara de forma estricta columnas sin cast.
- Hoy funciona porque ambas vienen del mismo driver.
- Es frágil si cambia el modo de emulación de PDO. Conviene añadir `'brand_id' => 'integer'` a los casts o comparar con `(int)`.

**`WebhookController.php:76-86`.** Mutar el request para que no se haga flash del secreto es un efecto lateral poco evidente.
- La vía idiomática es `$dontFlash`/`->dontFlash(['secret'])` en el manejador de excepciones.
- Opcional: el comportamiento actual es correcto y está probado.

**`WebhookDomainObserver.php:16,32`.** El tipo unión `PostTarget|InboxComment` con guardas `instanceof` en cada método oscurece la intención.
- Dos observers pequeños serían más claros. Opcional.

**Duplicación.**
- La comprobación HTTPS aparece en `WebhookSubscriptions.php:28-30` y `WebhookTransport.php:18-20`.
- `max(1, (int) config('webhooks.max_per_user', 20))` aparece en el controlador y en el servicio.
- Extraer ambos a un único sitio.

### M-6 · Minor (UI / accesibilidad)

**Botones con nombres idénticos.**
- **Ubicación:** `resources/js/Pages/Webhooks/Index.vue:325-328`.
- **Escenario:** con varias tarjetas, cada una tiene botones con nombre accesible «Editar» y «Eliminar». La lista de controles de un lector de pantalla queda ambigua.
- **Corrección:** `:aria-label="\`Editar ${hook.name}\`"` o `aria-describedby` hacia el `h3`.

**Claves duplicadas en errores.**
- **Ubicación:** `Index.vue:285`.
- **Escenario:** `:key="error"` genera claves duplicadas si dos campos devuelven el mismo mensaje, por ejemplo `events.0` y `events.1`. Vue emite un aviso y puede reutilizar mal los nodos.
- **Corrección:** usar la clave del campo o el índice.

### M-7 · Minor (tests) — huecos de cobertura

Faltan casos para:
- Reintento con 408, 425 y 429.
- Rama `DecryptException` → `blocked/invalid_secret`.
- Interacción real de `$tries` con `release()` y excepciones intermedias (I-3).
- Rollback de la transición de publicación cuando falla el outbox (I-1).
- Excepciones HTTP no clasificadas (M-1).
- Receptor lento en SQLite con dos workers (I-2).

La concurrencia se acredita con scripts externos que no forman parte del parche. Hay que confirmar que quedan archivados en `testing/t80/**` como dice el ledger.

### M-8 · Minor (robustez E2E) — fuga de la bandera de aislamiento
**Ubicación:** `tests/e2e/webhooks.spec.ts:33-35`, `tests/e2e/isolated/bootstrap.php` (hunk `webhooks-on`).

**Escenario:** el `unlink` de `webhooks-on` está en el mismo script de artisan que el borrado de `jobs`. Si ese borrado falla (por ejemplo, la tabla no existe porque la cola no es `database`), la bandera queda activa. Los specs posteriores reciben entonces un `UrlGuard` que solo resuelve `hooks.example.test`. Ese guard también sustituye al de Canva si ambas banderas coinciden, y los specs fallan de forma confusa.

**Corrección:** eliminar la bandera en un `artisan` separado, o primero y con `try/finally`.

---

## Hechos que faltan, de forma exacta

1. Fuente de `PostTransitions::transition`, `PublishOutcomes::{published,failed}`, `PollPublishStatus` y `PublishRecovery`. Sirve para saber si las transiciones terminales son transaccionales y se hacen mediante Eloquent (I-1).
2. Si SQLite es un motor soportado en producción (I-2).
3. `retry_after` de la conexión de cola y versión de `illuminate/http` (I-3, M-1).
4. Si existen otras rutas de creación de `InboxComment` de terceros fuera de `InboxService::upsert` (por ejemplo, ingestión por webhook entrante o MCP) que no tengan transacción.

## Política opcional, no defectos contra el contrato

- Retención y poda de eventos y entregas (M-3).
- Cola dedicada por defecto (M-4).
- Reactivar entregas canceladas.
- Rotación del secreto con dos firmas válidas simultáneas.
- Uso de `dontFlash` frente a la mutación del request.

## Resumen por eje

| Eje | Estado |
|---|---|
| A Conformidad | Parcial: I-1 sin verificar; gates de MySQL y navegador pendientes sobre los padres finales |
| B Corrección | I-3 Important; M-1 |
| C Seguridad | Sin Critical/Important propios; la parte de disponibilidad de I-3 |
| D Rendimiento | I-2 (condicional); M-2, M-4 |
| E Calidad | M-5, M-6, M-7, M-8: Minor |

**Para cerrar:** resolver I-3, aportar los hechos de I-1 e I-2 (y corregirlos si se confirman) y repetir los gates de MySQL y navegador sobre los padres finales.