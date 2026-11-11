# T12 — entrega del núcleo geométrico G1–G4

Candidato implementado y pendiente de revisión independiente. Sólo dos fuentes nuevas asignadas. No incorpora gráficos al documento público, controles, Fabric, fuentes, SVG/PNG/PDF, animación ni exportaciones; no cierra T12 ni una configuración usable del inventario.

| Criterio | Implementación y evidencia |
|---|---|
| G1 configuración cerrada | Opciones propias por descriptores, getters no invocados, listas densas de colores, números/enum/límites y defaults inmutables. RED/GREEN 01–04. |
| G2 variantes y geometría | Diez variantes reales, orientaciones, colores por categoría/serie, orden e identidad, cero/null distintos, ranuras agrupadas conservadas y acumulados positivos/negativos separados. RED/GREEN 05–11. |
| G2 dominio y precisión | Rango explícito sin recorte silencioso, dominios no degenerados, extremos opuestos finitos, overflow con IDs, ticks únicos con subnormales y avisos de barras no nulas reducidas a cero. RED/GREEN 12–15. |
| G2 aclaración proporcional | Restricción confirmada por root y añadida al contrato antes del fix: rechazar underflow no nulo y negativos por signo/coefficient del raw; -0.0 y -0e-999 verdaderamente nulos siguen válidos. Dos RED/GREEN independientes 21–22, sin modificar C1. |
| G2 borde fraccionario | Sonda descubrió 40.010000000000005 como borde final de seis barras en 40.01 px. RED/GREEN 25 añade contención del redondeo geométrico, sin cambiar datos ni permitir clipping de un dominio inválido. |
| G3 resultado | Referencia, categorías, series, ticks, baseline y barras con IDs/raw/start/end/porcentaje, más avisos de C1 y del layout. Grafo propio profundamente congelado, sin retener entradas mutables. RED/GREEN 16. |
| G4 presupuesto | Caracterizaciones ya verdes: 12.000 barras únicas con 1000 categorías × 12 series; validación heredada; matriz de diez variantes, tamaños fraccionarios y gap cero. Tres caracterizaciones, sin inventar RED. |

Suite vigente `26-suite-final.json`: **69/69 PASS**, 22 propios + 17 binding + 30 core; cero fallos, skips o cancelaciones. TAP 945,8636 ms. Node v22.23.2 con old-space512MiB; no equivale a RSS ni Job de SO. Cuatro fuentes/test aceptados de binding/core conservan sus SHA iniciales.

Son **19 RED genuinos y sus GREEN posteriores**, con título, comando, error y fecha en manifest.json; stdout/JSON completos conservados. El primer test de cada criterio falló por comportamiento, no por importación o sintaxis. Las suites previas 66/68 y la sonda numérica se conservan como históricos, no se llaman resultados vigentes. El refactor posterior sólo evita recalcular modos y coeficientes dentro de bucles; su suite68 queda preservada antes del último fix de borde.

Scope-check sobre la iniciativa: exit0, sin fuera de alcance, avisos, info o exclusiones de usuario. Rama feature/creative-social-suite; sin dev.json, Jira o constitución. Consulta de conocimiento ejecutada sin restricciones aplicables devueltas. Root conserva el ledger y copiará la evidencia de los RED; no se escribió documentación o ledger desde este agente.

Archivos en UTF8/LF, cero U+FFFD. Sin ejecución nativa, navegador, HTTP, aplicación, PHP, BD, entorno real, dependencias nuevas, red, build, PDF o commits. La función valida la tabla mediante C1 una vez por creación; la integración deberá cachear la revisión y la geometría estáticas antes de animaciones. Permisos por marca, render compartido y aceptación visual son trabajo posterior.
