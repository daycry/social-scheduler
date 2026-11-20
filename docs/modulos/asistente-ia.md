# Asistente IA (opcional)

Cuatro funciones del compositor: «Sugerir texto», «Escribe cuándo» (programar en lenguaje natural, C-04c), «Adaptar a cada red» y «Generar» el texto alternativo de una imagen (T-82). Usan la Messages API de Anthropic por `Http` (sin SDK). **Desactivado por defecto**: solo existe con `AI_ASSISTANT_ENABLED=true` **y** `ANTHROPIC_API_KEY`.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/AiAssistant.php` |
| Controlador / UI | `AiAssistantController` (`status`, `suggest`, `schedule`, `adapt`, `alt`), `resources/js/Components/AiSuggest.vue`, `resources/js/Components/Composer/ScheduleSection.vue`, `resources/js/Components/Composer/AiAdapt.vue` y `resources/js/Components/Composer/TargetMediaPanel.vue` |
| Rutas | `GET ai/status`, `POST ai/suggest`, `POST ai/schedule`, `POST ai/adapt` y `POST ai/alt` (con los límites nombrados `ai.*` de `config/rate_limits.php`; `routes/parity.php`) |

- **Privacidad (qué se envía a Anthropic)**: en «Sugerir texto», solo el brief que escribe el usuario (sin correos, teléfonos ni enlaces), el nombre de la marca y la red; nunca cuentas, publicaciones ni media. La política de privacidad lo menciona cuando está activo.
- **Topes**: `AI_ASSISTANT_DAILY_LIMIT` por usuario y día y `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` global (protege el gasto). La reserva se devuelve solo si la llamada no llegó a hacerse o el proveedor la rechazó con HTTP (nunca tras una respuesta ni tras un timeout; ver la tabla de abajo), sobre la clave del día en que se hizo, y el contador nunca baja de cero (también si cambia el día durante la llamada).

## Programar en lenguaje natural («Escribe cuándo», C-04c)

`AiAssistant::proposeSchedule(User, Brand, frase)` traduce una frase como «el viernes a las 18 en Instagram y Bluesky» a **una sola fecha** y unas redes. **No programa nada**: devuelve una propuesta (`scheduled_at` local `Y-m-d\TH:i`, `timezone`, `utc`, `label`, `networks`, `ignored_networks`) que el compositor muestra y que solo se aplica cuando el usuario pulsa **Aplicar** (rellena la fecha y marca las cuentas activas de esas redes); **Descartar** la tira. Una sola fecha porque `scheduled_at` es por publicación, no por destino (varias fechas quedan fuera). El campo no aparece si el asistente está desactivado.

- **Qué se envía a Anthropic**: un JSON con `ahora` (fecha y hora actuales en la zona de la marca), `zona_horaria`, `redes` (las de `Network::offered()`) y `frase`, esta última **redactada** (sin correos, teléfonos ni enlaces; una fecha como «15-11-2026» se conserva). No viajan el nombre de la marca, las cuentas, las publicaciones, el texto del post ni la media. El prompt de sistema declara la frase un *dato* y pide solo un JSON con `datetime` y `networks`.
- **Inyección de prompt**: la frase va como valor escapado de un JSON (no se concatena al prompt) y la respuesta se valida con un **esquema estricto**: exactamente las claves `datetime` (`YYYY-MM-DDTHH:MM[:SS]`, hora local sin zona) y `networks` (nombres de red conocidos); la fecha debe existir en la zona de la marca (una hora que no existe por el cambio de hora se rechaza), ser **futura** y quedar como mucho a un año (`AI_ASSISTANT_SCHEDULE_MAX_DAYS`, 365). Cualquier otra cosa (texto alrededor, claves de más, offsets, varias fechas) se rechaza con un mensaje legible. Solo se aplican las redes que la instalación ofrece y que la marca tiene **conectadas** (cuenta activa); el resto sale en `ignored_networks` y se avisa.
- **Coste y límite**: cada propuesta es una llamada (≈ 200 tokens de salida como máximo) y **comparte el límite diario** con «Sugerir texto» (`AI_ASSISTANT_DAILY_LIMIT` por usuario y `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` global). Aplica la «Política de cuota» de más abajo (una respuesta que no se entiende gasta el uso). `POST ai/schedule` está limitado a 10 por minuto.
- **Seguridad de acceso**: la marca se resuelve con el `UserScope` (una marca ajena responde 404 y no se llama a la IA); con el asistente sin configurar responde **409** con la explicación (convención única de `suggest`, `schedule`, `adapt` y `alt`; `GET ai/status` informa además de `vision`, si el modelo admite imágenes). Los mensajes al usuario, también los 404, van en castellano.
- **Sin MCP**: no hay herramienta MCP para esto, el cliente MCP ya es un LLM que entiende fechas.
- **Pruebas**: Pest con la IA simulada (`Http::fake`: 11 frases y 3 zonas en `ProposeScheduleTableTest`) y Playwright con `page.route` (nunca se llama a Anthropic); probar frases reales con la clave propia es la prueba manual M-05.

## Política de cuota (única)

Vale para `suggest`, «Escribe cuándo», «Adaptar a cada red», el texto alternativo y las automatizaciones del estudio (`complete`, con y sin `$usable`):

| Situación | Cupo del usuario | Cupo global |
|---|---|---|
| El proveedor respondió (útil o no: JSON inválido, cortada, fecha no entendida, ninguna versión utilizable) | se gasta | se gasta |
| La llamada no llegó a hacerse o el proveedor la rechazó con HTTP (saturado, 4xx/5xx; sin coste) | se devuelve | se devuelve |
| Timeout o error de conexión (ambiguo, probablemente cobrado) | se gasta | se gasta |

Por qué sin reembolso cuando el proveedor respondió: una respuesta inservible la provoca a menudo la propia entrada («ayer a las 9», un texto que fuerza versiones inválidas), y devolver el cupo dejaría a un solo usuario agotar el tope global de todos (CWE-770). Es el mismo criterio del estudio de imágenes, que no reintenta ni devuelve cuota tras un timeout ambiguo. Los textos de la interfaz no prometen devolver el uso.

## Adaptar el texto a cada red (T-82)

`AiAssistant::adapt(User, Brand, texto, destinos)` reescribe el texto común para cada destino seleccionado en **una sola llamada** (una unidad de cupo, no una por red). Cada destino es `{account_id, content_type}`; la respuesta es una lista `results` con `{account_id, text, error}` por red. **No aplica nada**: el compositor muestra una versión por red (con su contador) y solo al pulsar **Aplicar** rellena el texto propio de cada destino (el mismo mecanismo de «Editar por red»); **Descartar** lo tira.

- **Validación por red**: el servidor comprueba cada versión con la regla de texto de su tipo en `config/media_specs.php` (`MediaValidator::textErrors`: `text.max` con su modo de conteo `chars|graphemes|threads`, `max_bytes` y `max_hashtags`). La versión que no cabe **se rechaza con su motivo y no se trunca**; el resto se ofrece igualmente. Si ninguna se puede usar, el uso se gasta igual (el proveedor respondió; ver la política de cuota).
- **Qué se envía a Anthropic**: un JSON con el nombre de la marca, el texto común y, por destino, `id`, red, tipo de contenido y límites. El texto va **sin correos, teléfonos ni enlaces** (se cambian por marcadores numerados como `[enlace1]` que se restauran en la versión devuelta) y el sistema lo declara un *dato*, nunca instrucciones. Nunca viajan cuentas, otras publicaciones ni media.
- **Esquema estricto**: la respuesta debe ser un objeto con exactamente la clave `versions` y una cadena por cada destino pedido. Si no es JSON válido o falta una red, responde 422 con un mensaje y el uso **se gasta** (cupo del usuario y global), igual que si ninguna versión es utilizable: el proveedor respondió. Ver «Política de cuota» más abajo.
- **Enlaces, correos y teléfonos**: se enmascaran en una sola pasada y con prioridad para el enlace (una URL con un correo dentro no se trocea), con marcadores numerados que se restauran. Una versión que **omite** un marcador del original o **inventa** uno se rechaza con un motivo claro: no se pierde un dato en silencio. El modelo recibe el límite efectivo de cada red (el máximo menos lo que crece el texto al restaurar los marcadores).
- **Salida y tiempo**: el presupuesto de tokens se dimensiona con la longitud del texto y las redes, con tope `AI_ASSISTANT_ADAPT_MAX_TOKENS` (8000). El tiempo de espera parte de `AI_ASSISTANT_TIMEOUT` y suma 1 s por cada 50 tokens, con tope `AI_ASSISTANT_ADAPT_MAX_TIMEOUT` (50 s por defecto; la config lo recorta a 55 s porque Nginx corta a los 60 s).
- **Marcadores**: si el texto del usuario ya contiene algo como «[email3]», los marcadores del servidor usan otros delimitadores (`<<…>>`, `{{…}}`, `⟪…⟫`) para no confundirse con él.
- **Tipo de contenido**: si el tipo de un destino cambia después de adaptar, su propuesta queda anulada hasta volver a adaptar.
- **Acceso**: la marca y las cuentas se resuelven con `UserScope`/`ownedBy()`; una marca o cuenta ajena responde 404 sin llamar a la IA. Con el asistente sin configurar responde **409** con la explicación (la interfaz muestra el botón inactivo con `AiNotConfigured`).

## Texto alternativo desde el compositor (T-82)

En el panel de media de cada red (**Personalizar media para esta red**), junto al campo de texto alternativo, **Generar** pide a `POST ai/alt` (`media_id`) una frase con `StudioAiText::describe` (la misma generación que usa el estudio con `altText`) y la pone en el campo, que **sigue siendo editable** antes de guardar (máximo `TargetMedia::ALT_MAX`); si ya había un alt escrito se pide confirmación antes de reemplazarlo. La media se autoriza por propietario (biblioteca, URL, Drive o Recursos de cualquier marca del usuario; la de otro usuario responde 404). Solo imágenes PNG, JPEG o WebP listas; sin visión (`social.ai.vision`) el botón queda inactivo con la explicación; envía la imagen reducida a Anthropic únicamente cuando el usuario pulsa el botón y consume una unidad de cupo. Sin IA configurada el botón queda inactivo y explica por qué.

- **Pruebas**: `tests/Feature/Ai/AdaptContentTest.php` (Pest, `Http::fake`) y `tests/e2e/ai-adapt.spec.ts` (E2E-21, con la IA falsa del servidor desechable); probar con la clave real es la prueba manual M-05.
