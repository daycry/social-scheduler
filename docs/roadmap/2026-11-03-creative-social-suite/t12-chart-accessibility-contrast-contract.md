# T12: contraste de controles y datos del gráfico

Estado: F4 candidato aplicado y revisado A1/B1, build5 terminado sin deriva; regresión de aplicación3 pendiente. Errors2 pasó tabla/tipos/lexemas/negativos y falló en el primeraxe:0violations y16nodos de contraste incompleto. Tresselects tienen imagen de fondo; doceceldas y unstatus dependen del fondo del diálogo y quedan fuera de su rectángulo visible. No se declara que sus colores tengan contraste insuficiente. [Contraprueba nativa4estados](testing/t12/select-contrast-diagnostic/report.md): fixture reconstruida12incompletos→10sóloselect→2sólosección→0ambos. Native select38→39px; se conserva la geometría de sección al cambiar únicamente su fondo, no entre apariencias de control.

## Hipótesis y contraprueba

Una sonda local compacta contrastará CSS compilado actual y controles HTML de los artefactos. Se probará quitar sólo la flecha de background-image y recuperar appearance:auto del select, sin cambiar valores, opciones, colores o fuentes. Las celdas se investigarán aparte: tabla mínima explícitamente reconstruida con su contexto de scroll y textos originales, comparando fondo heredado con blanco explícito, que es el color actual del diálogo.

Cuatro estados deseados: baseline, sóloselect, sólotabla, ambos. Se registran resultados reales de la regla color-contrast, estilos calculados, dimensiones, teclado y capturas. El escenario reconstruido es diagnóstico, nunca DOM original o aceptación de la aplicación. Si no reproduce una causa se declara parcial y se conserva la hipótesis pendiente.

Sin.env/DB/Laravel/build/proveedores/redexterna; Node512MiB y un único slot. Job3GiB COMMIT, preflight6GiB, piso3GiB cada100ms,90s; cierre y hashes actuales. Root ejecuta sólo después de READY/revisión del preparador, sin modificaciones posteriores a freeze.

## Criterios del producto después de F3

F4 candidato aplicado: sólo select.field scoped con appearance:auto/-webkit-appearance:auto/background-image:none ybg-white en la sección existente que agrupa tabla/nav/status. Script intacto; Vue/template/style compilan sin errores. Revisión A1/B1 limpia y build5 real sin deriva:62inputs/77assets,1,8361s, picoCOMMIT1192218624B bajo3GiB. La regresión nativa completa3 todavía está pendiente. El aumento de1px por select de apariencia nativa está medido y no se oculta.

La apariencia conserva un control select nativo operable por teclado, foco, texto y opciones. Las tablas mantienen todos los tipos y lexemas, paginación, scroll y contenido accesible. El fondo efectivo y el contraste se comprueban; no se oculta contenido para pasar el comprobador.

La regresión permanente es E2E28.03 completo, sin filtros ni exclusión de reglas o de incomplete. El resultado fallido anterior constituye el rojo previo a CSS; no se añade un test que sólo copie declaraciones de estilo. Tras una contramedida probada: cambio mínimo, revisión fresca, nuevo build y ejecución propia con presupuesto idéntico e informes inmutables. No se reetiqueta errors1/2, no se cierra T12 y no se transforma una prueba aislada de contraste en aprobación global.
