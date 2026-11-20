# T-76: verificación previa al cierre

Candidato aislado. La dependencia T-75, la regresión ampliada de navegador y la revisión real de Claude Opus 5.5 siguen pendientes. No se ha integrado ni cerrado.

- Node: **785/785**; siete casos nuevos ejercitan el composable con Vue real, incluido fetch que ignora AbortSignal.
- Pest focal: **45 passed, 392 assertions** tanto en SQLite como en MySQL 8.4.3 aislado.
- TypeScript estricto y build de producción verdes. El componente y el composable nuevos quedan incluidos por la importación de Calendar; los descriptores de props del compositor siguen en el alcance estricto establecido por T-68.
- Navegador E2E-16: **9 passed, 0 skipped, 0 failed, 0 flaky**, qa-gate VERDE. Compositor con datos, compositor sin datos conservando fecha manual y calendario a nuevo compositor sin guardar, en escritorio, 375 y 360 px. Axe y desbordamiento comprobados.
- El primer recorrido integrado reprodujo tres fallos de calendario: identificador de filtro textual frente al identificador numérico de la marca. Se corrigió la comparación; no se relajó la aserción.
- DST: se reprodujo antes del fix la normalización de una hora inexistente y la selección de una hora repetida. Un helper compartido solo admite horas locales que representan un único instante; servicio y validación del enlace usan el mismo criterio.
- Una propuesta no reemplaza una fecha editada durante la petición, ni se aplica a otra marca/red o tras desmontar. Una petición anterior tampoco elimina el indicador ocupado de la nueva.

Los resultados y rutas exactas figuran en verification.json. source-hashes.json identifica el árbol ejecutado. El revisor deberá entregar también un veredicto explícito de calidad del código, además de aceptación, corrección, seguridad y rendimiento. Ninguna ejecución de pruebas sustituye ese veredicto.

## Regresión PHP final

La suite completa pasó **4281 pruebas, 9 omisiones, 28.685 aserciones** (%TEMP%/metricool-t76-r25-pest-full.log). La primera ejecución no fue verde: a la copia le faltaban scripts de deploy, plantillas de entorno y el contrato OpenAPI que leen las pruebas. Se completó la copia con archivos versionables, sin copiar ningún .env privado ni modificar pruebas. La repetición completa sí pasó. Node volvió a pasar 785/785 tras el último cambio del calendario y ESLint focal está verde.

El navegador ampliado ejecuta ahora E2E-16, composer, composer-refresh, composer-presets, calendar-views y natural-schedule, en una sola invocación serial sobre 1758 archivos congelados. No se atribuye verde a esa ejecución hasta su terminación.

## Navegador ampliado terminado — 2026-11-18

**108 passed, 0 skipped, 0 failed, 0 flaky**, qa-gate VERDE, 11,6 minutos. Se comprobaron después los SHA-256 de los **1758 archivos**: ninguna diferencia. Incluye preferencias, crear/editar/guardar, confirmación al salir, programación con IA simulada, notas, accesibilidad y vistas de calendario, además de Mejor hora. Continúan pendientes la revisión real de Claude y la integración con T-75.
