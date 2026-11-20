# T-75 cerrada: registro de actividad visible

Cerrada e integrada el **18 de noviembre de 2026**, después de revisión real de **Claude Code Opus 5.5**, correcciones y verificación. El revisor final declara cero defectos Critical/Important y **calidad del código aceptable sin condiciones**. Su única precisión documental adicional se aplicó literalmente; no queda abierta.

- Pest completo: **4294 passed, 9 skipped, 28843 assertions**.
- MySQL 8.4.3 y SQLite focales: **32 pruebas, 241 aserciones**.
- Node completo: **778/778**; tipado estricto, Pint, ESLint, Prettier y build verdes.
- Navegador: **13 passed, 2 skipped, 0 failed, 0 flaky**, qa-gate VERDE. Las dos omisiones son el sidebar de escritorio en móviles.
- Se verificaron las huellas después del navegador. Los 1728 archivos de ejecución/configuración siguen idénticos; solo cambió una frase de la guía conforme al texto propuesto por el revisor. Los 21 archivos integrados coinciden byte por byte con el candidato final y el parche revierte limpiamente.

Se corrigió la inferencia insegura de marca, se mantuvo personal todo el historial anterior, se ligó el contexto de API a cada Request mediante WeakMap y se unificó la atribución de errores. El encabezado corrupto se reprodujo con un test de navegador antes de corregirlo. Las pruebas históricas ya no ejecutan DDL dentro de transacciones.

MySQL desechable verificó rollback/reaplicación, índices y FK. EXPLAIN ANALYZE con 50 registros visibles entre 60.000 lee dos rangos de 50 entradas y deduplica a 50 filas; no barre toda la tabla. Los tiempos locales no constituyen una garantía de capacidad.

Los tres veredictos y el seguimiento previo están en reviews/. verification.json contiene rutas, recuentos, SHA-256 de recibos/reportes, límites y la precisión documental posterior al QA. source-hashes.json identifica el candidato integrado. preflight.md conserva la historia de preparación. No se hizo commit, push ni se usaron proveedores o datos reales.
