# T-11: cola privada y ejecución nativa desde Laravel

2026-11-05. La cola yel puente nativo funcionan hasta la unión MP4/GIF. T-11 permanece en progreso yel roadmap conserva7/23 tareas completas. El flujo se detiene ante register; todavía no marca ready ni ofrece exportación audiovisual desde la UI/API/MCP. Faltan registro/descarga/borrador yseñal de cancelación del proceso. La auditoría completa con Claude CLI sigue prevista tras implementar todo el roadmap.

Cada mensaje contiene un lease privado reservado. El arranque atómico solo se realiza una vez, verifica generación/posición yrenueva el TTL de una entrega todavía vigente. El recibo de la cola de BD distingue un mensaje que espera worker de un proceso interrumpido. Una entrega duplicada o un callback de fallo antiguo no afectan a otra fase.

El puente toma documento ymetadata autorizada del servidor, genera un manifiesto privado yllama a av-runtime bajo el mutex del workspace. Comprueba identidad de respuesta ylímite OS; comparte el plazo85s entre ejecución einspección de bytes. Copia, verificación ypromoción también reciben ese mismo deadline. Retira los workmanifests para no acumular el proyecto completo por tramo. No mantiene transacciones BD durante procesos.

El handler acepta resultados ydespacha la siguiente fase sin ShouldBeUnique sobre el export completo. La recuperación limpia asignaciones vencidas, repara promociones pendientes yretira parciales de exports fallidos/cancelados sin reencolarlos. `studio:recover --limit=1..100` queda registrado cada minuto sin solapamiento; no instala cron o servicios en el VPS. La comprobación entre operaciones es de15s, con operaciones individuales de limpieza hasta30s opromoción hasta85s; no es un límite duro15s del comando completo.

## Revisión

| Intento | Lentes | Resultado |
|---|---|---|
|1 | A+B+D; C=false | A/D sin gaps; B1/B2Important reproducidos: crash trasaceptación sininput fijo, yterminales excluidos de limpieza |
|2 | A+B+D; C=false | B1/B2 corregidos; D2-1Important: consulta pending partía de los steps publicados |
|3 | A+B+D; C=false | Sin gaps pendientes; D2-1 corregido con leasesfalse como entrada yEXISTS indexado porgeneración/resultado |

Las18fuentes se congelaron ycomprobaron antes/después de cada revisión. El tercer delta modifica4ficheros/80líneas; preserva los veredictos anteriores. Los selectores ydiffs completos están en [raw](raw/). Scope0fuera/0avisos/sin exclusiones deusuario. Sin constitución/interop/OpenAPI nuevo/cobertura configurada/Jira ni promoción de conocimiento.

D3 verificó consulta ymigración reales en SQLite:50000publicados/0pendientes devuelve0 sin recorrer los50000steps;20pendientes adicionales devuelve16 sinpublicar. No se atribuyen tiempos ni aceptación MySQL. Las ejecuciones de las lentes omiten explícitamente los cinco casos nativos; la regresión posterior los activa.

## Regresión posterior

| Comprobación | Resultado | Evidencia |
|---|---|---|
| PHP integrado: once archivos Studio |195/195,1024aserciones;0errores/fallos/skips |[JUnit](raw/phpunit.xml), [salida](raw/php.stdout) |
| Cinco casos nativos opt-in |5/5,92aserciones incluidas en las195; no son pruebas adicionales |[Casos ytiempos](native-cases.json) |
| Integridad durante la pasada |30fuentes idénticas antes/después y24artefactos registrados |[Manifiesto](manifest.json), [resumen](summary.json) |

Los casos nativos ejecutan el supervisor real desde PHP yel worker real de la cola de BD desechable. Cubren MP4 yGIF de100×80,12frames/1s; yuna segunda pareja con WAV privado: MP4 copia/normaliza/mezcla el tono440Hz yproduceAAC con amplitud medida>0,07; GIF no copia la música no utilizada. Se valida prueba de límiteOS≤3GiB. Las salidas quedan en assemble/processing, sin medio registrado ni publicación externa. Esos resultados no acreditan todavía la exportación pública completa, vídeo con recursos/tipografías/transiciones desde la aplicación o multimediaLinux.

Regresión terminal: sesión58040/PID28176,21508ms (`d2aa96`). `STUDIO_AV_NATIVE_TEST=1`, SQLite ystorage UUID propios. No repetir esta suite sin cambios ofallos nuevos. No se repitieron frontend150 ni E2E42 anteriores: no cambió JavaScript/componentes/rutas públicas, yno se convierten sus evidencias históricas en QA del nuevo flujo nativo.

Pint ygit diff --check correctos. Ledger-lint0incoherencias y16avisos previos deChangelog en tareas abiertas. Baseline9fuentes previas,18fuentes revisadas y30fuentes integradas; las diferencias corresponden a fuentes anteriores preservadas, no a revisiones omitidas.

## Límites y continuación

QA root, no QA final independiente del padre. La prueba de cola usa SQLite, no acredita concurrencia MySQL multiproceso. La migración con started_at/recibo/promoción/metadata solo se ejecutó en bases desechables. No se aplicó a MySQL local ni al VPS. No se ejecutó ONNX/imaging ni OAuth/publicaciones reales.

Continúa registro durable del medio, descarga/borrador, señalización de cancelación nativa, superficies web/API/MCP yUI. El cierre de T-11 requiere el flujo completo yQA independiente, con medidas audiovisuales pertinentes. No hay nuevos commits/push de T-10/T-11 ni se ha cerrado el objetivo completo.
