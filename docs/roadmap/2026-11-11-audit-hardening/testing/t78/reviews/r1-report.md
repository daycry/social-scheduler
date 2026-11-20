# Revisión independiente T78, intento 1 de 3 (estática)

## Veredicto

El diff es **aceptable en lo estático** frente al contrato aprobado. No encuentro defectos Critical ni Important confirmados. Hay hallazgos Minor y tres hechos condicionales que no puedo verificar con el material aportado.

**No procede integrar todavía.** Faltan resultados para este diff rebasado:
- type-check y build;
- browser 15 casos.

El browser 9/9 y el QA verde previos al rebase no se atribuyen al diff actual, como indica la propia evidencia.

## A. Aceptación frente al contrato

| Requisito | Estado | Evidencia estática |
|---|---|---|
| Permiso solo de servidor, lista vacía = nadie, correo verificado | Cumple | `SystemAccess.php:12-24`, `config/health.php` (`admin_emails`), tests 47-52 y 157-163 |
| Perfil limpia la verificación al cambiar email; sin autoescalada | Cumple | `ProfileController.php:91-93`; `is_admin` ignorado al no estar en `rules()`; test 147-155 |
| Vista, servicio y middleware reutilizan SystemAccess | Cumple | `SystemStatus.php:31`, `HandleInertiaRequests.php:47`, controlador vía servicio |
| HealthCheck en modo HTTP con caché nativa y edad máxima visible | Cumple | `SystemStatus.php:36` llama a `run(false, true)`; `:69`; `Index.vue:52-55` |
| GET no encola ni consulta redes | Cumple | No hay `dispatch` en la ruta; tests con `Queue::fake` y `Http::preventStrayRequests`; E2E compara el recuento de `jobs` |
| Sonda: fecha en cada proceso; 24 h separadas de 10 min | Cumple | `QueueProbeJob.php:34-35` |
| SQL proyectado y agregado, sin columnas cifradas | Cumple | `SystemStatus.php:105-109` |
| YouTube como contador local etiquetado; resto no disponible; sin porcentaje | Cumple | `SystemStatus.php:39-56`, `Index.vue:149-171` |
| Estado global, no de un tenant | Cumple | `DB::table` sin `UserScope`, intencionado y agregado por red |
| Fallos auxiliares explícitos, no convertidos en 0 | Cumple | `tokens_available`, `available`, `unavailable_reason` |
| Accesibilidad y tamaños | Pendiente | Hay `axe` y `expectNoOverflow` en el spec `@responsive`, pero sin resultado para el rebase |
| Componente y DTO en TS estricto | Cumple con matiz | DTO `.d.ts` y página JS con JSDoc incluida en `tsconfig.frontend.json` (ver E-1) |

## B. Corrección y robustez

**B-1 · Minor (condicional) · `app/Services/SystemStatus.php:126`**
- `CarbonImmutable::parse($date, 'UTC')` supone que las columnas `*_expires_at` se guardan en UTC.
- Escenario: si `config('app.timezone')` es, por ejemplo, `Europe/Madrid`, Eloquent guarda las fechas en hora local. La UI mostraría la caducidad desplazada 1-2 h y la etiquetaría como UTC.
- Hecho faltante: el valor de `app.timezone` en `config/app.php`.
- Corrección propuesta: `parse($date, config('app.timezone'))->utc()`.

**B-2 · Minor (condicional) · `app/Jobs/QueueProbeJob.php:35` y `SystemStatus.php:82-86`**
- La documentación afirma que la página muestra sondas «de cada cola configurada, además de media y render».
- Escenario: si `app:queue-probe` solo itera `config('queue.names')` y no incluye `media` ni `studio-render`, esas tarjetas mostrarán siempre «Sin sonda procesada en las últimas 24 horas». La afirmación sería veraz, pero el operador nunca podría obtener evidencia de esas colas.
- Escenario análogo: si el comando despacha sin `onQueue()` hacia una cola por defecto distinta de `default`, la marca queda en la clave `default` y no en la de la cola real.
- Hecho faltante: el código fuente de `app:queue-probe`.

**B-3 · Minor (condicional) · `SystemStatus.php:105`**
- `DB::table('social_accounts')` no aplica `SoftDeletes`.
- Escenario: si `SocialAccount` usa borrado lógico, las cuentas borradas pero no desconectadas inflan `account_count` y adelantan `MIN(token_expires_at)`.
- Hecho faltante: si el modelo usa `SoftDeletes` y si existe `deleted_at`.

**B-4 · Minor · `SystemStatus.php:105-109`**
- `groupBy` sin `orderBy`, así que el orden de las tarjetas de caducidad depende del motor (SQLite frente a MySQL) y puede cambiar entre lecturas.
- Las cuotas sí siguen el orden del enum.
- Corrección: añadir `->orderBy('network')`.

**B-5 · Minor (optativo) · `SystemStatus.php:88-98`**
- Un único `try` envuelve todas las lecturas.
- Escenario: un fallo puntual en una clave marca todas las colas como «No se pudo leer», aunque las demás se leyeran bien.
- Con un almacén de caché caído el resultado es correcto; con un fallo intermitente pierde granularidad. Es mejora, no defecto del contrato.

**B-6 · Minor · `resources/js/Pages/System/Index.vue:120` y `:137`**
- Escenario: con una sola cuenta se muestra «Instagram · 1 cuentas» y «1 cuentas sin fecha…».
- Es un error gramatical visible para el operador. Corrección: pluralización condicional.

Lo demás es correcto:
- La autorización ocurre antes de ejecutar HealthCheck (test 39-45 con `shouldNotReceive`).
- Los fallos de cuota, tokens y sonda solo registran la clase de la excepción.
- El estado `not_recorded` frente a `read_failed` se distingue en la UI.

## C. Seguridad introducida

No hay vulnerabilidad confirmada.
- La proyección SQL no toca `access_token` ni `refresh_token`.
- El informe no contiene identidades: solo red, recuentos y fechas mínimas.
- `auth.user` ya se compartía antes (preexistente).
- La lista de permitidos es solo de servidor: valida entradas, normaliza mayúsculas y espacios, y no hay campo editable.
- El entorno E2E fija `SYSTEM_ADMIN_EMAILS` explícitamente y no hereda el del host.

**C-1 · Hecho faltante (riesgo condicional) · `app/Support/SystemAccess.php:12`**
- La garantía depende de que `email_verified_at` solo se fije tras probar la posesión del buzón.
- Escenario: si `SocialLoginController` crea o vincula usuarios marcando `email_verified_at = now()` con el email de un proveedor que no verifica correos, alguien podría registrarse con la dirección de un operador sin controlar su buzón.
- Escenario análogo: cualquier otra vía de cambio de email (API, MCP) que no limpie la verificación.
- Solo se ha mostrado `ProfileController`. Antes de integrar hay que confirmar:
  - el manejo de `email_verified` en el login social;
  - que no existen otras rutas que muten `email`.
- No lo califico como defecto porque el contrato aprobado acepta «correo verificado» como criterio.

## D. Rendimiento

- La prop compartida `canViewSystemStatus` es un bucle sobre una configuración pequeña, sin consultas. Coste despreciable en cada respuesta Inertia.
- Cada GET de `/system` ejecuta lo siguiente:
  - la batería HealthCheck: varios `COUNT`, una escritura y lectura en el disco de media (hasta 5 s de timeout en S3) y, con la caché nativa vacía, hasta 3 procesos de 5 s;
  - una consulta agregada sobre `social_accounts`;
  - unas pocas lecturas de caché.
- Es aceptable para una página solo de operadores con actualización manual, y está documentado.
- No hay N+1 ni carga de modelos.

## E. Calidad del código

**E-1 · Política opcional, no defecto · `resources/js/Pages/System/Index.vue:1-10`**
- La página es `<script setup>` en JS con JSDoc y `checkJs`, no `lang="ts"`. Sigue la convención ya aceptada en `Activity/Index.vue`, con el DTO en `.d.ts`.
- Solo sería defecto si el contrato exigiera literalmente `lang="ts"`. Hecho faltante: la redacción exacta de «TS estricto» en el contrato.

**E-2 · Minor · `tests/e2e/isolated/runtime.ts` (hunks @@ 8-199)**
- Más del 95 % del diff de este fichero es reformateo de Prettier, sin cambio de comportamiento. Solo hay una línea funcional: `SYSTEM_ADMIN_EMAILS` en `buildEnvironment`.
- Mezclar formateo masivo con un cambio funcional dificulta la revisión y la trazabilidad (blame). Lo recomendable es separarlo en un commit propio o revertir el reformateo.

**E-3 · Minor (optativo) · `config/health.php` y `.env.example:95`**
- La autorización de operadores vive en el espacio de configuración `health`.
- Además, la variable se insertó entre `HEALTH_ALERT_EMAIL` y `HEALTH_ALERT_THROTTLE_MINUTES`, lo que parte ese grupo.
- La lista se recorta en la configuración y otra vez en `SystemAccess`. La duplicación es inocua.

**E-4 · Minor · `Index.vue:60` y `:62`**
- Hay ternarios anidados duplicados para la clase y el texto del estado de salud.
- Un mapa `{ ok, degraded, down } → { label, class }` sería más mantenible.

### Pruebas (calidad)

**T-1 · Minor · `tests/Feature/System/SystemStatusTest.php:77`**
- Las credenciales se guardan cifradas, así que comprobar que la respuesta no contiene el texto en claro demuestra poco sobre «no seleccionar columnas cifradas». Una regresión con `select *` sobre el modelo y su serialización pasaría igualmente si el campo estuviera oculto.
- Mejora: capturar las consultas con `DB::listen` y comprobar que ninguna contiene `access_token` o `refresh_token`.

**T-2 · Minor · `SystemStatusTest.php:82-95`**
- No se prueban las TTL separadas.
- Escenario sin cubrir: un cambio de 86400 a 600 en `QueueProbeJob.php:35` no rompería ningún test.
- Mejora: avanzar el reloj (`travel`) 11 min y 25 h y comprobar los valores.

**T-3 · Minor · tests de feature**
- Faltan casos para:
  - la exclusión de cuentas `STATUS_DISCONNECTED`;
  - `native_cache_seconds` en el informe.

**T-4 · Minor · `tests/e2e/system-status.spec.ts:55`**
- `toHaveCount(7)` acopla el test al número de casos de `Network`. Añadir una red romperá el E2E sin que haya regresión.
- Mejora: derivar el número del enum o comprobar `>= 1` más la tarjeta de YouTube.

**T-5 · Hecho faltante · `system-status.spec.ts:37`**
- La aserción «Cron · Fallo» depende de que ningún scheduler regenere el latido en el slot E2E. Si existe, el test sería intermitente.

## Hechos faltantes concretos (no buscados)

1. Código de `app:queue-probe`: qué colas cubre y si usa `onQueue` (B-2).
2. Valor de `config('app.timezone')` (B-1).
3. Si `SocialAccount` usa `SoftDeletes` (B-3).
4. Manejo de `email_verified_at` en el login social y otras vías de cambio de email (C-1).
5. Resultados de type-check, build y browser 15 casos sobre el rebase (A).

## Decisiones del padre no reabiertas

No reabro sin evidencia nueva:
- la lista de servidor sin `is_admin`;
- el estado global;
- YouTube como contador local;
- la ejecución de la sonda de almacenamiento en el GET, heredada de HealthCheck y documentada.

## Resultado

| Área | Valoración |
|---|---|
| A. Aceptación | Aprobable estáticamente; bloqueada para integrar por la verificación del rebase pendiente |
| B. Corrección | Sin defectos Important; 6 Minor, 3 de ellos condicionados a hechos faltantes |
| C. Seguridad | Sin hallazgos introducidos; confirmar C-1 antes de cerrar |
| D. Rendimiento | Correcto |
| E. Calidad | Buena separación de responsabilidades; Minor E-2 (ruido de formato) y huecos de test T-1 y T-2 |