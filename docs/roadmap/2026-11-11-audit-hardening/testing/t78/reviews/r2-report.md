# Revisión independiente T78, intento 2 de 3 (estática)

## Veredicto

**Aprobable en lo estático; no integrable todavía.** No encuentro defectos Critical ni Important en el diff final. Las correcciones de R1 están bien aplicadas y las respuestas condicionales quedan resueltas con fuente. Solo añado un hallazgo Minor nuevo, condicionado a un hecho de configuración E2E (T-6).

Para integrar faltan resultados sobre el diff final:
- la suite PHP global;
- la batería MySQL;
- browser 15/15.

El 4354/9 y el browser 15/15 son anteriores a los ajustes y no se atribuyen al diff final. El RED r34 es anterior al arreglo de la pluralización. Mi opinión estática no sustituye estos gates.

## Reevaluación de R1

| Punto R1 | Estado | Base de la decisión |
|---|---|---|
| B-1 zona horaria | **Cerrado, no defecto** | `config/app.php:80` fija `'timezone' => 'UTC'` sin env, así que `parse($date,'UTC')` es correcto. En MySQL, Eloquent y la consulta cruda comparten sesión, de modo que el valor leído coincide con el escrito. |
| B-2 cobertura de sondas | **Cerrado** | `QueueProbe.php:35-45` usa la misma unión `queue.names` + `media.import_queue` + `queue.studio_render` que `SystemStatus.php:82-86` y despacha con `onQueue($queue)`. `$this->queue` se serializa con el job, así que `latestKey` usa la cola real. El cron de 15 min (`routes/console.php:84`) cabe de sobra en la TTL de 24 h. |
| B-3 borrado lógico | **Cerrado** | `SocialAccount.php:24` solo usa `BelongsToUser, HasFactory`; no hay `SoftDeletes`. |
| B-4 orden | **Corregido** | `SystemStatus.php:109` añade `->orderBy('network')`. Es válido con `ONLY_FULL_GROUP_BY` (columna agrupada). El test 165-175 fija el orden y la exclusión de desconectadas. |
| B-5 granularidad del try | **Optativo, no adoptado** | Correcto: marcar todo como no disponible no inventa ceros y cumple el contrato. No lo reabro. |
| B-6 plural | **Corregido** | `Index.vue:123` y `:141`. El E2E en `:59-60` comprueba ambas formas (ver T-6 sobre su robustez). |
| C-1 verificación de email | **Cerrado** | Detalle en el apartado C. |
| E-1 `lang="ts"` | **Convención T68, no defecto** | Está incluido en `tsconfig.frontend.json` con `checkJs`, JSDoc y DTO `.d.ts`. No lo reabro. |
| E-2 ruido de formato | **Corregido** | `runtime.ts` solo añade la línea 91 (`SYSTEM_ADMIN_EMAILS`). |
| E-3 agrupación en `.env` | **Corregido** | La variable va después de `HEALTH_ALERT_THROTTLE_MINUTES` en ambos `.env*.example`. El espacio de configuración `health` queda como decisión justificada. |
| E-4 ternarios | **Corregido** | `Index.vue:12-17`: mapa `healthStates` y un `computed` único. El indexado con la unión literal `'ok'\|'degraded'\|'down'` es válido en modo estricto. |
| T-1 proyección SQL | **Corregido** | Detalle en el apartado E. |
| T-2 TTL | **Corregido** | `SystemStatusTest.php:196-206`: a 11 min caduca la sonda individual y la última se conserva; a 25 h más caducan ambas. El mutante 600 documentado falla. |
| T-3 | **Corregido** | Desconectadas (171-174) y `native_cache_seconds` (208-213, con HealthCheck simulado). |
| T-4 recuento acoplado | **Corregido** | `spec.ts:40,43,58` deriva el recuento de `Network::cases()`. |
| T-5 latido del cron | **Cerrado** | `serve.cjs:22` solo arranca `php -S` y el frontend; no hay scheduler ni worker. |

## A. Aceptación frente al contrato

| Requisito | Estado |
|---|---|
| Panel de operador global, allowlist de servidor, correo verificado | Cumple: `SystemAccess.php:10-25` y `config/health.php:8`. Con la lista vacía no entra nadie (tests 47-52 y 157-163). |
| Agregados con fechas y cuotas conocidas, sin credenciales ni proveedores | Cumple: `SystemStatus.php:105-109` y el test de proyección 177-194. |
| El GET no encola | Cumple: no hay `dispatch` en el GET. Los tests usan `Queue::fake` y `assertNothingPushed`, y el E2E compara `jobs`. |
| HealthCheck canónico | Cumple: `run(false, true)` en `SystemStatus.php:36`. El test 29-37 compara con `run()`; con `health.native=false` ambos modos son equivalentes. |
| Sonda con timestamp, TTL 24 h separada de 10 min | Cumple: `QueueProbeJob.php:30-35`, cubierto con mutante. |
| strict/checkJs y DTO según T68 | Cumple: TS 0 según la evidencia r37. |
| Responsivo y a11y | Pendiente de browser final: el spec incluye `expectNoOverflow` y `axeSeriousCritical`. |

## B. Corrección

No hay defectos nuevos en producción. He comprobado expresamente:

- **Excepción de servicio:** `SystemStatus.php:31-33` lanza `AuthorizationException` antes de `run()`. El test 39-45 lo cubre con `shouldNotReceive`.
- **Ruta protegida por `verified`:** el test 147-155 espera la redirección a `verification.notice`. La UI (`canViewSystemStatus`) aplica la misma condición mediante `SystemAccess`, así que el menú y la ruta están alineados.
- **Fallo de tipos en la fila agregada:** si el motor devolviera un valor no string, `date(?string)` lanzaría `TypeError`. Lo captura el `catch (Throwable)` de `:58-64` y se muestra como no disponible, no como 0.
- **Simulación de caché en el test 121-136:** la primera lectura es `latestKey('publish')`, coherente con `once()`.

## C. Seguridad

No hay vulnerabilidad introducida. C-1 queda cerrado con la fuente aportada:

- **Login social:**
  - `SocialIdentityResolver` nunca crea usuarios ni toca `email` o `email_verified_at`.
  - `autoLinkGoogle` (`:196-235`) exige `email_verified === true|'true'` y que el usuario local ya esté verificado (`:210`).
  - Facebook no vincula automáticamente (`:50-51`).
  - `link()` y `transfer()` solo crean o mueven `SocialIdentity`.
- **Registro:** `RegisteredUserController.php:40-44` crea el usuario sin verificar.
- **Verificación:** `VerifyEmailController.php:21` marca la verificación tras `EmailVerificationRequest`, con `auth` + `signed` + `throttle` (`routes/auth.php:68-70`).
- **Perfil:** `ProfileController.php:91-93` anula la verificación al cambiar el email. `is_admin` se descarta porque no está en `rules()`.
- **Otras vías:** el autor afirma haber revisado toda escritura de `email_verified_at` y `markEmailAsVerified`. No tengo indicios en contra y lo acepto como respuesta con fuente.

Lo demás:
- La respuesta no incluye identidades ni secretos.
- Los logs solo registran la clase de la excepción.
- El E2E fija `SYSTEM_ADMIN_EMAILS` sin heredar el entorno del host.

## D. Rendimiento

Sin cambios respecto a R1, que ya lo aprobó:
- `orderBy` sobre la columna agrupada tiene coste despreciable.
- La prop compartida es un bucle en memoria.
- La sonda de almacenamiento de HealthCheck en cada GET es una decisión heredada y documentada.

## E. Calidad y pruebas

El código queda más limpio tras E-2, E-3 y E-4. T-1 está bien acotado:
- `DB::listen` captura el SQL real.
- La excepción `select exists(` corresponde únicamente a `HealthCheck::snapshots()` (`HealthCheck.php:171-172`), que devuelve un booleano y no materializa columnas.
- La prohibición de `access_token|refresh_token` se aplica a **todas** las consultas, sin excepción, y el mutante falla como se espera.

### T-6 · Minor (condicional) · `tests/e2e/system-status.spec.ts:59-60`, `:53-55`, `:44` y `:75-76`

**Problema.** Tras B-6, el E2E comprueba recuentos exactos de un estado **global** de la base de datos y la caché del slot:
- el título exacto `Instagram · 1 cuenta`;
- el texto exacto `1 cuenta sin fecha de caducidad de acceso.`;
- el consumo YouTube `X / Y`, leído antes de navegar;
- `jobs` antes = después.

El agregado de `SystemStatus` no filtra por usuario, por diseño.

**Escenario 1: proyectos en paralelo.** Si el tag `@responsive` ejecuta varios proyectos a la vez (por ejemplo, escritorio y móvil) en el mismo slot, el caso del operador de un proyecto coincide con el de otro, o con el caso «usuario ordinario».
- `seedComposerBrand(user.email, …)` (`:85`) crea otra cuenta Instagram activa.
- La tarjeta muestra `Instagram · 2 cuentas` y la aserción exacta falla de forma intermitente.
- Además, ambos proyectos comparten `operator.email`. El `cleanComposerBrands(operator.email)` de `:32` y del `afterEach` de un proyecto puede borrar la marca que el otro acaba de sembrar.

**Escenario 2: otras specs en paralelo.** Otra spec que encole jobs o cargue cuota de YouTube entre `:44` y `:75` rompe `after === before` o el texto de consumo.

**Hecho faltante.** `workers` y `fullyParallel` de la configuración de Playwright para estos proyectos, y si el slot es exclusivo por proyecto. Si la ejecución es serial (`workers: 1`) o el slot es exclusivo por proyecto, el hallazgo no aplica. El 15/15 previo apunta a que es serial, pero no se atribuye al diff final.

**Mejora, si no es serial:**
- usar `test.describe.configure({ mode: 'serial' })` y un email de operador por proyecto (`${project}`);
- o comprobar el plural en un nivel no global y relajar el título a `/^Instagram · \d+ cuentas?$/`.

### Nota no accionable

La exención `select exists(` del test T-1 la aceptaría cualquier consulta `exists` futura sobre `social_accounts`. Como `EXISTS` no materializa columnas y la prohibición de columnas de credenciales sigue siendo absoluta, no supone riesgo de exposición. No requiere cambio.

## Hechos faltantes concretos

1. Configuración de Playwright (`workers`, `fullyParallel`, proyectos `@responsive`) y exclusividad del slot (T-6).
2. Resultados de la suite PHP global, MySQL y browser 15/15 sobre el diff final, SHA `df1f65c1…`.

## Decisiones no reabiertas

No reabro, por falta de evidencia nueva:
- la allowlist de servidor sin `is_admin`;
- el estado global sin tenant;
- YouTube como contador local;
- la sonda de almacenamiento en el GET;
- B-5 (granularidad, optativo);
- E-1 (convención T68);
- el espacio de configuración `health` (E-3).

## Resultado por área

| Área | Valoración |
|---|---|
| A. Aceptación | Cumple estáticamente; integración bloqueada hasta los gates finales (PHP global, MySQL, browser) |
| B. Corrección | Sin defectos; B-1 a B-3 cerrados con fuente, B-4 y B-6 corregidos con RED previo |
| C. Seguridad | Sin hallazgos; C-1 cerrado |
| D. Rendimiento | Correcto, sin cambios |
| E. Calidad | E-2, E-3, E-4 y T-1 a T-5 resueltos; un Minor condicional nuevo (T-6, robustez E2E ante paralelismo) |

**Recomendación:** apto para R3 o QA final. Antes de cerrar, confirmar T-6 (o hacer el spec serial) y adjuntar los resultados de PHP global, MySQL y browser 15/15 sobre `df1f65c1…`.