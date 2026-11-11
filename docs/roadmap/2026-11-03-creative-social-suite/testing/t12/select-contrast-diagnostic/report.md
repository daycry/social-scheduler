# T12: diagnóstico de contraste de controles y datos

Cuatro estados nativos completados, sin fallos del diagnóstico. La aplicación completa sigue pendiente: errors2 conserva1casofallido/2noejecutados y0violations/1incomplete con16nodos. No se omiten reglas en E2E.

La fixture reconstruida copia dos selects HTML completos, doce celdas y unstatus del artefacto original, junto con CSS compilado build4 y axe instalado. El primerselect original tenía HTML truncado y no se reconstruyó. Diálogo, scroll y separador650px son reconstrucciones explícitas, no DOM original. De las doce celdas, nueve reproducen la condición fuera del rectángulo del fondo del diálogo.

| Estado | Imagen de fondo | Celdas incompletas | Status incompleto | Total |
|---|---:|---:|---:|---:|
| Original | 2 | 9 | 1 | 12 |
| Select nativo | 0 | 9 | 1 | 10 |
| Sección de datos blanca | 2 | 0 | 0 | 2 |
| Ambos cambios | 0 | 0 | 0 | 0 |

La imagen de fondo de los selects impide resolver su contraste. El fondo explícito de la sección que agrupa tabla ystatus evita depender del rectángulo visible del diálogo. Ambos candidatos mantienen colores, texto, opciones y fuentes. Añadir blanco a la sección conserva sus dimensiones y posición para cada variante de select; es el mismo blanco heredado del diálogo. La apariencia nativa aumenta cada control de38a39px y desplaza2px el grupo posterior. No se afirma igualdad geométrica entre esas dos apariencias.

Home/ArrowDown/End y Tab/ShiftTab funcionan en los controles cerrados y el foco es visible. No se infiere comportamiento del popup nativo del sistema a partir de esas mediciones. Root inspeccionó las ocho capturas reales; el observador de píxeles leyó cuatro imágenes sin editarlas. Los JSON mantienen métricas y limitaciones.

Job agregado3GiB COMMIT, preflight6GiB, piso3GiB cada100ms y plazo90s; duración1,7642s, pico274415616B y mínimo físico9118531584B. Sin red, Laravel, PHP, DB, build o.env.23fuentes idénticas antes/después y en comprobación root. Supervisor30560, nodo24808 y observador21840 terminales;8080IPv4/IPv6libres. Los estados y originales no se relanzan ni reetiquetan.

Siguiente: aplicar sólo las contramedidas probadas, revisar, compilar una versión nueva y ejecutar E2E28.03 íntegro otra vez en salida propia. Esta sonda usa únicamente la regla color-contrast para aislar las causas; no acredita axe completo, la aplicación, T12 ni el roadmap.
