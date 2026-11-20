# Revisión adversarial T-81, intento 2 de 3 (estática)

## Veredicto

**Aprobable para integración local.** No quedan hallazgos Critical ni Important abiertos contra el contrato aprobado.

- Los tres Important de R1 están corregidos con evidencia RED/GREEN coherente con el parche.
- Los Minor de R1 están corregidos o rechazados con una justificación aceptable.
- Solo encuentro un Minor nuevo condicionado a un hecho, más nits y políticas opcionales. Ninguno bloquea.
- M-07 (claves VAPID reales, HTTPS de producción y recepción en dispositivos) sigue a la espera del usuario como política aprobada. No se sustituye por mocks y no bloquea la implementación local.
- El QA final ya está completo, así que no pido repetir gates.

## Reevaluación de R1

| ID | Estado | Evidencia en el parche |
|---|---|---|
| **I-1** cookie, renovación y colisión | **Cerrado** | Ver detalle 1 |
| **I-2** DELETE primero y timeouts | **Cerrado** | Ver detalle 2 |
| **I-3** worker estático | **Cerrado por construcción** | Ver detalle 3 |
| **M-1** DNS rechazado sin reintento | **Aceptado como política documentada** | `docs/usuario/pwa.md:27` lo declara explícitamente. Mantiene UrlGuard y el mensaje único (CWE-204). R1 lo calificó Minor y pidió el test diferencial solo si se cambiaba la clasificación. No se reabre. |
| **M-2** | **Cerrado** | `pwa.md:17` cita `app:reencrypt`, que coincide con `ReencryptSecrets.php:14`. |
| **M-3** | **Cerrado** | Ver detalle 4 |
| **M-4** deadlock | **Cerrado** | `PushSubscriptions.php:51-72` con `DB::transaction(..., 3)`. No hay HTTP dentro de la transacción y la ValidationException no se reintenta. Reproducido en MySQL 8.4.3: RED con 1213 y GREEN en 5 rondas con 2 dueños. |
| **M-5** | **Cerrado** | Ver detalle 5 |
| **M-6** y base64url | **Aceptado como opción** | La justificación es suficiente: máximo 20 dispositivos, ningún cuello de botella medido y se evita estado obsoleto. Es optimización, no defecto. |
| **Listeners duplicados** | **Cerrado** | Ver detalle 6 |
| Nits de R1 | **Cerrados** | El CNF se borra en `PushSubscriptionTest.php:82` y en `push.spec.ts:129`. El aviso de worker está en `.env*.example`. |

**Detalle por hallazgo:**

1. **I-1.**
   - `PushSubscriptionController.php:65-68` usa `Cookie::forever` con Secure según la petición, HttpOnly y Lax. Hay un único helper.
   - La renovación está en `:26-29`, solo si la fila es propia.
   - La colisión entre dueños es ahora una `ValidationException` (`PushSubscriptions.php:55-57`), sin transferir la fila y sin `old_input`.
   - Tests: `PushSubscriptionTest.php:51-74` y `:129-144`.
2. **I-2.**
   - `Notifications.vue:45-54`: `router.delete` sale sin esperar ninguna API Push. La cancelación en el navegador va en `onSuccess`, sin devolver la promesa a Inertia, y `busy` se libera en `onFinish`.
   - `push.js:56-71` limita cada operación con limpieza del timer. El permiso queda fuera del límite (`:21`), que es correcto.
   - Node `push-client.test.mjs:80-89` y E2E con `unsubscribe` infinito (`push.spec.ts:52-55` y `:119-126`).
3. **I-3.**
   - `public/sw.js` es estático, sin ruta ni controlador.
   - El E2E comprueba 200, `javascript` y que no hay Set-Cookie (`push.spec.ts:7-10`).
   - La comprobación en Nginx/Hestia real queda documentada como tarea del operador dentro de M-07 (`pwa.md:31`).
4. **M-3.** Las cuatro coberturas están:
   - No transferencia: `:63-74`.
   - Sin `old_input`: `:146-154`.
   - Almacén caído al cerrar sesión: `:156-174`.
   - Listener que ignora no verificado, desactivado y `mail`: `:176-195`.
   - El matcher exacto `once()` destapó además el defecto real de los listeners duplicados.
5. **M-5.**
   - Se eliminó `content_encoding` de la migración.
   - `last_attempted_at` está documentado como marca técnica (`SendPushNotification.php:50-51` y `pwa.md:29`).
   - La UI dice "Última suscripción" (`Notifications.vue:116`).
   - El payload es `{}` (`WebPushSender.php:34`) y el worker ya no usa `data.url`.
   - `composer.json:6` vuelve a su formato original.
6. **Listeners duplicados.**
   - `PushServiceProvider.php:19-24` ya no registra listeners a mano.
   - El descubrimiento queda confirmado por el framework: `Application.php:251`, `ApplicationBuilder.php:97-113` y `EventServiceProvider.php:136-139` con el alias `AppEventServiceProvider`.
   - Los dos listeners tienen `handle` tipado. `event:cache` está documentado (`pwa.md:31`) y verificado con la caché real de eventos.

## A. Aceptación

Los criterios del ledger se cumplen:
- **Instalable sin caché privada.** El manifiesto válido sigue igual que en R1. El worker no tiene `fetch` ni almacenamiento, según `service-worker.test.mjs:44-57`.
- **Suscripciones.** Se guardan cifradas y se pueden revocar.
- **PushSender falso.** Unitario en `:245-301` y E2E con `GeneralFakePushSender`.
- **Revisión.** Sin Critical ni Important abiertos tras esta revisión.
- **Pest, Playwright y documentación.** Según el QA final aportado.

Límites declarados correctamente:
- best-effort sin outbox;
- deduplicación solo inmediata;
- no "exactly once";
- identificación del dispositivo dependiente de la cookie;
- M-07 pendiente.

## B. Corrección

- **Job.** El lock por fila, la relectura de `last_notification_id`, el rollback en Retry y la eliminación por 404/410 no cambian respecto a R1, que ya los aceptó.
- **Transporte.**
  - `ConnectionException` no es PSR `ClientExceptionInterface`, así que sale de `WebPush::flush` (`WebPush.php:182-187`) y se captura como Retry (`WebPushSender.php:43-44`). Es correcto.
  - `UnsafeUrlException` da Rejected, como dice la política documentada.
- **Alta.** El orden de locks User → suscripción se mantiene. No hay ciclo con el job, que solo bloquea la suscripción, ni con el logout o el DELETE, que solo borran la suscripción.
- **Validación.** La `ValidationException` dentro de la transacción hace rollback y no se reintenta, porque no es un error de concurrencia.
- **Cookie.**
  - El id está autoincremental también en SQLite (`$table->id()`), así que no se reutiliza.
  - El logout filtra por `ownedBy`.
  - La destrucción solo olvida la cookie si coincide con el dispositivo borrado (`Controller:58-60`).

## C. Seguridad

Sin cambios negativos:
- **SSRF.** Validación al alta y al envío, IP fijada, sin proxy ni redirecciones.
- **Fugas.** Endpoint y claves no aparecen en props, logs, job ni `old_input`.
- **Colisión.**
  - El mensaje es genérico.
  - Para aprovechar la colisión como oráculo haría falta poseer el endpoint y las claves de otra persona, que son en sí una capacidad secreta. No es un oráculo útil.
- **Cookie.** Va cifrada por EncryptCookies y siempre se filtra por dueño.
- **Worker.**
  - No lee el payload y navega solo a `/notifications` del propio origen.
  - La CSP aportada permite `worker-src 'self'` y `default-src 'self'`, lo que cubre el manifiesto.

## D. Rendimiento

- Correcto a escala personal: fan-out de 20 dispositivos como máximo y validación OpenSSL por llamada aceptada.
- Que `/sw.js` sea estático elimina la sesión y el PHP en cada comprobación de actualización.
- La renovación de la cookie en `index` es despreciable.

## E. Calidad

- La separación de responsabilidades que valoró R1 se mantiene.
- Los comentarios de concurrencia (`PushSubscriptions.php:50` y `SendPushNotification.php:50-51`) son precisos.
- Los tests son deterministas: reloj del sandbox con watchdog externo y matcher de log exacto.
- Solo quedan los nits de abajo.

---

## Hallazgos accionables

### N-1 · Minor (condicional; falta un hecho) · `resources/js/Pages/Profile/Notifications.vue:31` y `:48-50`

**Problema.** Los mensajes se escriben en `messages`, que es estado local del componente, desde callbacks que se ejecutan después de que Inertia haya aplicado la nueva página:
- `onError` en la activación;
- el `catch` de `revokeBrowserPush` en la revocación, que puede llegar hasta unos 12 s después (4 operaciones de 3 s).

Si la visita no preserva el estado, el adaptador de Vue vuelve a montar la página y esos mensajes van a una instancia ya desmontada.

**Escenario A (colisión).**
1. La usuaria B pulsa Activar en un navegador cuya suscripción sigue vinculada a A.
2. El servidor responde 302 con `errors.endpoint`.
3. `onError` escribe en el ref antiguo.
4. B ve la página sin ningún mensaje y no sabe que tiene que restablecer el permiso.

Esto vacía el "error manejable" que exigía I-1. El test PHP (`:70-72`) solo comprueba la sesión, y no hay E2E del mensaje visible.

**Escenario B (revocación).** El aviso "Revisa también el permiso de este navegador" no se muestra nunca.

**Hecho que falta.** La versión de `@inertiajs/vue3` y si, con `router.post`/`router.delete` sin la opción `preserveState`, la página se vuelve a montar. Por defecto la clave del componente cambia cuando `preserveState` es falso.

**Corrección propuesta.**
- Pasar `preserveState: true` en las dos visitas. Las props, incluida `subscriptions`, se siguen actualizando.
- Alternativa: pintar `usePage().props.errors`.

**Test.** Un E2E de colisión que compruebe que `role="alert"` contiene el texto genérico.

### N-2 · Nit · `routes/web.php:220`

Queda un comentario huérfano, "Public, static worker…", sin ninguna ruta debajo. Despista a quien lea el fichero de rutas. Conviene eliminarlo o moverlo a `public/sw.js`, que ya tiene el suyo.

### N-3 · Nit (accesibilidad) · `Notifications.vue:119-121`

Con varios dispositivos hay N botones con el mismo nombre accesible, "Revocar". En navegación por lista de controles no se distinguen. Propuesta: `:aria-label="\`Revocar ${device.device_name}\`"`. axe no lo marca como serious; es una mejora.

### N-4 · Nit (cierre del ledger) · bloque "Archivos" de T-81

Lista ficheros que no existen en el parche:
- `app/Jobs/DeliverPushSubscription.php`
- `resources/js/Pages/Profile/Edit.vue`
- `resources/js/pwa/**`

Además duplica `PushServiceProvider.php` y `bootstrap/providers.php`. Conviene ajustarlo al cerrar para que el inventario coincida con los 40 archivos congelados.

---

## Políticas opcionales (no son defectos del contrato)

1. **Timeout de `pushManager.subscribe` (`push.js:36-38`).**
   - En el primer alta en móvil con red lenta, el registro con FCM o APNs puede superar 3 s.
   - El usuario vería "no ha respondido a tiempo", aunque el siguiente intento se recupera porque `getSubscription()` ya devuelve la suscripción creada.
   - Se podría usar unos 10 s solo para `subscribe`.
2. **Recuperación automática ante colisión.** El cliente podría hacer `unsubscribe()` y volver a suscribir, lo que da un endpoint nuevo sin transferir la fila. Ahora la recuperación es manual (restablecer el permiso) y está documentada en `pwa.md:11`.
3. **Sesión expirada sin Logout.** La cookie de A persiste y el siguiente usuario en ese navegador recibe la colisión. Es coherente con la regla aprobada de no transferir y está cubierto por la documentación.
4. **Poda de suscripciones inactivas** (por `last_seen_at`), dentro de la política de retención.
5. Se mantienen las políticas que R1 consideró opcionales:
   - HTTPS público en lugar de allowlist de hosts;
   - sin poda de rechazos 401/403;
   - `failed_jobs` con alerta de salud;
   - plugin `php-http/discovery` permitido;
   - `openWindow` siempre en ventana nueva.

## Hechos que faltan

- Comportamiento de remontaje de Inertia con estas visitas (decide N-1).
- **Fuera del alcance de T-81, solo como observación:** si `WebhookServiceProvider` de T-80 registra a mano listeners que viven en `app/Listeners`. Sería la misma clase de duplicación que se acaba de corregir aquí. No bloquea T-81.
- `innodb_lock_wait_timeout` de producción. Sigue sin aportarse y no se inventa; la espera máxima de unos 20 s del job lo hace no crítico.

## Resumen por eje

| Eje | Estado | Bloqueantes |
|---|---|---|
| A Aceptación | Cumple; M-07 a la espera del usuario como política | — |
| B Corrección | Correcta en MySQL y SQLite; deadlock M-4 resuelto | — (N-1 condicional, Minor) |
| C Seguridad | Sin fugas; SSRF interno cerrado; worker sin `fetch` | — |
| D Rendimiento | Adecuado a escala personal | — |
| E Calidad | Buena; nits N-2 a N-4 | — |

**Decisión:** aprobado. Recomiendo resolver N-1 (`preserveState: true` y un E2E del mensaje de colisión) y los nits en la misma iteración si es barato, pero ninguno bloquea el cierre. M-07 continúa pendiente del usuario sin sustituirse por simulaciones.