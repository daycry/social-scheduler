# Presentaciones: avance real y QA pendiente

2026-11-07. T12 sigue en progreso;8de23tareas cerradas. Esta evidencia corresponde al escritorio1440 y **no acepta el caso ni la fase**.

La corrección de filtros vacíos pasó REDfuncional4/4→GREEN7/7+28vecinos, revisiónA+B0hallazgos ybuild10real83inputs/78assets. El primer intento anterior había quedado en blanco al recargar. Esta nueva ejecución sí reabre el documento guardado y llega a presentación AV.

| Alcance | Observación real | Límite |
|---|---|---|
|Recarga del documento AV|Editor y Presentar disponibles|Sólo este documento de prueba|
|Vídeo/audio propios|Eventos nativos playing;frames ylinaje de drawImage;vídeo64² con tiempos crecientes|No aprobación auditiva humana ni caso completo|
|Pausa y navegación|Aserciones anteriores al fallo pasan;medios pausados y destino azul con notas correctas|Sólo escritorio;ver PNG originales|
|Cierre|Todos los medios terminan pausados;la prueba rechaza5eventos|La marca de cierre era anterior al clic efectivo|
|Frontera temporal del rechazo|Los5eventos sucedieron como mínimo35.084ms ANTES del clickaction|Oráculo debe tomar marca en captura del gesto real;no quitar aserción|
|Fuentes privadas/gráfico vivo/fullscreen/cancelación/3originales/mobile|No ejecutados|23casos restantes detenidos por maxFailures1|

Resultado original:1fallo/23sin ejecutar,0flaky;18404.382ms del runner. QA2 previo terminó en ledger-lint antes de crear runtime por una cabecera administrativa duplicada, reparada con un carácter ylint0. QA3 conserva su origen common900:536.134968s acumulados incluidos ese fallo, reparación y revisión; **no900s nuevos**. Job COMMIT pico701423616B bajo3GiB, físico mínimo9779249152B, sin fallo de guarda ni deriva. Supervisor30088 terminal,owned[],8080IPv4/IPv6 libres.

La captura de reproducción muestra vídeo centrado, preview azul siguiente y notas en presentador. La de navegación muestra la segunda diapositiva azul y sus notas. Se inspeccionaron los PNG guardados; no se renderizó otra vez.

Se conservan JSON/PNG originales por bytes en este directorio ysu manifiesto. La traza completa sigue local en el directorio raw original, identificada por SHA. El implementer fresco corrige sólo la marca temporal del oráculo con regresión pura; aún no hay nuevo PASS nativo, PDF final ni cierre deT12. Build10 no requiere repetición por un cambio de tests que no forma parte de sus entradas.
