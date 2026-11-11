# T-11 — reserva y aceptación del registro durable

Fecha: 2026-11-05. Estado del subbloque: revisión y regresión cerradas. T-11 permanece **en progreso**; el roadmap conserva **7/23** tareas completadas.

## Qué está implementado

`StudioAvState::allocateRegistration` reserva un solo medio pendiente por workspace. Reutiliza su ID al renovar un lease; fija propietario, destino generado, disco, SHA256 y tamaño del assemble aceptado. Comprueba metadata frente al plan, dimensiones padded también en GIF y límite de biblioteca al reservar y al aceptar.

`accept(register)` exige esa reserva y un journal privado de transferencia completo/verificado. Bajo el mismo fencing y transacción publica el medio, el vínculo a la marca y la exportación con origen de descarga local. Un fallo al crear el vínculo revierte la visibilidad del medio; una cancelación o lease vencido impide aceptar el resultado.

Las operaciones genéricas complete/finalize/delete consultan la fila actual y protegen la reserva pendiente. Upload y complete HTTP responden409 antes de modificar el destino. El borrado de medios ya listos conserva las reglas existentes de referencias a publicaciones.

## Revisión adversarial

| Intento | Lente A | Lente B | Resultado |
|---|---|---|---|
| 1, cuatro fuentes congeladas | Conformidad ✓; HTTP se aceptó inicialmente por el guard de servicio | Important B1 confirmado por reproducción | La ruta upload escribía antes de llegar al guard. Journal conservado permitiría aceptar bytes distintos |
| 2, cinco fuentes congeladas | B1 corregido; demás ✓ preservados | Sin defectos pendientes | Guard temprano HTTP y dos casos permanentes verifican bytes, journal, estado pendiente y409 |

Cada lente ejecutó la focal propia: intento1 **20/20,75 aserciones**; intento2 **22/22,83 aserciones**. Los cuatro/cinco SHA coinciden antes y después. Las tablas completas están en el ledger. Scope-check --baseHEAD exit0, sin fuera de alcance, avisos ni exclusiones de usuario. Selector explícito sobre runtime: C=false/D=false. Model-tier devuelve2 por instalación parcial sin agents; modelo heredado. Sin cobertura configurada, Jira, constitución o interop aplicables; no cambia OpenAPI ni UI en este bloque.

## Evidencia de pruebas

| Prueba | Resultado y alcance |
|---|---|
| RED inicial | Un medio listo de la misma marca, pero ajeno al render, era aceptado; `526067` |
| RED reserva/guards/pin/GIF | Método ausente `5f810e`; guards genéricos `0ba4c3`; destino/presupuesto `4cfacb`; GIF impar `8b5b51` |
| RED HTTP B1 | 2/2 fallan: upload sobrescribe `video` con `wrong`; complete devuelve500. Raw persistido `raw/red-http.stdout`, `raw/red-http.stderr`; tool `17b38c` |
| GREEN HTTP | 2/2,8 aserciones; `2a7a11` |
| Regresión final root | **315/315 PHP,1611 aserciones,0 fallos/errores/skips**, exit0,31731ms, `5846f6`; 33 SHA iguales antes/después |

La regresión abarca estado/archivos/limpieza/cola/puente nativo, renderer/exportaciones/API/MCP/backend/automatización/fotogramas/documento temporal/audio y medios genéricos/importación. Incluye cinco casos audiovisuales reales,92 aserciones: worker de BD hasta MP4/GIF, WAV440Hz medido/GIF sin copiar música, y puente MP4 bajo límite de grupo heredado. STUDIO_AV_NATIVE_TEST=1; sin ONNX, MySQL, red real ni VPS. Los cinco casos mantienen su alcance previo **hasta assemble** y no prueban la nueva transferencia durable.

El journal completo de los tests de registro se fabrica explícitamente para probar la aceptación de estado; **no acredita copia local ni R2**. Los RED iniciales se conservan como extracto de resultados observados con IDs de herramienta; no existen stdout originales de esas primeras focales y no se presentan como raw. El RED HTTP y la regresión final sí conservan stdout/stderr/JUnit reales.

La QA es root con revisión independiente A/B; no es la QA final independiente de T-11. Sin cambios UI, no se repiten frontend150/E2E42 anteriores ni se amplía su alcance. La regresión179 anterior al fixHTTP es histórica.

## Trabajo siguiente

Implementar transferencia física independiente local/R2, progreso por partes y prueba de integridad; recuperación/aborto/limpieza de reservas pendientes; después conectar register al runner. Queue.enqueue(register) continúa devolviendo false: el flujo actual se detiene tras assemble y no ofrece MP4/GIF públicos. Siguen pendientes señal de cancelación nativa, descarga/borrador integrados, UI/API/MCP y QA final integral. T-10 mantiene abierta la inferencia con su presupuesto actual; la auditoría de toda la aplicación/documentación con Claude CLI se ejecutará tras implementar el roadmap.

La siguiente transferencia debe respetar las reglas oficiales de [multipart S3](https://docs.aws.amazon.com/us_en/AmazonS3/latest/userguide/mpuoverview.html) y [multipart R2](https://developers.cloudflare.com/r2/objects/upload-objects/), verificadas en esta sesión; esas referencias no son evidencia de una transferencia implementada.

No se han hecho nuevos commits/push de T-10/T-11 ni migraciones en MySQL/VPS.
