# T12 — referencia y proyección de datos de gráficos

Aceptado únicamente el núcleo interno B1–B3. **A1/B1 sin gaps; C/D no aplican** según selector determinista de las líneas añadidas. No incorpora geometría, controles ni acceso público por marca; ninguna de las 32 configuraciones se declara usable por esta entrega. T12 sigue abierta, 8/23 tareas cerradas.

El módulo fija id/revisión/SHA y columnas por ID, conserva categorías y lexemas, mantiene null como ausencia y exige conversión explícita de texto. Safe rechaza magnitudes grandes o underflow a cero; approximate requiere elección y emite advertencias por celda. No muta tablas ni introduce I/O o dependencias.

## Revisión independiente

Se leyó íntegramente el delta de 132 líneas de módulo y 220 de test. CB y TEST en la tabla se refieren a resources/js/Studio/chart-binding.js y tests/frontend/studio-chart-binding.test.mjs.

| Criterio B1–B3 | Veredicto | Evidencia en chart-binding.js/test |
|---|---|---|
| Referencia cerrada, enteros positivos seguros y SHA minúsculo | ✓ | CB37–46; TEST21–30 |
| Coincidencia de identidad/revisión/SHA antes de utilizar celdas | ✓ | CB96–101; TEST98–106 |
| IDs válidos y 1–12 series distintas de categoría | ✓ | CB5–6/43–52; TEST33–42 |
| Selección por ID existente, independiente de etiquetas | ✓ | CB102–108; TEST109–113/196–202 |
| Campos propios cerrados, sin getters, herencia o huecos | ✓ | CB9–35/54–61; TEST45–71/124–137 |
| Fuente intacta y referencia propia inmutable | ✓ | CB41/51–52/131; TEST11–18/163–172 |
| Categorías Unicode/texto/número/null con identidad y lexema | ✓ | CB109–113; TEST74–95 |
| Series tipadas/null, negativos/cero y huecos conservados | ✓ | CB118–128; TEST78–94 |
| Texto exige conversión; diagnóstico fila/columna/IDs | ✓ | CB121; TEST116–120 |
| Safe predeterminado y comparación decimal exacta con exponentes | ✓ | CB45–46/64–86/124–125; TEST140–145/176–181 |
| Underflow no nulo exige aproximación, advertencias distintas | ✓ | CB122–126; TEST148–160/205–218 |
| Orden/alineación, tabla vacía y snapshot cerrado | ✓ | CB98/109–130; TEST84–106/196–202 |
| Límites 1000×100, 12 series/12.000 valores | ✓ | CB48/54–61/115–130; TEST124–137/185–193 |
| API pura y datos propios profundamente inmutables | ✓ | CB1–132/89–95/131; TEST163–172 |
| TDD y verificación trazable | ✓ | 14 RED, suite47/47 y root47/47; raw |
| Alcance/estado declarados e integridad | ✓ | Dos fuentes declaradas, cuatro SHA+contrato estables, diff completo coincidente |

B contrastó 5.173 lexemas contra un oráculo racional BigInt y diez casos de getters: cero discrepancias, cero getters invocados. Cuatro fuentes y contrato intactos. No repitió suite ni utilizó aplicaciones, red o procesos nativos.

## Verificación raíz

47/47 PASS (17 propios y 30 del core), cero fallos/cancelaciones/skips, 764,3532 ms TAP; exit0 y cuatro SHA intactos. Node old-space512MiB no es una cota RSS. Catorce RED genuinos y tres caracterizaciones declaradas; se conservan errores históricos de comando/redacción y la suite anterior a underflow. Root verificó los 104 hashes de artefactos de implementación antes de copiar.

Sin validación de UI, geometría, permisos integrados, undo/reapertura o exportación de gráficos. Esas puertas y el resto del roadmap permanecen pendientes. Sin commits, publicaciones o pruebas externas.
