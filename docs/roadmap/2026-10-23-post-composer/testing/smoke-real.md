# Registro del smoke real del compositor

Preparado el 2026-10-31. **Pendiente de ejecución por el usuario**: este registro no acredita publicaciones reales, opciones aceptadas por las redes ni ausencia de duplicados.

Fuentes: [T-41/T-42](../tasks.md), [M-01 y M-04](../test-plan.md), [decisiones por red](../../../referencia/decisiones-por-red.md). La QA aislada de T-43 usa conectores simulados y no sustituye este smoke.

## Preparación de la sesión

| Dato | Registro del usuario |
|---|---|
| Fecha y zona horaria | PENDIENTE |
| URL y versión de la aplicación utilizada | PENDIENTE |
| Marca propia de prueba | PENDIENTE |
| Cuentas conectadas y permisos vigentes | PENDIENTE |
| Cuota disponible de YouTube | PENDIENTE |
| TikTok: modo borrador confirmado | PENDIENTE |
| TikTok: Direct Post auditado y disponible para el contraste de M-04 | PENDIENTE; no activarlo solo para completar este registro |
| Destinos existentes antes de empezar | PENDIENTE |

No registrar contraseñas, tokens, cookies ni claves. Usar los identificadores internos de prueba y los enlaces de los contenidos que el usuario elija publicar.

## M-01 — Una publicación por red

Elegir contenido propio de prueba y registrar el texto efectivo de cada red. Identificar cuál de estos casos usa **Publicar ahora**; no darlo por probado por haber programado una publicación futura.

| Red | ID de post/destino en la app | Texto efectivo y tipo de media | Opciones elegidas | Fecha programada / Publicar ahora | URL o ID externo | Estado observado | Resultado |
|---|---|---|---|---|---|---|---|
| Instagram | PENDIENTE | PENDIENTE | Texto propio por red | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| Facebook | PENDIENTE | PENDIENTE | Texto propio por red | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| YouTube | PENDIENTE | PENDIENTE | Ver M-04 | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| TikTok | PENDIENTE | PENDIENTE | Borrador a bandeja; ver M-04 | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |

En TikTok se registra por separado la recepción del borrador y, si el usuario lo termina de publicar, el enlace final. No confundir una subida a bandeja con Direct Post público.

## Reintento y ausencia de duplicados

Usar el mismo destino de prueba para el reintento previsto por M-01; registrar los identificadores antes y después, sin crear una segunda publicación para aparentar el reintento.

| Red | Destino original | Motivo / intento observado | IDs externos antes | IDs externos después | Número real de publicaciones | ¿Duplicados? |
|---|---|---|---|---|---|---|
| Instagram | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | NO VERIFICADO |
| Facebook | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | NO VERIFICADO |

## M-04 — Opciones reales de YouTube y TikTok

| Comprobación | Valor elegido en la app | Valor observado en la plataforma | Evidencia | Resultado |
|---|---|---|---|---|
| YouTube: categoría | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| YouTube: hecho para niños | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| YouTube: notificar suscriptores | PENDIENTE | PENDIENTE | PENDIENTE | NO EJECUTADO |
| TikTok en borrador: controles de divulgación ocultos | PENDIENTE | No se transmiten opciones de divulgación en la subida a bandeja | PENDIENTE | NO EJECUTADO |
| TikTok con Direct Post auditado: divulgación elegida y transmitida | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE: requiere Direct Post disponible |
| Opciones no confirmadas en T-26 siguen ocultas | PENDIENTE | PENDIENTE | PENDIENTE | NO VERIFICADO |

El pase M-01 de TikTok usa borrador a bandeja. En ese modo, los interruptores de divulgación están ocultos y la subida solo envía `source_info`; activar una divulgación después en TikTok no demuestra que la app la transmitiera. Registrar esa comprobación de UI por separado. El contraste de transmisión de M-04 requiere una cuenta con Direct Post auditado y esas opciones disponibles. Si no lo está, conservar esa fila **PENDIENTE** y no marcar M-04 completo por el pase de borrador. No se cambia la configuración ni el modo de publicación para aparentar esa verificación.

## Reconciliación con la aplicación

- Destinos esperados, identificados por los IDs de la tabla: **PENDIENTE**.
- Destinos y estados observados en la lista de publicaciones: **PENDIENTE**.
- Destinos con estado `publicada`, distinguiendo el borrador de TikTok: **PENDIENTE**.
- Diferencias entre el listado, los enlaces externos y el recuento: **PENDIENTE**.
- Caso concreto de Publicar ahora y hora observada: **PENDIENTE**.

## Incidencias y conclusión

| Fecha | Red / destino | Incidencia y mensaje visible | Evidencia sin secretos | Estado / siguiente paso |
|---|---|---|---|---|
| PENDIENTE | PENDIENTE | Sin ejecución registrada | PENDIENTE | PENDIENTE |

- [ ] M-01: las cuatro redes muestran el texto efectivo y las opciones elegidas.
- [ ] Instagram y Facebook: no hay duplicados, incluido el reintento.
- [ ] M-04: opciones contrastadas con las plataformas reales.
- [ ] Recuento y destinos coinciden con la aplicación.
- [ ] Incidencias anotadas y conclusión del usuario registrada.

**Conclusión actual: NO EJECUTADO.** T-41 sigue `en-progreso`. Este documento preparado no cambia la spec a `implementada` ni cierra el plan.
