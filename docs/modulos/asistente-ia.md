# Asistente IA (opcional)

Dos funciones del compositor: «Sugerir texto» y «Escribe cuándo» (programar en lenguaje natural, C-04c). Usan la Messages API de Anthropic por `Http` (sin SDK), con la Messages API de Anthropic por `Http` (sin SDK). **Desactivado por defecto**: solo existe con `AI_ASSISTANT_ENABLED=true` **y** `ANTHROPIC_API_KEY`.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/AiAssistant.php` |
| Controlador / UI | `AiAssistantController` (`status`, `suggest`, `schedule`), `resources/js/Components/AiSuggest.vue` y `resources/js/Components/Composer/ScheduleSection.vue` |
| Rutas | `GET ai/status`, `POST ai/suggest` y `POST ai/schedule` (las dos últimas con `throttle:10,1`; `routes/parity.php`) |

- **Privacidad (qué se envía a Anthropic)**: en «Sugerir texto», solo el brief que escribe el usuario (sin correos, teléfonos ni enlaces), el nombre de la marca y la red; nunca cuentas, publicaciones ni media. La política de privacidad lo menciona cuando está activo.
- **Topes**: `AI_ASSISTANT_DAILY_LIMIT` por usuario y día y `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` global (protege el gasto). La reserva se devuelve si la llamada falla, sobre la clave del día en que se hizo, y el contador nunca baja de cero (también si cambia el día durante la llamada).

## Programar en lenguaje natural («Escribe cuándo», C-04c)

`AiAssistant::proposeSchedule(User, Brand, frase)` traduce una frase como «el viernes a las 18 en Instagram y Bluesky» a **una sola fecha** y unas redes. **No programa nada**: devuelve una propuesta (`scheduled_at` local `Y-m-d\TH:i`, `timezone`, `utc`, `label`, `networks`, `ignored_networks`) que el compositor muestra y que solo se aplica cuando el usuario pulsa **Aplicar** (rellena la fecha y marca las cuentas activas de esas redes); **Descartar** la tira. Una sola fecha porque `scheduled_at` es por publicación, no por destino (varias fechas quedan fuera). El campo no aparece si el asistente está desactivado.

- **Qué se envía a Anthropic**: un JSON con `ahora` (fecha y hora actuales en la zona de la marca), `zona_horaria`, `redes` (las de `Network::offered()`) y `frase`, esta última **redactada** (sin correos, teléfonos ni enlaces; una fecha como «15-11-2026» se conserva). No viajan el nombre de la marca, las cuentas, las publicaciones, el texto del post ni la media. El prompt de sistema declara la frase un *dato* y pide solo un JSON con `datetime` y `networks`.
- **Inyección de prompt**: la frase va como valor escapado de un JSON (no se concatena al prompt) y la respuesta se valida con un **esquema estricto**: exactamente las claves `datetime` (`YYYY-MM-DDTHH:MM[:SS]`, hora local sin zona) y `networks` (nombres de red conocidos); la fecha debe existir en la zona de la marca (una hora que no existe por el cambio de hora se rechaza), ser **futura** y quedar como mucho a un año (`AI_ASSISTANT_SCHEDULE_MAX_DAYS`, 365). Cualquier otra cosa (texto alrededor, claves de más, offsets, varias fechas) se rechaza con un mensaje legible. Solo se aplican las redes que la instalación ofrece y que la marca tiene **conectadas** (cuenta activa); el resto sale en `ignored_networks` y se avisa.
- **Coste y límite**: cada propuesta es una llamada (≈ 200 tokens de salida como máximo) y **comparte el límite diario** con «Sugerir texto» (`AI_ASSISTANT_DAILY_LIMIT` por usuario y `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` global). La reserva se devuelve si falla la conexión o el servicio; una respuesta que no se entiende sí gasta cupo (la llamada se hizo). `POST ai/schedule` está limitado a 10 por minuto.
- **Seguridad de acceso**: la marca se resuelve con el `UserScope` (una marca ajena responde 404 y no se llama a la IA); con el asistente desactivado responde 404.
- **Sin MCP**: no hay herramienta MCP para esto, el cliente MCP ya es un LLM que entiende fechas.
- **Pruebas**: Pest con la IA simulada (`Http::fake`: 11 frases y 3 zonas en `ProposeScheduleTableTest`) y Playwright con `page.route` (nunca se llama a Anthropic); probar frases reales con la clave propia es la prueba manual M-05.
