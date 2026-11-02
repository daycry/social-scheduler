---
estado: en-progreso
creado: 2026-10-31
actualizado: 2026-10-31
spec: spec.md
---

# Entrega autorizada: audio por red

El usuario concreta que añadir audio como Metricool es suficiente y autoriza mejoras relacionadas útiles. Esta entrega implementa selección de audio para Instagram Reels en web y MCP, con búsqueda, preescucha, cambio, eliminación y volumen independiente de pista/vídeo. El inventario amplio permanece como backlog; no condiciona la aceptación de esta entrega ni se declara implementado.

Ampliación posterior: revisar las seis redes existentes, incorporar la música recomendada para fotos TikTok que expone su Content Posting API y publicar capacidades claras en todas las conexiones. No se traslada el selector de Instagram a otras redes. Facebook se comprobó también en la UI real de Metricool: el Reel de esta cuenta muestra título, sin selector de audio. Su API de recomendaciones no acredita asociación al publicar. La música concreta de TikTok Business necesita otra integración y queda identificada como diferencia, sin simularla.

## Decisiones de implementación

- Mantener `PostTarget.options`; no hay nueva migración ni cambio del formato multimedia común. Campo tipado `instagram_audio` con `audio_id`, `audio_volume` y `video_volume`. Solo el identificador y volúmenes se guardan; metadatos y URL temporales se consultan al catálogo.
- Servicio único de catálogo para web y MCP con propiedad de cuenta explícita, cuentas activas de Instagram, User token cifrado ya disponible en `refresh_token`, y permisos OAuth existentes. El token de Página no sustituye silenciosamente al de usuario.
- Adaptador de Meta basado en su documentación oficial obtenida con HTTP 200 el 2026-10-31: `GET /ig_audio`, `GET /{audio_id}`, `POST /{ig_user_id}/media` con `audio_configuration` JSON. Tokens solo en Authorization.
- El publicador revalida forma/capacidad y comprueba la pista antes de crear el contenedor. Si Meta rechaza la pista, no se publica sin ella. Conservar checkpoint y reconciliación actuales; al sondear un contenedor existente no se recrea ni se vuelve a elegir audio.
- Preescucha de pista separada; no se promete escuchar la mezcla final. No se implementa desplazamiento ni recorte del catálogo. Volúmenes 1–100, conforme al contrato oficial, sin inventar que 0 está admitido.
- Mejora relacionada: balance de volumen para evitar mezclar dos pistas a volumen completo por accidente. Los conectores actuales incorporan las opciones de audio verificadas; publicidad, conectores nuevos, asociación nativa de catálogo de Facebook y recurrencias permanecen fuera de esta entrega.

## Verificación

Tests de catálogo/propiedad/credenciales/URL segura, validación web y MCP, persistencia y publicación con checkpoint; regresión de publicadores y compositor. Compilación frontend y revisión adversarial independiente. E2E ejecutado en entorno desechable: 15/15; suite PHP 2.491 tests/14.671 aserciones, Node 12/12, build y tipado correctos. Revisión A+B+D, intento 3, sin gaps pendientes. Los smokes con la API real siguen a cargo del usuario y permanecen abiertos; [informe y evidencias](testing/report.md).

El [ledger](tasks.md) registra la evidencia RED/GREEN y las limitaciones. No se introduce una estimación económica como si hubiera sido acordada.

## Corrección del buscador (2026-11-01, T-10)

La revisión solicitada por el usuario demuestra pérdida de páginas: Meta devuelve `paging.cursors.after` sin `paging.next`. Continuación explícita contra `ig_audio`, independiente por música/audio original; un tipo agotado no vuelve a consultarse desde el principio. La respuesta añade `next_cursor` y `has_more` sin romper `results`. Cursor cifrado ligado a cuenta, búsqueda y filtro, con 30 minutos de validez; no se siguen URLs externas. Web añade Cargar más y búsquedas al escribir con debounce de 350 ms, cancelación e invalidación inmediata de la búsqueda anterior. Error de página conserva resultados y cursor para reintentar. Pruebas focales, regresión y revisión del código de T-10 preceden su cierre.


## Estética de ajustes (T-11)

Reutilizar un panel de presentación con `details/summary` nativos para conservar contenido, teclado y estado al plegar. Ajustes globales agrupa programación/notas; las cuentas mantienen su región accesible y muestran red, cuenta y tipo. CSS limitado a los paneles, interruptores y el botón existente de audio. Abrir los `details` ante un enlace de error evita ocultar el campo de destino. La lógica de los publicadores, la búsqueda de música y el estado del compositor no cambian. QA con datos ficticios y capturas; sin publicaciones reales.

## Opciones de publicación y errores (T-12)

Validar y normalizar `collaborators` e `is_ai_generated` en una clase compartida del conector, usada por PostService y por el publicador antes de cualquier HTTP. Reutilizar PostRequest para web/MCP. Exponer capacidades de Instagram por cuenta. En carruseles, enviar estos campos solo al padre; rechazar Stories y otros formatos incompatibles. El título `facebook_title` se envía al publicar vídeos y al finalizar Reels. Los cambios no alteran checkpoints ni crean publicaciones durante las pruebas.

La UI acepta usuarios explícitos con chips para quitar, requiere multimedia y explica límite e invitación. IA no se recuerda entre publicaciones. Poda las opciones al cambiar de formato. Errores con details/summary, contador, iconos y acceso al control; no se pierde la validación previa ni las acciones de borrador. Pruebas RED antes del código, regresión servidor/MCP/publicadores, E2E a 1440/375/360, revisión A+B+D y qa-gate. Inventario completo de los paneles observados en la guía: acortador, modo manual y automatización de SmartLinks quedan identificados como pendientes, sin controles ficticios.
