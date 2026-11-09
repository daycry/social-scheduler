# T12 — geometría de barras y columnas G1–G4

Aceptado únicamente el núcleo geométrico interno. **A2/B2 sin gaps pendientes; C/D no aplican.** No integra gráficos en documento, controles, render o exportación. Ninguna de las 32 configuraciones del inventario se declara usable por esta pieza; T12 sigue abierta y el total permanece 8/23.

Las diez variantes generan barras reales con orientación, agrupación, acumulados separados por signo, proporciones estables y color por serie/categoría. Conserva referencia/revisión/SHA, IDs, valores/raw, null y avisos de precisión de B1–B3. Rechaza tipos ambiguos, getters, clipping de dominio, overflow y porcentajes de un valor original no representable; escala extremos finitos sin Infinity y mantiene ticks únicos/cero visible. Se probaron 12.000 barras.

## Revisión y corrección

El intento1 pasó 69 pruebas pero B encontró un borde fuera del área por una ULP. Root lo reprodujo: bar-proportional, [129,null,533], width3728.5800000000004, suma x+width3728.580000000001. No había efecto visual apreciable; incumplía el contrato estricto y se corrigió con RED/GREEN sin introducir tolerancias.

El intento2 reduce el tamaño al representable inmediatamente inferior sólo cuando la suma final excede el límite, en ambos ejes. Coordenadas iniciales, datos/raw/dominio/porcentajes y demás semántica permanecen intactos. El módulo añade trabajo constante, sin bucles por corrección. La tabla completa de criterios/veredictos está en el [ledger](../../../tasks.md).

A2 verificó los 144 artefactos originales y 26 nuevos, el contrato, seis fuentes y las copias inmutables; focal1/1 PASS. B2 contrastó con oráculo BigUint64 independiente: 900 geometrías/29.700 barras, 96 excesos anteriores y cero actuales, exactamente 96 tamaños reducidos una ULP, resto de la semántica idéntica. Sonda del helper: 518.567 casos PASS y cuatro casos explícitos de préstamo entre palabras uint32. Se preservan todos los criterios anteriores excepto la contención que queda corregida.

## Verificación raíz

**70/70 PASS** (23 propios,17 binding,30 core), cero fallos/skips/cancelaciones; 1.071,7522 ms TAP, exit0 y seis SHA intactos. Node old-space512MiB no es una cota RSS. Diecinueve RED genuinos iniciales y una regresión RED/GREEN posterior; tres caracterizaciones declaradas. Se conservan todas las suites y fallos históricos, sin reinterpretarlos.

Sin nativos, UI, Fabric, HTTP, BD, dependencias nuevas, PNG/SVG/PDF o animación en esta verificación. La integración deberá cachear la revisión y geometría antes de fotogramas, comprobar permisos de marca y usar el mismo render en preview/export. No se ha ejecutado la auditoría final de Claude CLI ni se ha hecho entrega git.
