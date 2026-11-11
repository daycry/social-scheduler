# Geometría G1–G4 — corrección B1, intento 2

**B1 Important corregido; pendiente de revisión fresca del delta.** Los demás criterios aprobados en el intento 1 se conservan, sin cambios de requisitos.

El caso exacto de revisión (`bar-proportional`, `[129,null,533]`, ancho3728.5800000000004) produjo RED: la suma x+width excedía el área una ULP. El RED definitivo es `01-endpoint-red-final.json` y su TAP; el primer RED de la misma regresión también se conserva como histórico, sin contarlo dos veces.

Se mantiene la cota inicial min(requested,limit-start), pero se comprueba su suma final. Sólo si aún excede el límite se reduce el tamaño positivo al número representable inmediatamente inferior mediante sus bits IEEE754. Es una corrección constante, sin bucles, tolerancias en la contención, cambios de dominio o alteración del valor/raw/porcentaje. Se aplica simétricamente a width y height; las posiciones x/y siguen siendo las anteriores.

GREEN focal en `02-endpoint-green.json`. Suite final `03-suite-final.json`: **70/70 PASS**,23 propios+17binding+30core, cero fallos/skips/cancelaciones; TAP1061,3017ms. Cuatro fuentes/test aceptados de binding/core intactos. Node old-space512MiB no es una cota RSS.

Probe independiente `independent-probe.mjs` compara el módulo anterior congelado y el actual: **840 gráficos/5.600 barras**,28 pares de tamaños fraccionarios, tres cantidades de categorías y diez variantes. El código anterior presenta11 barras fuera por X; el actual presenta0 fuera de ambos ejes. También se comprueban todos los límites Y, sin atribuirles un fallo previo inexistente en ese barrido. Datos, IDs, raw, dominios, porcentajes, baseline, ticks, categorías y avisos coinciden exactamente con el módulo anterior. La regresión exacta reduce sólo un paso representable, contrastado con un oráculo BigUint64 independiente. Resultado235–237ms de cálculo puro, no latencia de aplicación.

Los144artefactos originales del intento1 permanecen con sus hashes, incluidos sus manifiestos y copias de fuentes. Nueva evidencia sólo en attempt2/. No se tocaron documentos, ledger, tareas ajenas, interfaz, build o assets. No hubo ejecución nativa, PHP, HTTP, aplicación, BD o dependencias. Este módulo aún no implica gráficos usables, render/export o cierre de T12.
