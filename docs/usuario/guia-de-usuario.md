# Guía de usuario

Social Scheduler programa tus publicaciones en Instagram, Facebook, YouTube y TikTok, y te muestra cómo van tus cuentas. Está pensado para uso personal.

## Primeros pasos

1. Regístrate y verifica tu correo.
2. Crea una **marca** (por ejemplo, tu proyecto o negocio). Cada marca agrupa sus cuentas, publicaciones y estadísticas.
3. Entra en las **Conexiones** de la marca y pulsa **Conectar** en cada red. Acepta los permisos en la pantalla de la red.

Si tienes varias páginas o canales, la app te pide elegir cuál conectar.

Avisos al conectar:

- **Google/YouTube**: puede aparecer "Google no ha verificado esta aplicación". Es esperado en uso personal: pulsa Continuar.
- **Instagram**: necesita una cuenta profesional enlazada a una Página de Facebook.

## Subir contenido

En la biblioteca de **Media** sube imágenes y vídeos. El archivo va directo al almacenamiento; la app comprueba formato, tamaño, duración y proporción. Instagram solo acepta imágenes JPEG. Si algo no cumple, te dice qué cambiar.

## Crear y programar una publicación

1. Pulsa **Crear publicación** y elige marca y cuentas de destino.
2. Elige el tipo por red (imagen, carrusel, reel, historia, vídeo, short…). El compositor te avisa de las limitaciones de cada tipo.
3. Escribe el texto, adjunta la media y elige fecha y hora (zona horaria de la marca).
4. Guarda como **borrador** o **programa**.

Estados que verás: Borrador, Programada, Publicando, Procesando, Publicada y Con error. Mientras sea Borrador o Programada puedes editar, reprogramar o borrar. Una vez en curso o publicada, ya no.

### Qué pasa si algo falla

- Un error temporal se reintenta solo (hasta 4 intentos).
- Si una cuenta necesita reconectarse, la publicación se conserva y sale al reconectar (con un plazo máximo; por defecto 72 h).
- Si el servidor estuvo caído, lo vencido se publica al volver solo si lleva menos de 24 h de retraso (valor por defecto, por confirmar).
- Si una red agota su cupo diario, por defecto se aplaza hasta que lo libere.
- Cuando una publicación termina con error recibes un aviso en la app y por correo, con el motivo.

### YouTube

Cada subida gasta unas 1.600 de las 10.000 unidades diarias: unas 6 subidas al día.

### TikTok

Por ahora el vídeo se envía como **borrador a tu bandeja de TikTok**: recibirás la notificación y terminas de publicarlo en la app. La publicación directa se activará cuando TikTok apruebe la auditoría. En la app aparece como "Publicada" con la nota "revisa tus borradores".

## Calendario

Vista de semana o mes (y lista) con el estado por red. Puedes filtrar por red y reprogramar una publicación pendiente moviéndola.

## Estadísticas

El **Dashboard** muestra por marca seguidores, alcance, visualizaciones e interacciones, con total y desglose por red. Elige el periodo y compara con otro. Las métricas que una red no ofrece se muestran como "no disponible", nunca como 0. Los datos se actualizan una vez al día (sobre las 12:00 UTC); los de las publicaciones recientes, cada 6 horas.

## Informes

En **Informes** elige marca, rango y formato (PDF, CSV o ambos) y, si quieres, un periodo de comparación. Se generan en segundo plano; cuando estén listos pulsa Descargar (el enlace caduca en 5 minutos, pero se genera uno nuevo cada vez). Se conservan 30 días y puedes tener hasta 5 en curso.

## API keys y asistentes de IA (MCP)

En **API keys** crea una clave para que un asistente (como Claude) programe y consulte por ti. La clave empieza por `msk_` y se muestra **una sola vez**: cópiala. Elige qué puede hacer (leer marcas, leer o escribir publicaciones, estadísticas, informes) y cuándo caduca. Puedes revocarla en cualquier momento. Detalles técnicos: [servidor MCP](../modulos/mcp.md).

## Avisos

El icono de avisos lista fallos de publicación y cuentas que necesitan reconexión. Reconectar conserva tus publicaciones pendientes. Desconectar una cuenta hace fallar las que tuviera pendientes.
