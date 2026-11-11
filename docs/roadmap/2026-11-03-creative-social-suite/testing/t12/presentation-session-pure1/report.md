# P1: motor de sesión de presentación

SOURCE-REVIEW-PASS del componente puro; sin aceptación de interfaz ni cierre de T12.

El motor valida documentos nativos v1/v2, conserva una copia de la selección y ofrece navegación, pausa/reanudación, reloj explícito, bucle, blackout y cierre. No posee timers, DOM ni recursos de navegador. Su integración en Editor, vistas de audiencia/presentador, notas persistidas, pantalla completa, grabación y Office siguen pendientes.

TDD inicial: módulo ausente RED,12/12 focales y25/25 con vecinos (incluyen12). La revisión independiente detectó B1, regresión decimal en avance0/frontera, y después B2, fin decimal acumulado. Ambos se reprodujeron y corrigieron con pruebas anteriores a cada fix. Resultado actual19/19 focales y32/32 con vecinos (incluyen19), sin fallos/omitidos/cancelaciones. No se suman estas tandas como casos nativos.

Revisión final A+B limpia. Probes independientes verifican límites exactos, residuales, v1/v2, loop, selección y deltas enormes; B comparó280 casos mediante BigInt y30720 estados fraccionarios acotados. Los originales, baselines, tres revisiones y logs se conservan completos. El primer log auxiliar de frontera223.4 usaba un literal impreciso: se corrigió antes del código y no se cuenta como RED válido.

El manifiesto original de revisión3 contenía un puntero heredado a review1, con hash/evidencia de review2 correctos. Se preserva y se añade manifest-current.json con corrección explícita, comprobada por B. No cambia código, pruebas ni presupuestos.

Las fuentes actuales están en review3/source/resources y review3/source/tests. No están importadas ni compiladas en el build7 de gráficos. No hay pruebas de navegador ni acceso a datos/servicios reales. El ledger canónico mantiene8/23 tareas cerradas y T12 abierta.
