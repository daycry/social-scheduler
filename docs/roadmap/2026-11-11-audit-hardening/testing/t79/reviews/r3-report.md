# Revisión T79 · intento 3/3 · estática

## Veredicto

**Acepto el código en revisión estática.** No hay hallazgos *Critical*, *Important* ni *Minor* nuevos. Quedan dos *Nit* opcionales.

- Los ajustes N1, N3 y N5–N9 están correctamente implementados.
- N4 está alineado en la documentación.
- N2 queda cerrado como decisión de política razonada. La fuente de `RefreshSocialAccount` la respalda.
- **Condición de cierre:** la integración depende de los gates globales de PHP (r58) y de navegador (r59) sobre los 30 ficheros congelados. Siguen en curso.
  - No atribuyo a este diff el resultado 4398/9, que es anterior a N1/N3/N5/N7.
  - Tomo los 51/286 de SQLite y MySQL, el rollback/up, las 5 rondas con 2 procesos, Node 789 y TS 0 como evidencia parcial válida, no como gate global.

## Reevaluación de los puntos de R2

| ID | Estado | Verificación estática |
|---|---|---|
| N1 | **Cerrado** | `EvaluateGoals.php:34-52` acumula `$failures` solo para `Throwable` que no sean `OwnershipException`. Procesa el lote completo y lanza al final un `RuntimeException` con el recuento, sin datos privados. El reintento (`tries=3`, `backoff=120`) es idempotente: <br>• el hito usa `ruleHash` sin periodo; <br>• la caída mantiene el mismo `period` durante los reintentos, porque `Days::closed` y el día de la marca no cambian en unos minutos a las 13:15 UTC; <br>• el token se deduplica por `(cuenta, expires_at)`; <br>• el correo solo se encola tras un evento nuevo (l. 62-77). <br>Los tests `GoalAlertTest:345-366` (fallo transitorio, segundo propietario avisado, reintento sin duplicar) y `GoalScheduleTest:77` (zona horaria envenenada → excepción resumen) cubren el cambio. |
| N2 | **Cerrado como política** | `ConnectionExpiry::knownDeadline` (l. 34-37) devuelve el candidato más temprano, aunque esté vencido. `RefreshSocialAccount::permanentReason` (l. 77-88) trata como permanentes los vencimientos de `data_access` y `refresh`. `needsWork` (l. 61-63) fuerza el procesamiento y `process` (l. 110-113) marca la cuenta como `requiere_reconexion`. Por tanto, un vencido no se considera dato rancio: la propia aplicación lo interpreta como «hay que actuar». El test `GoalAlertTest:336-343` fija el comportamiento y `analitica.md` lo documenta. Preferir el plazo futuro era una opción no contractual, así que no se reabre. |
| N3 | **Cerrado** | `GoalController.php:27` añade `status` a la carga ya restringida por `user_id` y `brand_id`, y la l. 37 expone solo un booleano. `goals.d.ts:21` tipa el campo. `Index.vue:387-389` muestra «Cuenta pendiente de reconexión». No se filtran estados de cuentas ajenas: la carga restringida devuelve `null` y el booleano es `false`. Lo cubren `GoalAlertTest:326-334` y E2E `goals.spec.ts:120-124`. La documentación ya precisa «mientras la cuenta siga activa». |
| N4 | **Cerrado** | `analitica.md` indica ahora «permite conservarla durante cualquier edición… No tendrá datos nuevos hasta reconectar». Coincide con `GoalService.php:69-75`. |
| N5 | **Cerrado** | `GoalEvaluator.php:57-58` usa la zona de la marca, `locale('es')` y `D [de] MMMM [de] YYYY`, idéntico a `ConnectionExpiryNotifier.php:67`. El payload `expires_at` se mantiene en ISO UTC. El test `:307-316` (23:30 UTC → «21 de noviembre de 2026» en Madrid) cubre el cruce de fecha. |
| N6 | **Cerrado** | `Index.vue:72` incluye la métrica en la descripción de las caídas. |
| N7 | **Cerrado** | La columna `last_expires_at` está en la migración inédita `091100:15` y su `down` la elimina (l. 31). Tiene cast `datetime` (`Goal.php:26`), se persiste en `GoalEvaluator.php:32` y se limpia al cambiar la regla (`GoalService.php:43`). El DTO la expone en ISO (`Controller:40`). La UI formatea en la zona de la marca y usa UTC si la zona no es válida (`Index.vue:46-52`). El test `:317-323` cubre que se conserva al renombrar y se limpia al cambiar el umbral. |
| N8 | **Cerrado** | `goals.spec.ts:36` calcula `min(ayer_marca, Days::closed(red))`. Comparar cadenas `Y-m-d` con `min()` es correcto. Coincide con `GoalObservations.php:54-58` para una cohorte de una sola cuenta. |
| N9 | **Cerrado** | `goals.spec.ts:107` usa `watch(page)` y la l. 139 comprueba `errors.errors`. |
| Latencia de YouTube | Política documentada | `analitica.md` lo explicita. No se reabre. |

## A. Aceptación

El código cumple el contrato y las aclaraciones ratificadas en R2:
- objetivos propios por marca o cuenta;
- hito, caída estricta y caducidad conocida que requiere actuar;
- ausente ≠ 0;
- misma cohorte en dos fechas cerradas;
- aviso in-app atómico y único;
- correo opcional sin outbox ni exactly-once;
- reintento por lote sin duplicar avisos.

**Pendiente, y bloquea la integración:** los gates globales de PHP y navegador sobre el SHA congelado. T79 sigue sin integrar, como corresponde.

## B. Corrección

No hay defectos nuevos contra el contrato. Revisé en concreto:

- **Interacción de N1 con `ShouldBeUnique`.** El bloqueo se mantiene durante los reintentos (`uniqueFor=3600` cubre unos 3 × (lote + 120 s)). No hay solapamiento con la ejecución del día siguiente.
- **Fallo persistente.** Un fallo determinista, como una zona horaria corrupta, deja una entrada en `failed_jobs` cada día y triplica la evaluación de ese día. Es justo la visibilidad que pedía N1. El coste ya se aceptó en R2 (D).
- **N3 frente a la caducidad.** Para cuentas `requiere_reconexion`, `candidates()` devuelve `[]`, el valor queda `null` y no se emite aviso del objetivo. El aviso específico (`ReconnectRequired`) lo emite `RefreshSocialAccount.php:188-192`. Es coherente con la documentación.
- **Hecho de R2 ya respondido.** Para `data_access` y `refresh`, el paso a `requiere_reconexion` al vencer está confirmado en la fuente, siempre que `RefreshTokens` despache la cuenta según `needsWork` (no tengo su fuente). Para el token de LinkedIn sin refresh, `permanentReason` no cubre `token_expires_at`, así que depende del conector. Es informativo, no un defecto: el test `:275-282` sigue siendo válido mientras la cuenta esté activa.

## C. Seguridad

Sin hallazgos.
- La nueva prop `account_requires_reconnection` se deriva de una relación restringida por propietario y marca. Una referencia ajena da `false` y nombre `null` (`GoalsTest:70-83`).
- La excepción resumen y los logs contienen solo el recuento, el id y la clase.
- La fecha humana del mensaje no contiene secretos.
- `last_expires_at` es una fecha, no una credencial.

## D. Rendimiento

Sin cambios materiales.
- N1 repite el lote completo solo cuando hay fallos (máximo 3 veces). Ya se aceptó.
- La columna `status` adicional en la carga ansiosa y la columna `last_expires_at` tienen un coste despreciable.

## E. Calidad: hallazgos nuevos (opcionales)

### Q1 — Nit (UX) — `resources/js/Pages/Goals/Index.vue:387-392`

**Problema.** La rama `account_requires_reconnection` va antes de la comprobación de `last_value` y se aplica a todos los tipos de objetivo.

**Escenario.** Durante la ventana de transición, el valor observado y válido se oculta:
1. Una cuenta de Instagram está activa a las 12:00 y el colector guarda el snapshot del periodo.
2. A las 12:30, `RefreshTokens` la marca como `requiere_reconexion`.
3. A las 13:15 se evalúa el hito. `read()` incluye las cuentas no desconectadas, así que obtiene el valor 1200 y avisa.
4. La pantalla de objetivos muestra solo «Cuenta pendiente de reconexión», sin el valor que motivó el aviso visible en Avisos.

**Corrección opcional.** Mostrar el estado de reconexión como línea adicional, o limitar esa rama a `last_value === null`.

### Q2 — Nit (texto) — `resources/js/Pages/Goals/Index.vue:55-61`

**Problema.** `tokenObservation` dice «Caducidad conocida: …» también cuando el plazo ya venció (`last_value < 0`).

**Escenario.** El aviso dice «La credencial caducó el …» y la tarjeta muestra una fecha pasada sin indicar que ya está vencida.

**Corrección opcional.** Usar «Caducó el …» si `goal.last_value < 0`.

### Nota residual (no accionable)

La fixture E2E (`goals.spec.ts:36`) siembra el snapshot al inicio y evalúa varios pasos después. Si la prueba cruza el cierre del día de la red (08:00 UTC para Los Ángeles) o la medianoche de Madrid (23:00 UTC en noviembre), el periodo cambia y la prueba fallaría. La ventana es de segundos y la probabilidad, despreciable. No pido cambios.

## Política opcional (no defecto)

- Que la evaluación con `Throwable` deje `failed_jobs` cada día ante un objetivo envenenado persistente es la visibilidad deseada. Una alerta específica para el operador sería producto aparte.
- Los puntos de política de R1 y R2 (agregado bloqueado, recreación, correo perdido al encolar, poda, escala) siguen aceptados y documentados.

## Hechos que faltan

1. Resultado de los gates globales de PHP (r58) y navegador (r59, 24 casos) sobre los 30 ficheros congelados.
2. Para LinkedIn sin refresh: si el conector lanza `ReconnectRequiredException` al vencer el token. Es solo informativo para la duración del valor negativo, no bloqueante.
3. Que `RefreshTokens` despacha según `RefreshSocialAccount::needsWork` (no tengo su fuente). Solo afecta a la explicación de N2, no a su corrección.

## Resumen

| Severidad | Número |
|---|---|
| Critical | 0 |
| Important | 0 |
| Minor | 0 |
| Nit | 2 (Q1, Q2), opcionales |

**Decisión.** Acepto el código en revisión estática. N1–N9 de R2 están verificados como corregidos o cerrados como política. **Se puede integrar en cuanto PHP global y navegador pasen sobre el diff congelado.** Q1 y Q2 pueden quedar como seguimiento sin bloquear.