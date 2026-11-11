# T10 — inferencia real dentro del límite de memoria

La condición de capacidad cambió: la lectura inicial encontró 12.226.617.344 bytes físicos disponibles. Se ejecutó una sola vez el guard de diagnóstico existente sin cambiarlo: mínimo inicial 10 GiB, worker de 6 GiB, piso físico de 4 GiB vigilado y límite de 30 segundos. No se repitió la prueba de 8 GiB rechazada anteriormente ni se redujeron las protecciones.

## Diagnóstico conservado

Exit 0 en 10,566 segundos; preflight efectivo 11.877.777.408 bytes disponibles. Modelo local fijado por SHA, fotografía de prueba y salida nueva privada; sin aplicación, env, BD o red. Resultado RGBA 512×512, 518.063 bytes, alpha mínimo 0/máximo 255. Pico del proceso medido: commit 6.306.537.472 bytes y working set 6.316.195.840 bytes. Son métricas distintas. Los seis hashes de entradas/código/modelo no cambiaron.

El primer diagnóstico usa el helper de perfil histórico; no se presenta como la ejecución exacta del entrypoint de producción. El PNG retenido que se muestra abajo procede de ese diagnóstico.

## Entry point real y comprobaciones existentes

Después se ejecutaron sin modificar los tests existentes test_background.py y test_memory.py sobre el entrypoint real. **4/4 PASS**, 10,475 segundos del runner: quitar fondo preserva los puntos de sujeto comprobados y el original, un modelo sin checksum válido se rechaza, el original no se puede sobrescribir y el kernel observa el límite efectivo de 6 GiB del worker.

El supervisor de esta verificación añadió un Job agregado de 6,25 GiB para incluir unittest/controlador, sin modificar el default audiovisual de 3 GiB ni el worker de 6 GiB. Preflight exigía 10,25 GiB libres, piso físico de 4 GiB cada 100 ms y deadline de 30 segundos; killOnClose y noBreakaway consultados en kernel. Pico agregado de commit 6.333.394.944 bytes; mínimo físico observado 5.705.973.760 bytes, superior al piso. Exit 0, árbol propio terminado y siete hashes intactos. Las muestras no prueban una cota de RAM física entre intervalos ni comportamiento Linux.

## Observación de transparencia

El análisis independiente del PNG retenido encuentra 149.423 píxeles completamente transparentes, 107.110 opacos y 256 niveles de alpha. Bandera, lanzadera y fondo en los puntos comprobados tienen alpha 0; rostro/traje alpha 255. Root inspeccionó la máscara y la composición sobre tablero: se aísla a la persona; el casco sostenido se descarta como objeto adicional. No se promete segmentación perfecta de accesorios. El original sigue intacto y la interfaz permite revisar el resultado antes de añadirlo.

![Resultado sobre tablero de transparencia](checkerboard.png)

![Canal alpha](alpha.png)

## Alcance abierto

Esto acepta sólo la comprobación focal real del presupuesto en este host Windows. T10 sigue abierta: falta QA integrada actual de fuentes, imágenes, máscara/filtros/crop y exportación con interfaz/API/MCP. Tampoco acredita Linux, recursos del VPS, concurrencia de varios workers ni todos los tamaños/imágenes permitidos. El usuario mantiene las pruebas del servidor. El modelo y los originales no se copiaron a este informe; los artefactos publicados son saneados.
