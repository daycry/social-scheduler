# Revisión adversarial T-80, intento 3/3: reevaluación de I-4, M-9 y N-1 sobre el parche final

## Veredicto

**Se puede cerrar.**

- **Correcciones.** I-4, M-9 y N-1 están corregidos.
- **Hallazgos abiertos.** No quedan Critical ni Important.
- **Gates.** Los gates finales que se declaran (PHP completo, módulo 44/293 en SQLite y MySQL 8.4.3, Node, tipos, estilo y build, navegador amplio y focal sobre el árbol definitivo, 5 rondas concurrentes) cubren el diff revisado. Los acepto sin pedir que se repitan.
- **Hallazgos nuevos.** Son Minor o Nit y ninguno bloquea el criterio «Revisión adversarial sin Critical/Important abiertos».

Lo aceptado en R2 (I-1, I-2, núcleo de I-3, M-1..M-8 y las opciones de calidad) no se reabre: no hay evidencia nueva.

---

## Reevaluación

### I-4 · Caducidad por edad de cola: **cerrado**

**Código.** `DeliverWebhook.php:17-20` deja `$tries = 0`, no tiene `retryUntil()` y no hay fecha persistida en el payload.

**Lectura del framework.**
- `Queue.php:182,188` serializa `maxTries=0` y `retryUntil=null`.
- En `Worker.php:730-742`, `$job->maxTries()` devuelve 0, que no es null, así que prevalece sobre `--tries` del worker.
- Con `retryUntil` nulo y `maxTries === 0`, la función retorna sin hacer nada.
- `Worker.php:798` exige `$maxTries > 0`, así que el camino de excepción tampoco falla el job por intentos.

**Consecuencia.** Ninguna entrega caduca por esperar en cola. `markFailed` (`WebhookDeliveryRunner.php:135-140`) solo se alcanza por `failJob`, que ahora solo dispara `maxExceptions`.

**Prueba.** `WebhookDeliveryTest.php:97-106` usa `QueueManager`/database y `Worker::runNextJob` reales. Avanza 4 h antes del primer pop y obtiene `delivered`, `attempts = 1` y 1 HTTP. Es exactamente el escenario de R2. El RED r58 se describe como fallo funcional y es coherente con el código anterior.

**Documentación.** `docs/api/webhooks.md:90` dice ahora «no caduca por el tiempo que pasa en cola», sin TTL inventado. Es consistente.

### M-9 · Amplificación de caídas por DNS lento: **cerrado con API nativa**

El hecho que faltaba en R2 está aportado: el atributo de clase `#[Illuminate\Queue\Attributes\CountCrashesAsExceptions]` (`CountCrashesAsExceptions.php:7-8`), que `Queue.php:185` lee para el payload.

**Comportamiento verificado sobre las fuentes adjuntas:**

- **Muerte dura** (SIGKILL u OOM). La marca `job-processing:{uuid}` sobrevive. El siguiente pop la detecta en `Worker.php:768` y la cuenta como excepción en `:772`.
- **Timeout pcntl.** `Worker.php:343-345` incrementa el contador y `:359-361` borra la marca antes del `kill`, así que no hay doble conteo con kill activo.
- **Releases de negocio.** `handle()` → `release()` borra la marca en `:656-658`. Con el driver database el UUID del payload se conserva al liberar, así que el contador abarca toda la vida de la entrega.
- **Cota.** Como máximo 3 ejecuciones interrumpidas o excepciones no controladas por entrega. Antes eran unas 60 en 3 h.

**Pruebas:**
- `:108-122`: 3 excepciones llevan a `worker_failed`, `attempts = 0` y la cola vacía.
- `:124-138`: el preflight con contador 2 más la marca pendiente falla sin HTTP. Reproduce marcas de caché y no mata un proceso; la documentación lo declara honestamente.
- `:59-83`: 1 excepción más 5 respuestas 503 conserva los 5 intentos de entrega.

**Dependencia de caché documentada.** `webhooks.md:90` exige una caché compartida persistente, y `.env.production.example:24` declara `CACHE_STORE=database`. `WorkCommand.php:151` inyecta esa caché en el worker.

### N-1 · **Cerrado**

`webhooks.md:96` distingue ahora entre handlers directos, Worker/QueueManager reales en proceso, y ausencia de daemon, de señal pcntl real y de receptor externo.

---

## Hallazgos nuevos (todos no bloqueantes)

### M-10 · Minor (B/A, preexistente, no es regresión de R3): un fallo DNS transitorio al entregar bloquea la entrega de forma terminal

**Ubicación:**
- `WebhookDeliveryRunner.php:96-100, 59-60`
- `UrlGuard.php:271-276, 290-303`

**Escenario:**
1. El VPS se reinicia. Es el mismo contexto que motivó I-4.
2. El supervisor arranca los workers antes de que el resolver esté listo, o el upstream DNS da SERVFAIL durante unos segundos.
3. `@dns_get_record` devuelve `false`, el resultado es `[]` y se lanza `UnsafeUrlException(NOT_ALLOWED)`.
4. `prepareUrl` devuelve `'unsafe_url'` y el runner marca la entrega `blocked` con `attempts = 1`.
5. Todas las entregas que se procesen en esa ventana quedan bloqueadas para siempre.
6. La UI muestra «La URL ya no apunta a una dirección pública permitida». Ni el barrido ni la reactivación las recuperan.

**Por qué es Minor y no Important:**
- El comportamiento es coherente con la decisión deliberada de `UrlGuard` (CWE-204) de no distinguir «no resuelve» de «privada».
- Encaja con la regla documentada en `webhooks.md:72`: «URL que deje de ser segura → Bloqueada».
- Ningún criterio de aceptación queda incumplido literalmente.

Aun así contradice la expectativa razonable de que un fallo de resolución es un error de conexión reintentable.

**Corrección barata y sin coste de seguridad:** al entregar, clasificar `unsafe_url` como `pending` con backoff, igual que `connection_error`.
- Se siguen consumiendo los 5 intentos y nunca se envía HTTP a una IP no validada.
- Una IP privada persistente acabaría en `exhausted/unsafe_url` en unos 81 min, en vez de en `blocked` inmediato.
- Habría que ajustar el test `:316-323` y la línea 72 de la documentación.
- **Alternativa de política:** documentar explícitamente que un fallo DNS al entregar se trata como URL insegura y termina en Bloqueada.

### M-11 · Minor (D, optimización opcional): acumulación de barridos y de jobs duplicados tras una caída larga de workers

**Ubicación:**
- `DispatchPendingWebhooks.php:20`
- `routes/console.php` (entrada `dispatch-pending-webhooks`)
- `DeliverWebhook.php:24`

**Escenario.** Los workers están caídos 4 h y el cron sigue activo.
1. **Barridos acumulados.** `withoutOverlapping` solo evita solapes del propio scheduler. El lock único del barrido expira a los 120 s, así que se encolan unos 30 `DispatchPendingWebhooks` por hora: unos 120 en total.
2. **Duplicados de entrega.** Pasadas 2 h expira el lock único de cada `DeliverWebhook` (`uniqueFor = 7200`). Cada barrido encola entonces un duplicado por entrega pendiente.
3. **Al volver los workers.** Se ejecutan unos 120 barridos, cada uno con hasta 500 lecturas e intentos de lock.

No hay HTTP duplicado: el lock de fila y `next_attempt_at` lo impiden, y los duplicados solo hacen `release`. El coste es de cola y de consultas, no de corrección. I-4 convierte este camino en el camino normal de recuperación, por eso lo señalo ahora.

**Mejora opcional:** `ShouldBeUniqueUntilProcessing` en el barrido, o un `uniqueFor` más largo. No bloquea.

### N-2 · Nit (E, test): la prueba original de Worker no usa el helper ni la caché

**Ubicación:** `WebhookDeliveryTest.php:59-64`

**Escenario.** El test construye su propio `Worker` sin `setCache`, así que en él `maxExceptions` y `CountCrashesAsExceptions` están desactivados (`Worker.php:762,813`). La respuesta R3 afirma que las fixtures repetidas se extrajeron a `nativeWebhookWorker`, pero esta no se migró.

El resultado no cambiaría (1 excepción < 3). Usar `nativeWebhookWorker()` haría que esa regresión ejercite la configuración real de producción.

### Hecho no verificable (sin hallazgo)

Desconozco el valor por defecto de `Worker::$killOnTimeout` en la versión instalada. Si fuera `false`, `Worker.php:343-356` lanza la excepción tras contarla y `handleJobException` (`:688`) la contaría una segunda vez. En ese caso bastarían 2 timeouts para llegar a `worker_failed`.

Es comportamiento del framework, sigue acotado y no cambia el veredicto. Como mucho merece una línea en la documentación si se confirma.

---

## Ejes

### A · Aceptación

Se cumplen los tres criterios funcionales:
- **Firma HMAC con marca de tiempo** sobre los bytes persistidos.
- **`UrlGuard`** bloquea IP privadas y redirecciones, al crear y al entregar.
- **Reintentos que se agotan y se registran.** Lo que R2 marcaba como incumplido queda resuelto por I-4: 5 intentos de entrega, más una cota independiente de 3 fallos del ejecutor, con motivo `worker_failed`.

Los gates finales declarados son suficientes: el navegador amplio 45/45 se ejecutó antes de cambios que solo tocaban metadatos del job, el test PHP y la documentación, y el focal 6/6 corre sobre el árbol definitivo. Con este informe se cumple la condición de revisión adversarial sin Critical ni Important abiertos.

### B · Corrección

- Sin defectos abiertos de severidad alta.
- Los releases de negocio y las excepciones del ejecutor quedan desacoplados de forma correcta, y la interacción con el lock único es correcta (se libera en `failed`).
- Una caída tras el HTTP y antes del commit revierte `attempts++` y repite la petición. Está documentado como petición ambigua deduplicable por UUID.
- Queda M-10 (Minor).

### C · Seguridad

- Sin hallazgos.
- La prelectura sigue fuera de la transacción y la propiedad se revalida bajo lock.
- La corrección de M-9 no envía nunca a una URL no validada.
- Corregir M-10 como propongo no afloja el guard.

### D · Rendimiento y disponibilidad

- M-9 resuelto: muertes de worker acotadas a 3 por entrega.
- M-11 es una optimización opcional.
- El coste de la caché database (lectura, `add` y `forget` por ejecución) es despreciable frente al HTTP.

### E · Calidad

- `DeliverWebhook` queda declarativo, con atributo y propiedades nativas, sin lógica temporal propia.
- Las pruebas nativas son claras y el helper está bien nombrado.
- Queda N-2.
- Persisten los menores ya aceptados: el closure de `run()`, el `last_error` duplicado y el `RequestException` muerto.

---

## Política opcional (no son defectos contra el contrato)

- Etiqueta de estado distinta de «Reintentos agotados» para `worker_failed` con `attempts = 0`. El motivo ya lo explica.
- Retención y poda (M-3).
- Cola dedicada (M-4).
- Reactivar entregas canceladas o bloqueadas.
- Rotación del secreto con doble firma.
- Tratamiento de DNS no resoluble al entregar (M-10): reintentable, o terminal pero documentado.

---

## Para cerrar

1. **Bloqueante:** ninguno. La tarea puede marcar la revisión adversarial como superada y pasar a cierre e integración según el procedimiento del ledger, que depende de que los padres T77/T78/T79 estén aprobados.
2. **Recomendado antes o después de integrar:**
   - M-10: hacer reintentable `unsafe_url` al entregar, o documentarlo.
   - N-2: migrar el test `:59-83` a `nativeWebhookWorker()`.
3. **Opcional:** M-11.