# Revisión independiente T78, intento 3 de 3 (estática)

## Veredicto

**Aprobable. Integrable en cuanto se confirme un gate.** No hay hallazgos Critical, Important ni Minor nuevos contra el contrato. T‑6 queda cerrado: con la configuración aportada, el escenario de concurrencia no puede darse. El ajuste de la fixture posterior a R2 es correcto. La producción es idéntica a la revisada en R2 (`SystemStatus.php`, `SystemAccess.php`, `QueueProbeJob.php`, `Index.vue`, rutas y middleware), así que no reabro A–E de producción.

Queda una sola condición de proceso: falta atribuir los gates de lint, formato y tipos al spec E2E final (apartado «Gates»).

## Reevaluación de T‑6 (R2)

| Escenario R2 | Estado | Base |
|---|---|---|
| 1. Proyectos `@responsive` en paralelo sobre el mismo slot | **No aplica, cerrado** | Ver detalle 1 |
| 2. Otras specs que encolan jobs o cargan cuota entre `:49` y `:88` | **No aplica, cerrado** | Ver detalle 2 |
| `operator.email` compartido y `cleanComposerBrands` cruzado | **No aplica, cerrado** | Ver detalle 3 |
| Modo `serial` adicional | **No necesario** | El describe ya se ejecuta en orden dentro de un worker (`fullyParallel: false`). Añadirlo sería redundante. Acepto la decisión del autor. |

**Detalle 1 (proyectos en paralelo).**
- `playwright.config.ts:18-19` fija `fullyParallel: false` y `workers: workerCount`.
- `runtime.ts:134-141` asigna el slot por `TEST_PARALLEL_INDEX`. Cada slot tiene su propia SQLite (`:97`), caché `file` y `LARAVEL_STORAGE_PATH` (`:96-98`) y su propio `php -S` (`playwright.config.ts:23-29`).
- Playwright garantiza que `parallelIndex` es único entre los workers vivos. Un worker reiniciado hereda el índice, pero solo cuando el anterior ya ha terminado. Por tanto, dos proyectos pueden ejecutarse a la vez, pero nunca en el mismo slot.
- `verifyRunIdentitySync` (`:20-26`, `:149`) impide que un worker use el servidor de otro slot o de otra ejecución.

**Detalle 2 (otras specs).** Dentro de un slot solo corre un test a la vez. Los contadores `jobs` y de cuota YouTube solo los modifica el propio test entre la lectura y la navegación.

**Detalle 3 (operador compartido).**
- Otro slot implica otra BD.
- En el mismo slot, la ejecución es secuencial y el `afterEach` borra la marca (`helpers.ts:610-621`) y, con ella, sus cuentas. Eso incluye la cuenta `$single` creada con `brand_id = $b->id`.

## Verificación del ajuste de fixture (posterior a R2)

Diagnóstico del fallo de r42: el test suponía «Instagram · 1 cuenta», pero el estado acumulado del slot ya incluía otra cuenta de Instagram. Es coherente con el diseño global del agregado, que no filtra por usuario, y no indica ningún defecto de producción.

La nueva fixture (`system-status.spec.ts:34-48`, `:64-73`) es correcta:

- **Recuento de Instagram derivado del estado real.**
  - Se calcula con el mismo filtro que `SystemStatus.php:105` (`status != desconectada`, sin scope de usuario).
  - Se lee en el mismo slot y antes de la navegación, sin escritores concurrentes. El título esperado coincide, por tanto, con el que renderiza `Index.vue:121-124`.
- **Singular determinista.**
  - `$single` es la primera red sin cuentas activas en el slot. Si no existe ninguna, la fixture lanza una excepción que `artisan()` detecta (`helpers.ts:160`), así que falla de forma explícita.
  - La cuenta se crea con la factoría, que por defecto produce cuentas activas según el contrato documentado de `seedComposerBrand` (`helpers.ts:488`), y con `token_expires_at = null`.
  - Esa tarjeta tiene exactamente `1 cuenta` y `1 cuenta sin fecha…`. La aserción está acotada al `listitem` de esa tarjeta (`:70-73`), sin depender de otros contadores globales.
- **Recuento `UNKNOWN`.** `count(Network::cases()) - 1` cuenta las tarjetas `not_recorded`, que son todas salvo YouTube (`SystemStatus.php:39-56`). No depende del estado de la BD.
- **Cuota y `jobs`.** Se leen antes de la navegación y no hay concurrencia en el slot, así que las igualdades exactas son estables.
- **Extracción de datos.** El regex `STATE:(\{.*?\})` es seguro: el JSON es plano y sin llaves anidadas.

No se relaja ninguna aserción. El paso de un literal a un valor derivado es lo correcto para un agregado global por diseño.

### Observación no bloqueante (política de pruebas opcional, no defecto)

**Ubicación:** `tests/e2e/system-status.spec.ts:64-69`.

La forma plural del título de Instagram se comprueba con la misma condición ternaria que la vista. El ejercicio de la rama `cuentas` depende de que el seeder del slot deje otra cuenta de Instagram, como indica el autor. Si un slot arrancara con 0 cuentas de Instagram residuales, el test seguiría en verde, pero solo cubriría el singular, sin avisar.

En la práctica, el seeder determinista y el r44 en verde en tres anchos indican que el plural sí se ejerció. Si se quisiera que esa cobertura fallara de forma explícita bastaría con `expect(state.instagram).toBeGreaterThan(1)`. Es opcional y no bloquea: B‑6 ya se aceptó en R2 con su RED r34.

## A–E (producción)

No hay diff productivo desde R2, así que mantengo las valoraciones de R2 sin reabrir decisiones aceptadas:
- allowlist de servidor;
- estado global;
- YouTube como contador local;
- sonda de almacenamiento en el GET;
- B‑5;
- E‑1;
- espacio de configuración `health`.

| Área | Valoración |
|---|---|
| A. Aceptación | Cumple. Responsividad y a11y quedan ahora acreditadas por el browser r44 (`expectNoOverflow` + `axeSeriousCritical` en 3 anchos). |
| B. Corrección | Sin defectos. La fixture nueva es correcta (ver arriba). |
| C. Seguridad | Sin hallazgos. C‑1 sigue cerrado. El E2E fija `SYSTEM_ADMIN_EMAILS` solo en el entorno del slot (`runtime.ts:91`). |
| D. Rendimiento | Sin cambios; correcto. |
| E. Calidad | T‑1 a T‑6 resueltos o cerrados. Queda la observación opcional anterior. |

## Gates: atribución al estado final

| Gate | Ejecución | ¿Cubre el diff final? |
|---|---|---|
| PHP global 4358/9, 29306 aserciones | r41 | **Sí.** Lo posterior solo toca un `.ts` E2E, que PHP no carga. |
| MySQL 8.4.3, 30/200 | r5 | **Sí,** según el autor. Producción PHP sin cambios desde entonces. |
| SQLite focal 91/567 (incluye AuthSocial y EmailVerification) | r39 | **Sí.** |
| Browser 15/15 sin fallos, omisiones ni flaky | r44, tras el ajuste | **Sí.** Ejecuta el spec final. |
| Node 789, TS 0, Pint, ESLint, Prettier, build | **r37** | **No acreditado para `system-status.spec.ts`**. Ver hecho faltante. |

**Hecho faltante (condición de cierre, proceso; no es defecto de código).**

- **Ubicación:** `tests/e2e/system-status.spec.ts` (versión final).
- **Problema:** r37 es anterior a R2, que ya lo citaba, y al ajuste posterior a r42. Playwright transpila el TS sin comprobar tipos, así que el r44 no sustituye a vue-tsc, ESLint ni Prettier.
- **Escenario concreto:** el nuevo `as { single: string; instagram: number }`, el formato de las líneas 64-73 o la guarda de `tests/frontend/test-tooling.test.mjs` sobre los specs E2E podrían fallar en CI aunque el browser esté en verde.
- **Qué hace falta:**
  1. confirmar si `tests/e2e/**` está dentro del alcance de ESLint, Prettier, `tsc` o la suite Node;
  2. si lo está, adjuntar una pasada de esos gates sobre el SHA final.
- Si `tests/e2e` está fuera de su alcance, el punto se cierra sin reejecutar. Producción (`Index.vue`, `.d.ts`, `runtime.ts`) no ha cambiado desde r37, así que su verificación sigue siendo válida.

No cierro este gate por opinión estática. Es el único elemento pendiente para integrar.

## Decisiones no reabiertas

Sin evidencia nueva, se mantiene todo lo aceptado en R1 y R2. La negativa a añadir `mode: 'serial'` es correcta y no la considero un déficit.

## Resultado

| Área | Valoración final |
|---|---|
| A. Aceptación | Cumple. Browser final 15/15 atribuido. |
| B. Corrección | Sin defectos. T‑6 cerrado; la fixture post‑R2 es correcta. |
| C. Seguridad | Sin hallazgos. |
| D. Rendimiento | Correcto. |
| E. Calidad | Sin defectos. Una observación opcional sobre la cobertura determinista del plural. |

**Recomendación:** apto para integrar los 21 archivos en cuanto se acredite que `system-status.spec.ts` pasa ESLint, Prettier y la comprobación de tipos y la suite Node, o se confirme que están fuera de su alcance. No se requiere ningún cambio de código.