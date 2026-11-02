# Smoke real de audio — pendiente del usuario

No ejecutado durante el desarrollo. Las pruebas automáticas usan proveedores y cuentas ficticios. Usar una cuenta propia y el contenido/visibilidad que el usuario decida; no confundir este registro con los smokes anteriores del VPS o de publicación básica.

| Comprobación | Resultado esperado | Estado / evidencia |
|---|---|---|
| Capacidades de las seis redes | Compositor y `list_accounts` muestran los formatos reales y las opciones de audio de esa conexión | Pendiente |
| Instagram elegible | Buscar canción/audio original, preescuchar cuando haya URL y elegir una pista inequívoca | Pendiente |
| Guardar/reabrir desde web y MCP | La misma pista y ambos volúmenes; las URLs de preescucha se renuevan, no se guardan en opciones | Pendiente |
| Cambiar/quitar pista | Actualiza solo ese destino; quitar conserva el sonido que ya estaba en el archivo | Pendiente |
| Publicación de un Reel | Enlace/ID final y pista nativa correcta; revisión de volumen por el usuario | Pendiente |
| Reintento/reconexión | No duplica un contenedor ya creado; credenciales caducadas piden reconectar | Pendiente |
| Cuenta/pista no elegible | Motivo claro; no se publica silenciosamente sin la pista elegida | Pendiente |
| TikTok foto Direct Post | Dominio verificado y permisos adecuados; activar música recomendada, guardar/reabrir y publicar una vez: TikTok elige música | Pendiente |
| TikTok sin música recomendada | `false` conserva la decisión; no arrastra el ajuste a vídeo o modo bandeja | Pendiente |
| Vídeo con sonido en Facebook/YouTube/Bluesky/Threads | La publicación conserva el audio del archivo dentro de los límites del formato; no se promete reconocimiento ni selector nativo de canción | Pendiente |

Registrar fecha, red, tipo, cuenta identificada sin secretos, enlace/ID del post, resultado observado y cualquier error. No guardar cookies, tokens o URLs temporales de preescucha en la evidencia. El catálogo concreto de TikTok Business y la asociación de catálogo de Facebook no se dan por implementados por este smoke.

## Ampliación T-11/T-12: presets (pendiente del usuario)

En el servidor actualizado, guardar y reabrir un borrador de Instagram con multimedia, colaborador e indicación de IA; comprobar que conserva los valores. Al publicar una pieza propia, verificar etiqueta de IA y envío/aceptación de invitación por la cuenta colaboradora; registrar ID/enlace y resultado. Comprobar también un carrusel (invitación y etiqueta del conjunto) y título independiente de un vídeo/Reel propio de Facebook. Verificar errores plegables y sus enlaces en móvil. Estos pasos no se han ejecutado contra redes reales durante desarrollo y no cierran T-06 por simulación.
