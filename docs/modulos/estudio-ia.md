# IA del estudio: proveedores, cuotas y modelos

La pestaña **IA** del estudio genera imágenes desde una descripción, rellena una zona, amplía el lienzo y crea variaciones. Los resultados quedan como recursos privados de la marca. Todo está probado con proveedores simulados; los smokes con claves reales son CS-M-04 en [pendientes manuales](../referencia/pendientes-manuales.md).

## Proveedores

Cada proveedor solo existe si su clave está definida (todas vacías por defecto). Sin ninguna clave, el panel muestra «Imágenes con IA: no configurada» y no pinta botones.

| Proveedor | Clave (la específica manda) | Modelo por defecto | Variable del modelo |
|---|---|---|---|
| OpenAI Images | `STUDIO_AI_OPENAI_KEY`, `OPENAI_API_KEY` | `gpt-image-2.5-sunburst` | `STUDIO_AI_OPENAI_MODEL` (calidad `STUDIO_AI_OPENAI_QUALITY`, `medium`) |
| Google (Gemini imagen) | `STUDIO_AI_GOOGLE_KEY`, `GEMINI_API_KEY` | `gemini-nano-banana-2.1` | `STUDIO_AI_GOOGLE_MODEL` |

Google no admite máscara: la edición con zona solo funciona con OpenAI. La zona de edición es un rectángulo (al menos un 2 % de ancho y alto).

## Operaciones

`generate` (descripción, proporción, estilo, 1 a 3 variantes), `edit` (imagen propia, descripción y zona), `outpaint` (ampliar el lienzo a otra proporción) y `variation`. Proporciones: `1:1`, `3:2`, `2:3`, `16:9` y `9:16`. La descripción admite hasta 1000 caracteres. La imagen de origen se reduce a 1536 px de lado mayor antes de enviarla.

## Cuotas y límites

| Límite | Valor | Dónde |
|---|---|---|
| Imágenes al día por usuario y proveedor | 20 | `STUDIO_AI_OPENAI_DAILY_LIMIT`, `STUDIO_AI_GOOGLE_DAILY_LIMIT` |
| Ajuste por usuario | JSON `{"12":{"openai":5}}` | `STUDIO_AI_USER_LIMITS` |
| Trabajos activos por usuario | 5 | `studio.ai.max_active_jobs` |
| Entrada y salida | 20 MiB / 30 MiB; salida de hasta 36 megapíxeles | `config/studio.php` |
| Peticiones | 6/min (imagen) y 12/min (texto) por usuario | limitadores `studio-ai` y `studio-ai-text` |

Un 429 transitorio o un 5xx se reintenta (hasta 3 intentos con esperas de 2 y 6 s) solo si cabe en el presupuesto del trabajo. Un timeout de lectura tras enviar el POST no se reintenta, porque el proveedor pudo cobrarlo, y no devuelve cuota. Si el proveedor falla, la cuota se devuelve.

## Cola y plazos

Los trabajos (`RunStudioAiJob`) van en la cola **`media`**, por lo que necesitan su worker. Plazos encadenados: `$timeout` del job 110 s < `--timeout` del worker 120 s < `retry_after` 180 s. Dentro del job, el presupuesto de llamadas al proveedor es `STUDIO_AI_BUDGET` (100 s) y una llamada dura como máximo `STUDIO_AI_TIMEOUT` (90 s). Estados: en cola, en curso, listo y error.

## Texto con Claude

Aparte de los proveedores de imagen, el asistente de texto (`AI_ASSISTANT_ENABLED=true` y `ANTHROPIC_API_KEY`, ver [asistente IA](asistente-ia.md)) propone titulares y copies, rellena una plantilla desde una lista de hasta 12 elementos (siempre con confirmación) y sugiere texto alternativo.

## API y MCP

La ability **`studio:ai`** es independiente (`designs:write` no basta): `GET /api/v1/brands/{brand}/ai`, `POST/GET .../ai-image-jobs` y `POST .../ai-text`; en MCP, `generate_studio_image` (`status`, `start`, `get`). Contrato en el [OpenAPI](../api/openapi.yaml).

## Seguridad

Las claves solo viajan en la cabecera de autenticación del proveedor y no se registran ni aparecen en la API o en errores. La salida debe ser PNG, JPEG o WebP por sus bytes y dimensiones; si el proveedor devuelve una URL se descarga con la guardia SSRF (`UrlGuard`). Los trabajos y recursos son privados por usuario y marca.
