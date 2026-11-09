# Diagnóstico focal de gráficos y transporte JSON

2026-11-06. **No es aceptación de interfaz ni PASS del roadmap.** Los intentos reales1 y2 conservan cada uno un fallo desktop y once casos no ejecutados. [Informe del intento2](../chart-ui-qa2/report.md). Las correcciones focales pasan pruebas aisladas; aún necesitan revisión y nueva QA integrada.

## Rectángulo tipográfico y tinta

El oráculo trataba getBBox como tinta. En bar r0, getBBox empieza104,578958 frente al mínimo106,25; los contornos reales ocupan109,124127–120,693649 dentro de107–184,2. Las cuatro categorías y los píxeles de la vista previa confirman el diagnóstico. El replay original rechaza BBox, acepta sólo tinta con la misma tolerancia0,75 y rechaza desplazarla una fila77,2. No se ha parcheado el renderer por este falso positivo.

El cambio del observador conserva BBox/matrices y añade TextMetrics de tinta por tspan/CTM/fuente efectiva. Oráculo9 y medidor8 verdes tras RED; vecinos conservados. READY_INK.json conserva hashes y límites. El script archivado con sufijo.txt documenta el experimento original y sus SHA; no es un comando portable ni debe ejecutarse contra fuentes posteriores sin restaurar su snapshot.

## Transporte controlado en Chromium local

Todas las sondas usaron datos ficticios, módulos reales y servidor loopback mínimo, sin Laravel, PHP, DB, proveedores, modelos ni cambios de producto. Cada ejecución tuvo Job agregado3GiB COMMIT, preflight6GiB/piso3GiB/100ms/plazo90s; heapNode512MiB. No son métricas RSS.

| Sonda | Resultado de la instrumentación | Duración / pico COMMIT |
|---|---|---|
|1 | Parcial:8observaciones completas y una parcial; Nodeexit1 por espera sin límite de response.finished antes del cierre propio80s |80,522s /231.473.152B |
|2 |16observaciones completas; suprimir cancel después de done no cambia ERR_ABORTED, hipótesis descartada |10,418s /232.542.208B |
|3 |16observaciones completas; aísla transporte y método de lectura, sincancel/release/abort/dispose |10,573s /231.890.944B |

En la tercera:8conContent-Length terminan sin aviso;4sin longitud leídos con arrayBuffer también;4sin longitud leídos manualmente notificanERR_ABORTED pese a EOF, SHA y lexema original correctos. Default/signal/redirect:error/full no cambian el patrón. Los eventos conservan RequestID/tipo/query/fase/tiempos y estado response.finished (incluido pending_after_500ms, nunca transformado en éxito). Los16casos son observaciones diagnósticas, no casos E2E aprobados.

Las fuentes permanecieron iguales y los puertos efímeros quedaron libres. La prueba no permite asociar retrospectivamente uno a uno los tres errores originales porque aquellos logs perdieron identidad/query/tiempo; sí reproduce y aísla el mecanismo con datos exactos. No se añaden allowlists de errores ni se cambia redirect:error o el lector acotado.

## Corrección HTTP en curso

El escritor canónico ya guarda json_bytes ySHA de los mismos chunks; read valida tamaño/SHA y el exportador conserva gramática/flags. Se propaga esa longitud autorizada y se declara Content-Length sóloJSON. El cuerpo ysu callback no cambian; CSV/TSV siguen dinámicos y MCP mantiene su filtro. REDheaderausente208B→GREEN3tests25assert; vecinosdatasets19/169 yexport7/27 excluyendo52MB. JUnit/stdout archivados enjson-header, no se atribuye transporte de52MB a estos tests.

La siguiente puerta es revisión fresca del bloque focal y UNA nueva pasada de12casos, con los mismos guards, política estricta de red y observadores independientes. Los informes fallidos y estas sondas permanecen inmutables. [Contrato focal](../../../t12-chart-ink-http-contract.md).
