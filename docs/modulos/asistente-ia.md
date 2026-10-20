# Asistente IA (opcional)

Botón «Sugerir texto» del compositor, con la Messages API de Anthropic por `Http` (sin SDK). **Desactivado por defecto**: solo existe con `AI_ASSISTANT_ENABLED=true` **y** `ANTHROPIC_API_KEY`.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/AiAssistant.php` |
| Controlador / UI | `AiAssistantController`, `resources/js/Components/AiSuggest.vue` |

- **Privacidad**: se envía solo el brief que escribe el usuario (sin correos, teléfonos ni enlaces), el nombre de la marca y la red; nunca cuentas, publicaciones ni media. La política de privacidad lo menciona cuando está activo.
- **Topes**: `AI_ASSISTANT_DAILY_LIMIT` por usuario y día y `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` global (protege el gasto). La reserva se devuelve si la llamada falla, sobre la clave del día en que se hizo, y el contador nunca baja de cero (también si cambia el día durante la llamada).
