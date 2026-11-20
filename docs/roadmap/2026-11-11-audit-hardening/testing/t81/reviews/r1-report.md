# Revisión adversarial T-81 (intento 1/3), estática

## Veredicto

**No se puede cerrar todavía.** Hay dos hallazgos Important contra el contrato aprobado y uno Important condicionado a un hecho de despliegue que falta. No encuentro ningún Critical.

Los gates globales también siguen abiertos:
- PHP r38 y navegador r39 están en curso.
- M-07 sigue a la espera del usuario. Está bien documentado y no se sustituye por mocks.

Las pruebas con SDK y HTTP falso, Node y el navegador con APIs simuladas no acreditan recepción en un dispositivo ni un worker nativo. La documentación lo dice correctamente en `docs/usuario/pwa.md:27`.

## A. Conformidad con el contrato

Cumple, según el código:
- **Manifiesto:** `id`, `start_url` y `scope` son `/`, con `display` standalone e iconos de 192 y 512.
- **Service worker:**
  - No tiene manejador `fetch` ni usa CacheStorage o IndexedDB.
  - El texto es genérico y no lee el payload.
  - El click va siempre a `/notifications`.
  - Registrar el worker no pide permiso.
- **Permiso:** se pide como primera operación asíncrona dentro del gesto del usuario.
- **Suscripciones:**
  - Endpoint y claves usan cast `encrypted`/`encrypted:array`, están en `$hidden` y figuran en el inventario de recifrado.
  - No se flashea old_input: se eliminan de `request`/`json` antes de validar (`PushSubscriptionController.php:31-38`).
  - El límite de 20 dispositivos se comprueba bajo lock de User.
  - El throttle es por usuario.
  - El hash del endpoint es único y `Ownership::assert` impide transferirlo.
- **VAPID:**
  - Valida claves P-256 canónicas de 65/32 bytes y comprueba la pareja con OpenSSL.
  - El subject debe ser mailto o https.
  - Se usa `aes128gcm` explícito, evitando el default legacy `aesgcm` (`Subscription.php:19`).
- **Clasificación de respuestas:** 2xx entregado; 404/410 expirado; 408/425/429/5xx reintento; 3xx y resto rechazado.
- **Cola:**
  - Listener solo para `database`, usuario verificado y configuración válida, con `afterCommit`.
  - El job lleva solo identificadores y revalida dueño, verificación, configuración y aviso no leído.
  - `ShouldBeUnique` 600 s, 3 intentos, backoff 30/120.
  - Un fallo al encolar se registra sin romper el aviso in-app.
- **Dependencias:** las 6 transitivas cuadran con el lock: minishlink, discovery, httplug, promise, base64url y jwt-library.

Incumple o queda en riesgo: I-1 (revocar al cerrar sesión), I-2 (revocar en servidor aunque PushManager falle) y M-1 (sin conexión debe reintentarse).

## B. Corrección: concurrencia, DNS y HTTP

**MySQL/MariaDB (producción).**
- `lockForUpdate` sobre la suscripción serializa los envíos del mismo dispositivo.
- La segunda transacción relee `last_notification_id` ya confirmado, porque la lectura con bloqueo ve la última versión.
- La redelivery inmediata queda deduplicada.
- El lock se mantiene durante DNS (sin timeout propio en `dns_get_record`), conexión de 3 s y tiempo HTTP de 10 s. El límite real es `$timeout = 20` del job (`SendPushNotification.php:23`).
- Si el worker muere, la conexión se cierra y hay rollback.
- Una revocación concurrente espera como máximo unos 20 s, menos que `innodb_lock_wait_timeout` por defecto (hecho no aportado).
- No veo ciclo de deadlock entre el job (solo suscripción) y `subscribe` (User y luego suscripción).

**SQLite (tests).**
- La escritura de `last_attempted_at` antes del HTTP toma el lock RESERVED.
- Un segundo job concurrente leyó un estado anterior, pero no podrá escribir: recibirá BUSY o BUSY_SNAPSHOT y fallará hacia reintento.
- Al reintentar verá el ID ya guardado. Es aceptable; no presupone un worker del sistema.

**Caída ambigua.** Si la caída ocurre tras el HTTP y antes del commit, hay reenvío. Coincide con el contrato ("no exactamente una vez").

## C. Seguridad

- El SSRF hacia hosts internos está cubierto:
  - UrlGuard valida al alta y de nuevo al enviar.
  - IP fijada con CURLOPT_RESOLVE, proxy vacío, sin redirecciones.
  - El `aud` del JWT VAPID es el origen del endpoint, así que no se puede reutilizar contra servicios reales.
- No veo fugas de endpoint ni claves en props, logs (solo la clase de la excepción) ni en el job serializado.
- La cookie va cifrada por EncryptCookies, HttpOnly, Lax y siempre filtrada con `ownedBy`.

## D. Rendimiento

Solo hay costes menores (ver M-6). Con 20 dispositivos por usuario como máximo, el fan-out está acotado.

## E. Calidad del código

Legible y con responsabilidades bien separadas:
- `PushConfiguration`: validación de configuración.
- `PushSubscriptions`: dominio.
- `PushHttpClient`: transporte.
- `WebPushSender`: SDK.
- Job y listeners: orquestación.

Hay duplicación de la codificación base64url canónica en `PushConfiguration.php:38-41` y `PushSubscriptions.php:29-31` (puede ir a un helper), además de campos muertos (M-5).

---

## Hallazgos accionables

### I-1 · Important · `app/Http/Controllers/PushSubscriptionController.php:42`, `app/Listeners/RevokePushOnLogout.php:17-21`

**Problema.** La cookie `push_device` caduca a los 43200 minutos (30 días) y solo se emite en `store`. La suscripción, en cambio, no caduca nunca.

**Escenario.**
1. El usuario A activa el push en el día 0 en un equipo compartido.
2. En el día 31 cierra sesión. La cookie ya no existe, así que el listener no revoca nada.
3. El navegador sigue recibiendo avisos de A.
4. La usuaria B entra en ese navegador y pulsa Activar. `activatePush` reutiliza la misma suscripción (mismo endpoint).
5. `PushSubscriptions.php:53-54` lanza `OwnershipException`, que se convierte en 404. B no puede suscribirse y ve una página de error de Inertia.

Además, el indicador `current` (línea 24) desaparece en silencio.

**Contrato afectado.** "Logout revoca solo ese usuario/dispositivo".

**Corrección propuesta.**
- Hacer la cookie de larga duración (por ejemplo `forever`) o renovarla en `index` o en cada petición autenticada cuando la fila exista.
- Opcionalmente, que el cliente envíe el hash del endpoint al cerrar sesión como respaldo best-effort.
- Para B: convertir la colisión con otro dueño en un `ValidationException` genérico. El cliente puede entonces hacer `unsubscribe()` y volver a suscribir, lo que genera un endpoint nuevo y sigue sin transferir la fila.

**Test que falta.** Colisión entre dueños (A conserva su fila y B recibe un error manejable).

### I-2 · Important · `resources/js/Pages/Profile/Notifications.vue:45-48`, `resources/js/lib/push.js:47-49`

**Problema.** La revocación espera `registerPushWorker()`, después `navigator.serviceWorker.ready` y después `unsubscribe()` antes de enviar el `DELETE`. El `try/catch` solo cubre el rechazo de esas promesas, no que nunca se resuelvan.

**Escenario.** El worker queda registrado pero nunca se activa (fallo de install o evaluación), o `unsubscribe()` no termina sin red hacia el servicio push. Entonces `ready` o `unsubscribe` no se resuelven nunca, el `router.delete` no se ejecuta, `busy` queda en `true` y toda la lista queda deshabilitada.

**Contrato afectado.** "El dispositivo se puede revocar en servidor aunque cookies o PushManager fallen".

**Corrección propuesta.** Enviar primero el `DELETE` al servidor y hacer la cancelación del navegador como best-effort con `Promise.race` y un timeout de unos 3 s. Aplicar el mismo timeout en `activatePush:27` para no dejar `busy` colgado.

**Test que falta.** Un test Node con `ready` que nunca se resuelve.

### I-3 · Important (condicional; falta un hecho) · `routes/web.php:222`, `app/Http/Controllers/PwaController.php:12`, `resources/js/lib/push.js:14`

**Problema.** `/sw.js` no es un fichero estático: lo sirve Laravel.

**Escenario.** El despliegue es Nginx + PHP-FPM en Hestia (`bootstrap/app.php:57`). Las plantillas habituales tienen un `location ~* \.(css|js|…)$` sin `try_files … /index.php`. En ese caso producción devolvería 404 para `/sw.js`, el registro fallaría siempre y no habría ni push ni instalación.

**Problema secundario (Minor).** La ruta está en el grupo `web`. Cada comprobación de actualización del worker puede crear sesión (con `SESSION_DRIVER=database` son filas nuevas) y enviar `Set-Cookie` en un script público.

**Corrección propuesta.** Publicar `public/sw.js` estático (copia en build o fichero versionado). `updateViaCache:'none'` ya evita la caché HTTP del script. Alternativa: sacar la ruta del grupo `web`.

**Hecho que falta.** La configuración nginx de producción para `.js`.

### M-1 · Minor · `app/Services/Push/PushHttpClient.php:19`, `app/Services/Push/WebPushSender.php:44-45`

**Escenario.** Un corte transitorio del resolver hace que `UrlGuard::resolve` devuelva `[]`. Eso lanza `UnsafeUrlException` con el mensaje NOT_ALLOWED y se clasifica como `Rejected`. Se escribe `last_notification_id` y ese aviso se pierde para el dispositivo sin reintento.

**Contrato afectado.** "No conexión → retry".

**Corrección propuesta.** Distinguir en el transporte "no resuelve" de "IP privada" mediante una subclase o código interno no visible al usuario, de modo que el oráculo CWE-204 no se expone.

**Documentación.** `docs/usuario/pwa.md:25` presenta 3 s/10 s como límites, pero la resolución DNS no está acotada (solo por el timeout de 20 s del job). Hay que aclararlo.

### M-2 · Minor · `docs/usuario/pwa.md:17`

La documentación cita `app:reencrypt-secrets`, pero la firma real es `app:reencrypt` (`ReencryptSecrets.php:14`). Un operador que rote APP_KEY ejecutaría un comando inexistente.

### M-3 · Minor (huecos de test sobre propiedades del contrato) · `tests/Feature/Push/PushSubscriptionTest.php`

No hay pruebas de:
- (a) No transferencia de un endpoint entre dueños (`PushSubscriptions.php:53-54`).
- (b) Una validación fallida no flashea `endpoint`/`keys` en `_old_input` (`PushSubscriptionController.php:31-38`).
- (c) Cerrar sesión con el almacén caído: hace logout, registra el aviso y olvida la cookie (`RevokePushOnLogout.php:22-26`).
- (d) El listener omite el encolado con usuario no verificado, push desactivado o canal distinto de `database` (`QueuePushNotification.php:23-25`).

El código parece correcto en los cuatro casos, pero no hay regresión que lo proteja.

### M-4 · Minor · `app/Services/Push/PushSubscriptions.php:52` (MySQL)

**Escenario.** `SELECT … FOR UPDATE` sin coincidencia sobre el índice único toma un gap lock. Si dos usuarios distintos dan de alta endpoints nuevos a la vez, ambos insertan en el mismo hueco, se produce el error 1213 (deadlock) y uno recibe un 500.

En una app personal es raro. Se puede capturar `QueryException` por deadlock o clave duplicada y reintentar una vez, o devolver un error de validación.

### M-5 · Minor (calidad y mantenibilidad)

- `migration:18`: la columna `content_encoding` nunca se lee; el sender pone `'aes128gcm'` fijo (`WebPushSender.php:32`).
- `SendPushNotification.php:50-51`: `last_attempted_at` solo persiste si no hay reintento, porque el `Retry` hace rollback. El nombre engaña; conviene documentar que existe para tomar el lock o renombrarlo.
- `PushSubscriptions.php:65`: `last_seen_at` solo se actualiza al suscribir, pero la UI lo llama "Última actividad" (`Notifications.vue:114`).
- `WebPushSender.php:33` y `sw.js:18`: el `url`, el título y el cuerpo del payload, y `data.url`, no se usan nunca. Es inocuo pero sugiere un acoplamiento que no existe. Conviene comentarlo o enviar un payload mínimo.
- `composer.json:6-9`: el reformateo de `keywords` es ruido ajeno a la tarea.

### M-6 · Minor (rendimiento) · `app/Services/Push/PushConfiguration.php:8-31`

`enabled()` vuelve a parsear la clave con OpenSSL en cada llamada. Se invoca en:
- el listener, una vez por aviso (`QueuePushNotification.php:24`);
- el job (`SendPushNotification.php:39`);
- el sender (`WebPushSender.php:21`);
- la vista (`publicState`).

Se puede memoizar por instancia o por huella de configuración.

### Nits

- `tests/Feature/Push/PushSubscriptionTest.php:50-51` y `tests/e2e/push.spec.ts:26` dejan ficheros `.cnf` en storage. El `finally` (`push.spec.ts:122-125`) no borra `push-e2e-openssl.cnf`.
- `.env.production.example:189`: falta el aviso que sí tiene webhooks ("una cola distinta de poll requiere su worker").

---

## Políticas opcionales (no son defectos del contrato)

1. **Allowlist de hosts push** (FCM, Mozilla, Apple) en lugar de "cualquier HTTPS público". El contrato aprobado permite HTTPS público.
2. **Suscripciones rechazadas indefinidamente.** Con 401/403 tras rotar VAPID, la fila se queda y cada aviso hace un HTTP inútil (`SendPushNotification.php:61-64`). Se podrían podar tras N rechazos.
3. **Reintentos agotados** generan `failed_jobs` y alerta de salud por correo (`HEALTH_ALERT_EMAIL`). Para un canal best-effort quizá convenga `failed()` silencioso o marcarlo como rechazado en el último intento.
4. **`php-http/discovery: true` en `allow-plugins`** (`composer.json:99`, preexistente). Como el cliente PSR-18 se inyecta, se podría poner a `false` para que futuras instalaciones con plugins no modifiquen dependencias.
5. **`notificationclick`** siempre abre ventana nueva. Podría enfocar un cliente ya abierto.
6. **Fan-out de muchos avisos seguidos:** el `tag` del worker colapsa la presentación, pero cada aviso sigue costando una llamada HTTP por dispositivo.

## Hechos que faltan

- Configuración nginx o Hestia para `*.js` (determina I-3).
- Directivas CSP de `SecurityHeaders`: `worker-src`/`script-src` para `/sw.js` y `manifest-src`.
- Middleware del grupo de rutas `profile/*`: auth y verified.
- Que la ruta `notifications.index` sea `/notifications`.
- Que todas las notificaciones de la app usen `'database'` como cadena en `via()` y no `DatabaseChannel::class`.
- `innodb_lock_wait_timeout` de producción.

## Reverificación requerida tras corregir

1. Pest Push y recifrado, incluidos los nuevos tests M-3, en SQLite y MySQL.
2. Node con el caso de `ready` que nunca se resuelve (I-2): el `DELETE` al servidor debe salir igualmente y `busy` debe volver a `false`.
3. Navegador (E2E-20) repitiendo la revocación con `unsubscribe()` que no se resuelve: la tarjeta desaparece y el recuento en servidor es 0.
4. Test de la cookie (I-1):
   - Una suscripción existente con la cookie ausente o caducada recupera el identificador al visitar `index` o al reactivar.
   - El logout posterior revoca solo ese dispositivo.
5. Test de colisión entre dueños: B recibe un error de validación manejable (no 404), la fila de A queda intacta y no se transfiere el `user_id`.
6. Si se corrige M-1: un fallo de DNS da `Retry`, una IP privada da `Rejected`, y el mensaje expuesto sigue siendo el mismo.
7. Comprobación de despliegue para I-3: `GET /sw.js` en un entorno con la configuración nginx real devuelve 200 y `text/javascript` y no crea sesión. Esta comprobación es del operador; no se sustituye con un test local.
8. Gates globales PHP r38 y navegador r39 en verde sobre el candidato corregido, además de TypeScript, vue-tsc, Pint, ESLint, Prettier y build.
9. Si cambian `composer.json` o el lock (por ejemplo, la política 4): `composer validate --strict` y `composer audit` sobre el lock final, sin modificarlo después.

## Resumen por eje

| Eje | Estado | Bloqueantes |
|---|---|---|
| A Conformidad | Parcial | I-1 (logout tras 30 días), I-2 (revocación en servidor si PushManager no responde) |
| B Corrección | Buena en MySQL y SQLite; clasificación DNS débil | M-1, M-4 |
| C Seguridad | Sin fugas ni SSRF interno; SSRF ciego a internet público aceptado por contrato | — |
| D Rendimiento | Correcto a escala personal | M-6, `/sw.js` en grupo `web` |
| E Calidad | Buena separación de responsabilidades; campos muertos y duplicación base64url | M-5 |
| Despliegue | Riesgo de 404 del worker en Hestia | I-3 (falta el hecho) |

**Decisión:** cambios requeridos. Hay que corregir I-1 e I-2, confirmar o resolver I-3 y añadir los tests de M-3. M-1, M-2 y M-5 son baratos y conviene incluirlos en la misma iteración. M-07 (claves reales, HTTPS de producción y dispositivos) sigue a la espera del usuario y no bloquea esta implementación local.

<!-- El stream original tiene dos fragmentos. Se elimina únicamente una línea cortada que el segundo repite completa; los recibos originales permanecen íntegros. -->
