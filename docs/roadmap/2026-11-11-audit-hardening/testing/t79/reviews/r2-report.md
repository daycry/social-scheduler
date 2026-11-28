# Revisión T79 · intento 2/3 · estática

## Veredicto

**El código es aceptable en revisión estática. La aceptación final queda condicionada a los gates.**

- Los cinco hallazgos de R1 (F1–F5) están corregidos. También el borde nuevo de LinkedIn con refresh vacío cifrado.
- La corrección es coherente con el contrato y con la aclaración de F4 conforme a T23.
- No quedan hallazgos *Critical* ni *Important*.
- El cierre depende de los gates globales de PHP, MySQL y navegador sobre el diff congelado (SHA `70d9a2f3…`, 30 ficheros). Siguen en ejecución. No se atribuyen resultados anteriores (4385/9) a este diff.
- Los 47/248 focales, Node 789, TS 0, build y Pint se toman como evidencia parcial válida, no como gate global.

## Reevaluación de R1

| ID | Estado | Verificación estática |
|---|---|---|
| F1 | **Cerrado** | `GoalService.php:72-75` usa `whereNot`. `DatabaseRule::whereNot` (vendor:121-134) genera `'!desconectada'`, que el verificador interpreta como `!=`. La conservación del id previo exige igualdad exacta con `$current->social_account_id` (l. 69-71) y mantiene `user_id` y `brand_id`. Una referencia ajena o corrupta sigue dando 422. Lo cubren los tests `GoalsTest:127-151` y E2E. |
| F2 | **Cerrado** | `GoalObservations.php:54-58` toma `min(ayer_marca, Days::closed(red))` con la misma referencia que `CollectSnapshots.php:42`. Auckland o Sydney a las 13:15 UTC da el 17-nov. Los Ángeles a las 02:00 UTC da el 16-nov. Ambas fechas usan la misma cohorte y no hay retroceso a snapshots antiguos. |
| F3 | **Cerrado** | `EvaluateGoals.php:38-44` captura `Throwable` por objetivo y registra solo el id y la clase. Un objetivo envenenado ya no bloquea a otros propietarios (`GoalScheduleTest:59-83`). |
| F4 | **Cerrado según la aclaración** | `knownDeadline` comparte `candidates()` con `deadline`. Los accesos autorrenovables de Google y TikTok no avisan. `deadline` mantiene su semántica para T23: ordenar y luego filtrar equivale a filtrar y luego ordenar. |
| F4b | **Cerrado** | `GoalObservations.php:32` proyecta `refresh_token` solo para LinkedIn mediante `CASE`. El cast `encrypted` descifra `''`, igual que T23. No se carga `access_token` y no hay secretos en el payload ni en los logs. |
| F5 | **Cerrado** | La clave de caída es `drop:{period}:{ruleHash}`, de 80 caracteres, por debajo de 100. `json_encode` del umbral `float` es estable. |
| Calidad | **Cerrado** | `GOALS_MAIL_QUEUE` es independiente. `GOALS_QUEUE` hereda `SOCIAL_ANALYTICS_QUEUE` (la clave real, `config/social.php:262`). El mapa de títulos es equivalente al ternario anterior. Se han añadido `aria-invalid`, `describedby`, `labelledby` y nombres accesibles por objetivo. La caducidad visible ya no muestra días fraccionarios. |
| Migraciones separadas | Nit opcional | Se acepta la respuesta. No se reabre. |

## A. Aceptación

Funcionalmente cumple el contrato:
- objetivos propios;
- hito, caída y caducidad conocida que requiere reconectar;
- dato ausente distinto de 0;
- misma cohorte en dos fechas cerradas;
- aviso in-app atómico y único;
- correo opcional sin promesa de outbox ni de exactly-once;
- `Goals/Index.vue` incluido en `tsconfig.frontend.json` con DTO en `goals.d.ts`.

**Pendiente:** PHP global, MySQL y navegador sobre el diff final. T79 sigue sin integrar.

## B. Corrección: hallazgos nuevos o residuales

### N1 — Minor — `app/Jobs/EvaluateGoals.php:42-44`

**Problema.** Un fallo transitorio pierde el periodo de caída sin reintento.
- Al capturar `Throwable`, el job nunca falla.
- Un deadlock agotado (3 intentos de `DB::transaction`) o una caída momentánea de la base de datos en un objetivo de caída solo deja un `warning`.
- Al día siguiente se evalúa P+1, así que P no se vuelve a evaluar nunca.
- Además, un fallo sistemático no aparece en `failed_jobs` ni en las sondas. Por ejemplo, todos los objetivos fallando por una columna o configuración rota en MySQL.

**Escenario.** Hay un deadlock en el objetivo 7 a las 13:15. La caída real de ese día no se avisa y el job figura como correcto.

**Corrección sugerida, opcional y no contractual.** Seguir procesando todo el lote y, si hubo `Throwable` distintos de `OwnershipException`, lanzar al final una excepción resumen. El reintento es seguro porque la deduplicación es idempotente, y el objetivo envenenado ya no bloquea a los demás.

### N2 — Minor — `app/Services/ConnectionExpiry.php:36` + `docs/modulos/analitica.md:119`

**Problema.** `knownDeadline` devuelve el **primer candidato aunque esté vencido**, incluso si existe otro futuro.

**Escenario.** Una cuenta activa tiene un `refresh_expires_at` antiguo que no se actualizó (dato rancio) y un `data_access_expires_at` real dentro de 5 días.
- El objetivo avisa una vez de que la credencial caducó el día X y queda deduplicado.
- El plazo futuro, que sí es accionable, no se avisa nunca mientras persista el valor rancio.

**Hecho que falta.** Si algún flujo de refresco deja fechas vencidas en cuentas `activa`. El fixture `Threads past` de T23 sugiere que el estado es posible.

**Opción.** Preferir el primer candidato futuro y recurrir al vencido más reciente solo si no hay ninguno futuro.

### N3 — Minor (precisión de documentación y UX) — `ConnectionExpiry.php:42-44`, `GoalObservations.php:30`, `Index.vue:381-383`, `docs/modulos/analitica.md:119`

**Problema.** Una cuenta en `requiere_reconexion` produce `candidates() = []`. El objetivo de caducidad muestra «Sin datos suficientes» justo en el estado más accionable.

**Discrepancia con la documentación.** La documentación afirma que «una fecha ya vencida sigue siendo conocida». Eso solo es cierto mientras la cuenta siga `activa`.
- Esto es coherente con T23, que avisa aparte con `reconnect_required`, así que **no es defecto de contrato**.
- Pero el texto de la documentación y de la UI es engañoso.

**Hecho que falta.** Si el refresco o la publicación pasan LinkedIn a `requiere_reconexion` al vencer. En ese caso, el valor negativo del test `GoalAlertTest:274` es efímero en producción.

**Corrección.** Matizar la documentación y mostrar «Cuenta pendiente de reconexión» en lugar de «Sin datos».

### N4 — Minor (documentación) — `app/Services/GoalService.php:69-75` frente a `docs/modulos/analitica.md:115`

**Problema.** La documentación y R2 dicen que se conserva la cuenta desconectada «al renombrar o pausar». En realidad, `keepingAccount` permite **cualquier** cambio: tipo, métrica o umbral.

**Escenario.** Se convierte a `token_expiry` una regla sobre una cuenta desconectada. Se guarda y queda permanentemente desconocida.

No es inseguro, porque la propiedad y la marca se validan siempre. Basta con alinear el texto o restringir el cambio de tipo. Ambas son decisiones de política.

### Notas sin defecto

- **`GoalEvaluator.php:40`.** `'…con '.$value['current']` muestra floats sin formato. Funciona correctamente.
- **Valor en el payload.** `payload['value']` llega como `int` o `float` según `json_encode`, sin `JSON_PRESERVE_ZERO_FRACTION`. El test se corrigió con razón. Los consumidores JS no se ven afectados.

## C. Seguridad

Sin hallazgos.
- Se carga `refresh_token` solo para LinkedIn y solo en memoria. El atributo está oculto y no hay serialización ni logs del valor. El log de excepciones registra solo la clase.
- La regla `exists` conserva `user_id` y `brand_id` también en la rama de conservación. Una referencia importada ajena da 422 y su nombre no se expone (`GoalsTest:70-83`).
- `Notifications/Index.vue`: `DYNAMIC_TITLES[n.type]` con claves de prototipo es teórico. `type` lo controla el servidor y `WORKFLOW` ya tenía el mismo patrón.

## D. Rendimiento

Sin cambios respecto a R1. Se aceptan las observaciones opcionales de escala y de ausencia de poda, ya documentadas.

El `CASE` del token añade una columna por consulta y es despreciable. N1, si se adopta, triplicaría el coste solo en ejecuciones con fallos.

## E. Calidad

### N5 — Minor (UX) — `app/Services/Goals/GoalEvaluator.php:56`

**Problema.** El mensaje de caducidad usa ISO-8601 en UTC, por ejemplo `2026-11-20T08:00:00+00:00`, en el aviso y en el asunto del correo.

T23 (`ConnectionExpiryNotifier.php:67`) formatea en la zona de la marca y en español (`D [de] MMMM [de] YYYY`). Conviene reutilizar ese formato para mantener la coherencia.

### N6 — Minor (UX) — `resources/js/Pages/Goals/Index.vue:66`

`ruleDescription` para caída no indica la métrica. Una caída de seguidores y una de engagement se ven idénticas («Caída superior al 25%»). Basta con añadir `· {{ metrics[goal.metric] }}`.

### N7 — Nit — `Index.vue:49-56`

**Problema.** La caducidad visible se reconstruye a partir de `last_value`, que es `decimal(20,4)` días, con una precisión de unos 9 s. Además, se formatea en la zona del navegador, no en la de la marca.

**Escenario.** Para una marca de Madrid vista desde otro huso, la hora mostrada difiere de la del aviso.

**Opción.** Exponer `expires_at` en el DTO. Es opcional.

### N8 — Nit (robustez E2E) — `tests/e2e/goals.spec.ts:36`

**Problema.** La prueba siembra en `Days::closed(instagram)`, pero el evaluador usa `min(ayer_marca, cerrado_red)`.

**Escenario.** Si `seedComposerBrand` usa una zona al oeste de Los Ángeles (Honolulu), entre las 08:00 y las 10:00 UTC el periodo evaluado es D-2 y la prueba falla de forma intermitente.

**Hecho que falta.** La zona del fixture. Con UTC o Europa no ocurre.

**Corrección.** Sembrar el periodo que calcula `GoalObservations`.

### N9 — Nit — `tests/e2e/goals.spec.ts:104-133`

El tercer E2E no usa `watch(page)` ni comprueba `errors.errors`, a diferencia de los otros dos.

## Política opcional (no defecto)

- **Latencia de YouTube.** El engagement de YouTube suele llegar incompleto el primer día y se completa con `settle_days`. El evaluador no reevalúa días ya pasados, así que las reglas de engagement con YouTube en la cohorte quedarán casi siempre como desconocidas. Cumple «ausente ≠ 0» y lo admite la documentación («no se buscan fechas antiguas»). Conviene mencionarlo de forma explícita.
- **Sin reintento por diseño.** Que un fallo transitorio no reintente (N1) es una decisión aceptable si se documenta.
- **Política de R1 sin cambios.** El agregado bloqueado por redes sin métrica, la recreación del objetivo y el correo perdido al encolar.

## Hechos que faltan

1. Si el refresco o la publicación cambian LinkedIn a `requiere_reconexion` al vencer el token (N3).
2. Si pueden quedar fechas de expiración vencidas en cuentas `activa` (N2).
3. La zona horaria de la marca que crea `seedComposerBrand` (N8).
4. El resultado de los gates globales de PHP, MySQL y navegador sobre el SHA congelado.

## Tests sugeridos (no bloqueantes)

- Lote con un `QueryException` simulado en un objetivo de caída, para documentar o fijar el comportamiento de N1.
- `knownDeadline` con un candidato vencido y otro futuro (N2).
- Objetivo de caducidad sobre una cuenta `requiere_reconexion`, para fijar el texto mostrado (N3).

## Resumen

| Severidad | Número |
|---|---|
| Critical | 0 |
| Important | 0 |
| Minor | 6 (N1–N6) |
| Nit | 3 (N7–N9) |

**Decisión.** Se puede aceptar en cuanto los gates globales de PHP, MySQL y navegador pasen sobre `70d9a2f3…`. N1–N9 pueden quedar como seguimiento sin bloquear la integración.