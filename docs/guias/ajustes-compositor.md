# Ajustes globales y ajustes de cada red

El compositor reúne los controles en paneles plegables inspirados en Metricool. Ajustes globales contiene fecha/hora, programación por texto cuando está habilitada y notas internas. Los paneles por red identifican cuenta y formato. Pulsa la cabecera o usa Tab y Enter/Espacio. Plegar conserva los valores; Guardar borrador los mantiene al reabrir.

## Instagram

Para imágenes, Reels y carruseles puedes añadir colaboradores por nombre de usuario, con o sin @. Adjunta primero multimedia, pulsa **Añadir colaborador**, escribe el usuario y pulsa **Añadir usuario**. Puedes quitarlo mediante su chip. Se normalizan a minúsculas, se rechazan duplicados y enlaces, y se admiten hasta **3** según el [contrato de Meta](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media/). La invitación se envía al publicar y requiere aceptación; Instagram puede rechazar cuentas no elegibles. La ayuda de Metricool anuncia cinco, pero nuestro endpoint documenta tres.

**Contenido generado con IA** comunica esa indicación a Instagram. Se decide por publicación y no se recuerda para la siguiente. En carruseles, colaboradores e IA se envían solo al contenedor padre. No se ofrecen para Stories. Cambiar de formato elimina opciones incompatibles. Los Reels conservan Compartir también en el feed y el [selector de audio](audio-instagram.md).

## Facebook y errores

Los vídeos y Reels tienen **Título de Facebook (opcional)**, de hasta 255 caracteres. Es independiente del texto general; los Reels lo envían al finalizar la carga. Cambiar a otro formato elimina ese título.

La zona **Hay N errores que corregir** se puede plegar. Muestra red, cuenta y mensaje. **Ir** abre el panel afectado y enfoca su control. Los errores de contenido bloquean Programar y Publicar ahora. Una fecha pasada bloquea Programar; Publicar ahora usa la fecha del servidor y sigue disponible si no hay otros errores. Guardar borrador conserva sus reglas previas.

## Inventario observado en Metricool

Observación de la pestaña de Aipocalipsis el 2026-11-01, con Facebook POST e Instagram REEL. No se guardó ni publicó en Metricool. El inventario distingue lo observado de lo que permite cada API.

| Panel/control observado | Estado en esta aplicación |
|---|---|
| Global: Auto publish + ayuda | Programación automática existente; selección de modo manual/notificación pendiente |
| Global: Use URL shortener + ayuda | Pendiente; no existe aún el servicio de acortado |
| Global: SmartLinks / creación y enlace automático | Módulo SmartLinks existente; inserción automática desde el compositor pendiente |
| Instagram: Collaborators / Add collaborator | Implementado; requiere multimedia, máximo 3 por este contrato |
| Instagram: Audio of reel / Add audio | Implementado para Reels con cuenta elegible |
| Instagram: Show Reel on feed | Implementado |
| Instagram: AI-generated content | Implementado para imagen/Reel/carrusel |
| Facebook Reel: Title (observación previa) | Implementado para vídeo y Reel |
| Errores: cabecera plegable, contador, icono/red/cuenta y mensajes | Implementado, con enlaces de corrección |
| Errores: fecha pasada / contenido vacío / multimedia requerida | Se mantienen las validaciones propias; no se copian errores si el proveedor/formato no los exige |

La ayuda de Metricool enumera además ubicación, etiquetas de personas/productos, portada, nombre de audio original, promoción y Reels de prueba. Continúan en el backlog amplio y requieren validar contratos y elegibilidad. Esta entrega no declara paridad completa.

## MCP

`list_accounts` anuncia `instagram_presets` con formatos, `max_collaborators` y `ai_disclosure`. Crear/editar/leer un post conserva las mismas opciones y validación que la web:

```json
{"targets":[{"account_id":12,"content_type":"instagram_reel","options":{"collaborators":["artist.one"],"is_ai_generated":true}}]}
```

Para quitar colaboradores usa `collaborators: []`; para desactivar IA, `is_ai_generated: false`. Un destino Facebook Reel/vídeo admite `options.facebook_title`. No uses ese título en otra red ni envíes colaboradores a Stories.

[Spec y criterios](../roadmap/2026-10-31-metricool-composer-parity/spec.md) · [Pruebas y revisión](../roadmap/2026-10-31-metricool-composer-parity/testing/presets-ui/report.md).
