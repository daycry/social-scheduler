# Revisión adversarial T79 (intento 1/3)

## Veredicto

**No aceptar todavía.** Hay dos motivos.

1. **QA pendiente.** El QA sobre el diff final está incompleto. Solo existe PHP full r36 (4385 passed). Build, tipos y browser siguen pendientes de repetir sobre el padre, como declara el propio candidato.
2. **Defectos estáticos.** Hay dos defectos *Important* contra el contrato (F1 y F3) y un riesgo sistemático de huso horario (F2) que conviene corregir o acotar antes de cerrar.

El diseño general es sólido:
- transacción con lock, índice único y aviso in-app atómico;
- correo separado con `afterCommit` y nueva comprobación en `shouldSend`;
- datos ausentes tratados como desconocidos;
- engagement ponderado;
- propiedad explícita en el job;
- DTO sin credenciales.

## A. Aceptación

| Requisito | Estado |
|---|---|
| CRUD por marca/cuenta propia, aislamiento y 404 ajeno | Cumple |
| Hito, caída estricta y caducidad conocida con deduplicación | Cumple, con las salvedades F1, F4 y F5 |
| Ausente ≠ 0, baseline 0 sin %, misma cohorte y una cuenta sin dato invalida el agregado | Cumple |
| Cron 13:15 tras el colector de 12:00, sin worker, métrica externa ni API pública nueva | Cumple |
| Correo opcional con triple condición y nueva comprobación al procesar | Cumple |
| UI en `tsconfig.frontend.json` (checkJs) y tipos DTO | Cumple estáticamente; **tipos/build pendientes** de repetir |
| Días completos en la TZ de la marca para todas las marcas | **En riesgo** (F2) |

## B. Corrección

### F1 — Important — `app/Services/GoalService.php:73-74`

**Defecto.** `Rule::exists(...)->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)` usa una firma que `DatabaseRule::where($column, $value = null)` no admite.

- El tercer argumento se descarta y el valor queda como `'!='`.
- `DatabasePresenceVerifier::addWhere` interpreta un valor con prefijo `!` como negación. La condición efectiva es `status != '='`, que siempre es verdadera.

**Escenario.** El propietario envía un POST manual (la UI la oculta) con su propia cuenta en estado `desconectada`. El objetivo se crea, aunque el mensaje y la documentación afirman lo contrario. El objetivo queda permanentemente en «Sin datos».

**Alcance.** No hay fuga entre tenants: los `where` de `user_id` y `brand_id` usan la firma correcta de dos argumentos.

**Evidencia.**
- Firma de `DatabaseRule::where` en Laravel 10–12. Falta confirmar que Laravel 13 no la cambió.
- Ningún test cubre una cuenta desconectada.

**Corrección.** Usar `->whereNot('status', SocialAccount::STATUS_DISCONNECTED)` y añadir un test.

**Efecto colateral al corregir.** Editar o pausar un objetivo cuya cuenta pasó a desconectada fallará la validación. El `<select>` (`Index.vue:215-229`) no contiene esa opción y reenvía el id antiguo. Hay que decidir una política: permitir conservar el id existente en `update`, o mostrar «cuenta desconectada» y permitir solo pausar o borrar.

### F2 — Important (condicionado) — `app/Services/Goals/GoalObservations.php:46` + `routes/console.php:88`

**Defecto.** El periodo es «ayer en la TZ de la marca», calculado en el único instante diario de 13:15 en la TZ del scheduler.

**Escenario.** Con el scheduler en UTC, una marca en `Australia/Sydney` (+11 en noviembre) o `Pacific/Auckland` (+13) ve a las 13:15 UTC las 00:15 o 02:15 del día siguiente. Su «ayer» es el día que acaba de cerrar.

- Si los snapshots se fechan en días UTC (como sugiere `docs/modulos/analitica.md` en la sección C-03b), ese snapshot no existe hasta las 12:00 UTC del día siguiente.
- Si se fechan en la TZ de la marca a las 12:00, ocurre lo mismo para offsets entre +10:45 y +12.

**Consecuencia.** Hito y caída quedan desconocidos **todos los días** para esas marcas. No hay segunda evaluación, así que no se recuperan. La documentación promete «hasta otra evaluación», pero esa evaluación mira otro periodo.

**Hechos que faltan.** Cómo asigna `CollectSnapshots` el campo `date` (UTC o TZ de la marca) y cuál es `app.timezone`.

**Corrección posible.** Elegir como periodo el último día cerrado *que el colector ya ha procesado*, o acotar por marca: `min(ayer_marca, ayer_colector)`. Añadir un test con TZ positiva alta (el test actual solo cubre Los Ángeles).

### F3 — Important — `app/Jobs/EvaluateGoals.php:37-41`

**Defecto.** Solo se captura `OwnershipException`. Cualquier otra excepción en un objetivo aborta el chunk y el job entero:
- una TZ inválida en `setTimezone`;
- un deadlock tras 3 intentos;
- un fallo del encolado `afterCommit` del correo;
- una violación del índice único en SQLite.

**Escenario.** Una marca con TZ corrupta en el objetivo 5 impide evaluar los objetivos 6..N de **todos** los usuarios. El job se reintenta 3 veces desde el principio y cae en el mismo punto. Es un «poison goal» permanente.

**Agravante.** Si el fallo es el encolado del correo, el evento ya está confirmado. El reintento lo deduplica y el correo no se reenvía. Esto es aceptable según el contrato (canal opcional, sin exactly-once), pero sigue bloqueando el lote.

**Corrección.** Usar `catch (Throwable $e)` por objetivo, registrando `goal_id` y la clase de excepción, y relanzar al final un resumen solo si se quiere marcar el job como fallido. Añadir un test de aislamiento.

### F4 — Important (riesgo de contrato, no defecto contra su texto) — `app/Services/Goals/GoalObservations.php:32-37`

El código hace lo que dice el contrato: elige el primer plazo conocido entre access, refresh y data access. Pero, según el docblock de `SocialAccount`, en Google `token_expires_at` es el access token de 1 h y en TikTok el de 24 h. Ambos se renuevan automáticamente (`RefreshTokens` cada 30 min).

**Escenario.** Un objetivo de caducidad sobre una cuenta de YouTube o TikTok con umbral ≥1:
- cada día encuentra un plazo nuevo a menos de 1 día;
- la clave `token:hash(account, expires_at)` cambia;
- se genera una alerta diaria falsa («La credencial caduca el …») indefinidamente.

T-23 (`connections:notify-expiry`) ya distingue los plazos relevantes por proveedor (Meta 90 días, LinkedIn sin renovación).

**Decisión necesaria del contrato:** excluir los access tokens renovables (con refresh vigente) o reutilizar la selección de T-23. Así se evitan falsos positivos y duplicación de lógica.

### F5 — Minor — `app/Services/Goals/GoalEvaluator.php:49`

`period_key = 'drop:'.period` no incluye métrica, cuenta ni umbral, a diferencia del hito (línea 39).

**Escenario.** Una caída alertada para el periodo P en seguidores; el usuario cambia a engagement u otra cuenta y se reevalúa P (reintento o ejecución manual). La nueva regla no puede avisar para P.

El impacto es bajo, porque la evaluación diaria pasa a P+1. Aun así, la deduplicación queda inconsistente con la promesa de «Modificar regla limpia».

Incluir `hash([metric, account, threshold])` en la clave alinea ambas semánticas.

### Notas de corrección sin defecto

- El fallback del colector cambia de `03:30` a `12:00` (`routes/console.php:46`). Es una edición fuera de alcance e inocua si `config/social.php` ya fija 12:00. Si el operador mueve `SOCIAL_ANALYTICS_DAILY_AT` después de las 13:15, no hay acoplamiento ni aviso. Es una política documentable.
- En engagement, la «caída %» es relativa sobre un porcentaje, no en puntos porcentuales. La UI no lo aclara (`Index.vue:44`). Conviene precisarlo.

## C. Seguridad introducida

No hay hallazgos bloqueantes.

- **Propiedad:** el binding con `UserScope` da 404 al ajeno, `ownedBy` es explícito en el job y el evaluador, `Ownership::assert` cubre goal y brand, y `$request->all()` queda filtrado por `validated()`. El test con `user_id` foráneo lo cubre.
- **Credenciales:** `first([...])` solo carga columnas de expiración; el DTO y el payload no incluyen tokens; la URL es relativa y sin firma.
- **Correo:** el nombre se valida con `not_regex /\p{C}/` (sin inyección de cabeceras) y las líneas Markdown se escapan.
- **F1** es una debilidad de validación sobre datos propios, no una escalada.
- **Minor/política:** `\p{C}` también rechaza `Cf` (ZWJ de emoji compuestos, por ejemplo «👨‍👩‍👧»). Es una decisión de producto.

## D. Rendimiento

- **Minor — `EvaluateGoals.php:32`.** Son unas 6 consultas y una transacción por objetivo dentro de un único job con el timeout del worker. A gran escala, un timeout reinicia el lote completo (relacionado con F3). Para una app personal es aceptable. Si crece, conviene despachar por usuario o marca.
- **Minor — `goal_alert_events`.** No tiene poda. Crece una fila por día por objetivo de caída, y diaria por objetivo de token si F4 no se corrige. Las notificaciones sí se podan (`Retention`), los eventos no. Es aceptable si se documenta.

## E. Calidad del código

- **Minor — `config/goals.php:7`.** Lee directamente `env('SOCIAL_ANALYTICS_QUEUE')` del otro módulo en lugar de derivar de `config('social.analytics.queue')`. Si ese módulo usa otro nombre de variable, las colas divergen en silencio. Hecho que falta: la clave real en `config/social.php`.
- **Minor — `app/Notifications/GoalAlert.php:22`.** El correo de objetivos usa `social.refresh_queue`, una clave semánticamente ajena. Conviene definir `goals.mail_queue` con default `poll`.
- **Minor — migraciones.** `2026_11_18_091000` y `091100` son dos migraciones nuevas e inéditas del mismo día para una sola tabla. Podrían fusionarse. Es un nit.
- **Minor — `Notifications/Index.vue:52`.** El ternario de `title` sigue creciendo. Conviene un mapa de funciones por tipo.
- **Minor (accesibilidad) — `Index.vue:271-273`.**
  - Los errores van en un bloque `role="alert"` sin `aria-invalid` ni `aria-describedby` por campo.
  - `:key="error"` colisiona si dos campos comparten mensaje.
- **Minor (accesibilidad) — `Index.vue:307-312`.**
  - Los botones «Editar» y «Eliminar» se repiten sin nombre accesible del objetivo.
  - El `article` no tiene `aria-labelledby`.
  - Solución: añadir `aria-label="Editar «{{ goal.name }}»"`.
- **Minor (UX) — `Index.vue:323`.** «Último valor … días hasta caducar» muestra días fraccionarios (4 decimales) y se queda obsoleto respecto al momento de la evaluación.

## Política opcional (no defecto)

- **Agregado de marca con métricas no disponibles.** Una marca con LinkedIn, TikTok, Bluesky o Threads (sin views/interactions, o sin lector) deja «Toda la marca» en engagement, y LinkedIn también en seguidores, **permanentemente** desconocido. Cumple «una cuenta sin dato invalida». Se podría avisar en la UI de qué cuenta bloquea el agregado.
- **Recreación de objetivos.** Borrar y recrear un objetivo idéntico vuelve a avisar el hito (nuevo `goal_id`).
- **Correo tras fallo de encolado.** Si el encolado posterior al commit falla, el correo se pierde sin reintento. Está reconocido y admitido por el contrato: canal opcional, sin outbox ni exactly-once.

## Hechos que faltan

1. Firma de `Illuminate\Validation\Rules\DatabaseRule::where` en la versión instalada (F1).
2. Semántica del campo `date` en `CollectSnapshots` y valor de `app.timezone` (F2).
3. Si `RefreshTokens` actualiza `token_expires_at` en Google y TikTok (F4).
4. La clave real de la cola de analítica en `config/social.php` (calidad, `config/goals.php`).
5. El middleware del grupo de rutas donde se añaden `goals.*` (se asume `auth` + `verified`, como `activity` y `system`).

## Tests a añadir

1. Crear y actualizar con una cuenta `desconectada` → 422.
2. Marca en `Pacific/Auckland` evaluada a las 13:15 UTC con un snapshot UTC de ayer.
3. Aislamiento del job: una marca con TZ inválida no impide evaluar el siguiente objetivo.
4. Token con access token de 1 h renovado entre dos días → sin alerta, o la política que se decida.
5. Caída en engagement.
6. Token ya vencido (valor negativo).
7. `shouldSend` con `mail_enabled = false` tras encolar (hoy solo se cubre el borrado).

## Resumen de severidades

| Severidad | Hallazgos |
|---|---|
| Critical | 0 |
| Important | 4: F1 y F3 defectos; F2 condicionado; F4 riesgo de contrato |
| Minor | ~10 |

El cierre requiere además repetir build, tipos y browser sobre el diff final.