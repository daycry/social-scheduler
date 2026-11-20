# T-76 cerrada: mejor hora en compositor y calendario

Cerrada e integrada el **18 de noviembre de 2026**, tras revisión real de **Claude Code Opus 5.5** con aceptación, corrección, seguridad, rendimiento y **calidad del código**. R3 confirma cero Critical/Important abiertos; su último Minor de precisión en las pruebas se corrigió literalmente y se verificó. Recibo y veredictos íntegros en reviews/.

- PHP completo: **4320 passed, 9 skipped, 29025 assertions**. Solo precede al refuerzo final de aserciones de texto exacto; producción idéntica.
- SQLite y MySQL 8.4.3 finales: **84 passed, 731 assertions** por motor. Seis casos fallan al restaurar temporalmente la frase antigua y vuelven a verde tras restaurar el original.
- Node: **789/789**; TypeScript, Pint, ESLint, Prettier y build verdes. El límite de tipado de los scripts heredados del compositor sigue declarado.
- Navegador final: **18 passed, 0 skipped/failed/flaky**, qa-gate VERDE. Verifica Europe/Madrid, calendario a compositor, selección explícita de red, datos insuficientes, enlace caducado, texto exacto del aviso y limpiar la fecha tras la propuesta. Antes de los ajustes menores de aviso/estado, la regresión ampliada fue **120/120**, sin omisiones/fallos/flaky.
- **1766 huellas** comprobadas después del navegador y contra el checkout integrado. **23 archivos** coinciden byte por byte con el candidato; el parche revierte limpiamente.

Se corrigieron el bucle de redirección de enlaces caducados, ValueError por NUL, agrupación condicional del calendario, fecha modificada/restaurada durante una petición y estado de éxito obsoleto. Tres mutantes discriminantes verifican el orden media, muestras y cercanía. El revisor aceptó que un margen mínimo de edición es política opcional fuera del contrato de fecha estrictamente futura; no se trata como deuda aceptada. V1 quedó descartado con evidencia real de zona horaria y fallback.

El último cambio tras R3 solo refuerza los tests HTTP/E2E con el texto literal indicado por Claude; ningún código de producción cambió. La revisión fue estática: los resultados de ejecución son del ejecutor. verification.json conserva recuentos, SHA-256 y límites; source-hashes.json identifica la versión final, y el manifiesto adicional identifica el run completo anterior al refuerzo de aserciones. Sin commit, push, proveedores reales ni datos privados.
