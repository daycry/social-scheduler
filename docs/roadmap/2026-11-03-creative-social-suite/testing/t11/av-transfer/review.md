# T-11 — transferencia física privada del render

Fecha: 2026-11-05. Subbloque revisado y verificado. **T-11 sigue en progreso; roadmap7/23**. Queue.enqueue(register) continúa deshabilitado: esto no habilita exportación MP4/GIF pública.

## Comportamiento implementado

El assemble calcula el SHA256 global y hashes por cada16MiB en una sola lectura, con buffers de1MiB, cancelación y límite8GiB/512hashes. La transferencia compara cada parte con esa prueba nativa aceptada. Los checkpoints antiguos sin prueba de partes fallan explícitamente; no se convierten en íntegros por declaración.

La copia local avanza una parte por llamada, confirma el parcial en disco, recupera colas no reconocidas según el cursor durable, crea un destino exclusivo y verifica sus bytes en pasadas posteriores. La copia de biblioteca sobrevive al borrado del árbol temporal. En S3/R2, el disco y el prefijo quedan fijados; el multipart conserva recibos y recupera respuestas perdidas de creación/completado. HEAD/metadata/ETag no sustituyen la verificación: se descargan rangos con If-Match y se comprueba cada SHA256 real. No se inicia otro multipart si desaparece un objeto ya verificado.

Las llamadas S3 llevan retries0, timeout≤20s/connect≤5s y deadline compartido≤85s; los rangos son streamed con read_timeout≤5s, validación de ContentLength/ContentRange y cierre del cuerpo. La copia reserva su tamaño completo en media_reserved_bytes antes del I/O y cuenta en el presupuesto output junto a los leases. La reserva persiste tras cancelación/interrupción y se libera al aceptar atómicamente el medio listo. El journal solo se actualiza bajo fencing de lease/generación y antes del deadline; un recibo tardío no publica ni avanza.

## Revisión adversarial

| Intento | A | B | D | Resultado |
|---|---|---|---|---|
| 1,11 fuentes | Conformidad parcial ✓; integra explícitamente pendientes | Important B1: hashFile eliminado y todavía invocado por verifiedFile | Important D1: búsqueda de assemble multiplicada por cada bloque recorre todos los pasos | Ambos defectos confirmados |
| 2,11 fuentes; delta86líneas | ✓, criterios previos conservados | Sin defectos nuevos; B1 corregido | Sin hallazgos nuevos; D1 corregido | Sin gaps pendientes |

B1 se corrige restaurando el helper original de validación de tramos. Una regresión Node comprueba que un tramo corrupto se rechaza antes de FFmpeg. La focal nativa original falló durante assemble; tras el fix el MP4 real se ensambla, copia a biblioteca y acepta mediante llamadas internas manuales.

D1 se corrige solo para consultas con fases filtradas: acceso por workspace/fase de lease y EXISTS indexado de pertenencia a generación. Las ramas allphases[] y unpublished se conservan. Con migración real y método actual en SQLite,100 llamadas con8193pasos pasan de328,65ms a17,92ms; con50001pasos,2397,23ms a14,11ms. EXPLAIN usa workspace+phase y workspace+generation+result_lease_id. No es medición MySQL ni de transferencia completa. El journal de256recibos con ETags máximos mide72863B,≈28MB acumulados durante el flujo; no se atribuye un gap de JSON.

Las tres lentes ejecutaron focales aisladas: intento1 PHP39/39,203aserciones yNode2/2; intento2 PHP40/40,206aserciones yNode3/3. A2 ejecutó además3regresiones de reuso/promoción,13aserciones. SHA11/11 estables en ambos intentos. Scope-check --baseHEAD0 sin fuera/avisos/exclusiones de usuario; selector C=false/D=true (migración y await en bucle). Model-tier2 por instalación parcial, modelo heredado. Sin cobertura configurada, Jira, constitución, interop ni cambios OpenAPI/UI aplicables. Tablas completas en el ledger.

## Verificación final root

| Evidencia | Resultado |
|---|---|
| PHP Studio y medios/importación | **333/333,1741aserciones,0fallos/errores/skips**,34282ms; `57605c` |
| Frontend completo, incluido renderer/opciones/checkpoints e integridad nueva | **153/153,0fallos/cancelados/skips**,37390,6685ms; `c587e6` |
| Integridad de fuentes |37SHA iguales antes/después de ambas suites |
| Focal nativa posterior al fix |1/1,21aserciones; `native-fixed.xml`, `1a00c9` |

Los333 casos incluyen cinco nativos reales,99aserciones. Cuatro casos de worker siguen hastaassemble (MP4/GIF y tono WAV440/GIF sin música copiada). El caso de puente MP4 añade la copia local real y aceptaciónBD **manual**, con hash físico del medio y estado listo. STUDIO_AV_NATIVE_TEST=1 y grupo nativo≤3GiB; sin ONNX. El S3/R2 se prueba mediante simulador del SDK, sin cuenta/bucket/red reales, con respuestasHTTP404/412 y fallos tras efectos remotos. Sus payloads sintéticos no acreditan codecs nativos en un bucket real.

Conservamos JUnit/stdout/stderr finales reales y JUnit de la focal nativa fallida y corregida. Los RED iniciales se registran como extractos de resultados de herramienta con IDs; no existen stdout originales de esas primeras focales y no se presentan como raw independiente. La primera invocación Node--test en sandbox falló por spawnEPERM; el flag sin experimental no existe en Node22. La ejecución directa fue el rojo válido; la suite final usa --experimental-test-isolation=none y host autorizado. El fallo intermedio del mockS3 se corrigió dándole respuestaHTTP404/412 real, sin relajar el producto.

QAroot con revisión independiente A/B/D; no es QA final independiente del padre. No cambia UI/rutas públicas; los42E2E anteriores conservan su alcance y no se repiten ni se atribuyen al nuevo flujo.

## Pendiente antes de habilitar register

Faltan aborto y limpieza de reservas pendientes, recuperación de terminales/huérfanos y posterior integración con runner/queue. La copia mantiene journal privado en MediaAsset; al diseñar la limpieza debe conservarse también el recibo necesario para actuar después de cascadas del propietario. No se deben liberar reservas, perder UploadId ni borrar un medio listo todavía referenciado. Después siguen señal de cancelación nativa y flujo de descarga/borrador web/API/MCP con QA integral.

T-10 conserva pendiente la inferencia con su presupuesto actual. El objetivo completo y la auditoría final de aplicación/documentación con Claude CLI continúan abiertos. Sin nuevos commits/push T-10/T-11 ni migración150000 en MySQL/VPS.

Referencias primarias del protocolo: [R2 multipart](https://developers.cloudflare.com/r2/objects/upload-objects/), [UploadPart S3](https://docs.aws.amazon.com/AmazonS3/latest/API/API_UploadPart.html), [stream/read_timeout de Guzzle](https://docs.guzzlephp.org/en/stable/request-options.html#stream).
