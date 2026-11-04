---
estado: en-progreso
creado: 2026-11-03
actualizado: 2026-11-04
spec: spec.md
plan: improvement-plan.md
verificacion: obligatoria
---

# Ledger canónico — suite social y creativa

Autorizado por la petición de integrar clones funcionales de Metricool/Canva, incluidas funciones de pago, trabajando de forma independiente y teniendo en cuenta APIs/MCP. Las verificaciones reales previas que corresponden al usuario no se dan por completadas.

## Resumen de progreso

| Fase | Completadas | Total | Progreso |
|---|---:|---:|---:|
| Fase 1 | 1 | 3 | 33 % |
| Fase 2 | 6 | 6 | 100 % |
| Fase 3 | 0 | 7 | 0 % |
| Fase 4 | 0 | 5 | 0 % |
| Fase 5 | 0 | 2 | 0 % |
| **Total** | **7** | **23** | **30 %** |

## Fase 1 — Investigación y consistencia

### T-01 — Inventario y evidencia de referencia
- **Estado**: en-progreso
- **Archivos**: `docs/roadmap/2026-11-03-creative-social-suite/**`
- **Verificación**: REA public Canva `ev_2837561e17e0c1d8f941b44c65501af862c5e255f6ae25ec79446deb3b38b632` observa barrera Cloudflare 403; no editor. Edge autenticado observado por UI Automation, separado de REA, sin modificar diseños. Inventario de repo por tres agentes de lectura. Fuentes oficiales en inventario.

**Criterios de aceptación**

- [ ] Matrices completas con evidencia, cobertura, límites y funciones premium identificadas.

### T-02 — Contratos y arquitectura UI/API/MCP
- **Estado**: completado
- **Changelog**: Define los contratos versionados del documento y API con propiedad, revisiones y estados de exportación compartidos.
- **Archivos**: `docs/roadmap/2026-11-03-creative-social-suite/**`, `docs/api/creative-suite.openapi.yaml`
- **Verificación**: validar estructura OpenAPI y consistencia del esquema de documento; diseñar permisos, propiedad, revisiones y trabajos.

**Criterios de aceptación**

- [x] Contratos explícitos antes de endpoints; servicios compartidos.

### T-03 — Auditoría y corrección de métricas
- **Estado**: en-progreso
- **Cubre (tests)**: E2E-01, M-01
- **Archivos**: `app/Analytics/**`, `app/Services/AnalyticsService.php`, `app/Services/AggregateAnalytics.php`, `app/Services/CampaignAnalytics.php`, `app/Connectors/**`, `app/Jobs/CollectAccountSnapshot.php`, `app/Jobs/RefreshPostMetrics.php`, `app/Jobs/BackfillMetrics.php`, `app/Jobs/CollectSnapshots.php`, `resources/js/Pages/Dashboard/**`, `resources/js/Components/KpiCard.vue`, `resources/js/Components/MetricQuality.vue`, `tests/**`
- **Verificación**: RED/GREEN por defecto confirmado, contratos de proveedor, cobertura y frescura; contraste de lectura real cuando disponible, sin inventar métricas.

**Criterios de aceptación**

- [ ] Tasas, flujos/stocks, nulos, fechas, paginación y presentación auditados.
- **Evidencia parcial**: RED 4/4 cálculos (`ced8e9`); GREEN correcciones 4/4. Cobertura RED 3/3 → GREEN 4/4, 107 aserciones. Regresión analytics 91/91, 532 aserciones (`f45c53`); web/MCP 26/26, 398. UI calidad RED 3/3 (`823aa4`) → GREEN 3/3 (`45d27d`). Informe de contraste real en `testing/metrics/audit-2026-11-03.md`; ningún write de snapshots reales. Revisión y QA local correctas; siguen abiertos el contraste integral por proveedor y la operación de recolección del VPS.
- **RED:** `tests/Feature/Backfill/BackfillTest.php::a continuation across midnight keeps the original closed-day range` omitía `2026-10-27`: cuatro snapshots en lugar de los cinco solicitados cuando una continuación cruzaba medianoche · 2026-11-04 (`02bfb7`). GREEN `32f74c`: **33/33, 110 aserciones** de Backfill/Snapshots tras conservar `throughDate` al despachar continuaciones. La lectura del nuevo atributo tolera payloads antiguos sin él. Esta corrección no diagnostica ni modifica la cola del VPS; revisión independiente pendiente junto a T-09.

## Fase 2 — Estudio gráfico por marca

### T-04 — Diseños, recursos, kits, carpetas y revisiones
- **Estado**: completado
- **Changelog**: Añade diseños, carpetas, kits, recursos y revisiones por marca con validación tipada y conflicto optimista.
- **Cubre (tests)**: E2E-02, E2E-04
- **Verificación**: php vendor/bin/pest tests/Feature/Studio/StudioBackendTest.php; ownership, revisiones, kits, recursos y carpetas; QA E2E-02 y revisión independiente antes del cierre.
- **Archivos**: `database/migrations/*studio*`, `app/Models/Studio/**`, `app/Services/Studio/**`, `app/Http/Controllers/Studio/**`, `routes/studio.php`, `tests/Feature/Studio/**`, `tests/Unit/Studio/**`

**Criterios de aceptación**

- [x] CRUD, aislamiento, documentos validados, kits y control optimista con tests.
- **Evidencia parcial**: RED rutas 404 (`98d960`), espacios tipográficos (`c085d8`), nulos inválidos (`e89691`) → GREEN 26 tests / 119 aserciones (`31270c`), Pint. JSON Studio excluido de trim/empty-to-null para conservar texto. Revisión y QA final acreditadas al pie del ledger.

### T-05 — Motor de edición y documento propio
- **Estado**: completado
- **Changelog**: Añade el motor gráfico, grupos, capas, historial, páginas y catorce plantillas originales con serialización propia.
- **Cubre (tests)**: E2E-03
- **Verificación**: node --test --experimental-test-isolation=none tests/frontend/studio-document.test.mjs tests/frontend/studio-geometry.test.mjs tests/frontend/studio-history.test.mjs tests/frontend/studio-loading.test.mjs tests/frontend/studio-templates.test.mjs; Chromium real para líneas y exportación; QA E2E-03.
- **Archivos**: `resources/js/Studio/**`, `tests/frontend/studio*.mjs`, `package.json`, `package-lock.json`

**Criterios de aceptación**

- [x] Texto/objetos/grupos/capas, transformaciones, bloqueo, filtros, recorte, undo/redo, páginas y serialización probados.
- **Evidencia parcial**: RED documento/historial/plantillas y geometría; gradientes no cuadrados (`aaa8cd`), cargas bloqueadas (`e60884`), vídeo recortado (`896d3e`) → GREEN 17/17 Node (`94a30a`), con Fabric real para matrices/grupos. Export PDF rasterizado, SVG con medios embebidos; ningún MP4. QA de navegador final acreditada al pie del ledger.

### T-06 — Experiencia del estudio y navegación
- **Estado**: completado
- **Changelog**: Incorpora catálogo y editor responsive por marca con guardado automático, recuperación de conflictos y herramientas accesibles.
- **Cubre (tests)**: E2E-02, E2E-03, E2E-04
- **Verificación**: npm run build; npx tsc --noEmit; npx playwright test tests/e2e/studio.spec.ts en desktop/mobile/mobile-360; capturas y qa-gate VERDE.
- **Archivos**: `resources/js/Pages/Studio/**`, `resources/js/Components/Studio/**`, `resources/js/navigation.js`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Components/Icon.vue`, `resources/css/**`, `routes/web.php`, `tests/e2e/studio*.spec.ts`

**Criterios de aceptación**

- [x] Catálogo, editor moderno responsive, guardado, recuperación/conflicto y selector de marca correctos.
- **Evidencia parcial**: RED E2E heading Estudio ausente (`cb23e5`, run `run-general-53a730b3-2dc6-44c9-bf9b-5996bba54bd5`). Catálogo, navegación y editor integrados. GREEN integrado final: 18/18 del bloque; ver informe de QA.

### T-07 — Exportación real y uso en publicaciones
- **Estado**: completado
- **Changelog**: Exporta PNG, JPEG, WebP, SVG y PDF reales y reutiliza imágenes validadas en borradores de su marca.
- **Cubre (tests)**: E2E-02, E2E-03, E2E-05, M-03
- **Verificación**: php vendor/bin/pest tests/Feature/Studio/StudioRendererTest.php; node --test --experimental-test-isolation=none tests/frontend/studio-renderer.browser.test.mjs; E2E-02/03/05 con archivos, subida y registro reales.
- **Archivos**: `app/Services/Studio/**`, `app/Jobs/Studio/**`, `resources/js/Studio/**`, `resources/js/Pages/Studio/**`, `resources/js/Components/Composer/**`, `app/Http/Controllers/Studio/**`, `scripts/studio/**`, `config/studio.php`, `tests/**`

**Criterios de aceptación**

- [x] PNG/JPEG/WebP/SVG/PDF reales, páginas ordenadas, revisión trazable, archivos válidos y borrador asociado.

### T-08 — API pública y herramientas MCP del estudio y analítica
- **Estado**: completado
- **Changelog**: Expone API v1 y nueve herramientas MCP del estudio con permisos, gestión de carpetas, render servidor e historiales paginados.
- **Cubre (tests)**: E2E-02, E2E-03, E2E-04, E2E-05
- **Verificación**: php vendor/bin/pest tests/Feature/Studio/StudioApiMcpTest.php; linter OpenAPI, catálogo completo paginado, permisos por acción, historia paginada y ausencia de N+1; revisión A/B/C/D.
- **Archivos**: `routes/api.php`, `routes/studio-api.php`, `bootstrap/app.php`, `app/Mcp/**`, `app/Http/Middleware/**`, `app/Http/Controllers/Studio/**`, `app/Http/Controllers/AnalyticsApiController.php`, `docs/api/**`, `tests/Feature/Mcp/**`, `tests/Feature/Studio/**`

**Criterios de aceptación**

- [x] Paridad de servicios, permisos, descubrimiento, paginación, trabajos y errores.
- **Evidencia parcial**: OpenAPI válido, cero errores y tres avisos opcionales. RED bearer API 404 en lugar de 401 (`bbefa8`), MCP sin `list_designs` (`7741c1`). Implementación y renderer real verificados en revisión y QA final.

### T-09 — Plantillas y automatización de kits por marca
- **Estado**: completado
- **Changelog**: Añade plantillas propias con campos literales, aplicación trazable del kit, redimensionado proporcional y variantes independientes por web, API y MCP.
- **Verificación**: Pest StudioAutomation/Studio/MCP, OpenAPI lint/diff, Node/Chromium, build, TypeScript y Pint; E2E-07/08 de plantilla, logo real, kit/undo/redo/resize/variantes/reapertura/exportación dentro de los 24 recorridos integrados, con QA independiente VERDE.
- **Archivos**: `app/Services/Studio/**`, `app/Models/Studio/**`, `app/Http/Controllers/Studio/**`, `app/Mcp/**`, `routes/studio*.php`, `database/migrations/*studio*`, `bootstrap/app.php`, `docs/api/creative-suite.openapi.yaml`, `resources/js/Studio/**`, `resources/js/Pages/Studio/**`, `resources/js/Components/Studio/**`, `tests/**`
- **Cubre (tests)**: E2E-07, E2E-08

**Criterios de aceptación**

- [x] Plantillas propias originales, copia independiente, sustitución de campos, resize y variantes.
- **RED:** `tests/e2e/studio-automation.spec.ts::own template substitutes literal fields` falla al buscar el botón inexistente `Guardar como plantilla`, tras crear/editar/guardar correctamente el original · 2026-11-04. Salida `52af25`, ejecución aislada `run-general-1c257688-582c-4f6c-9e1b-9cf9a61a3c3a`; no se implementó UI antes de este fallo. El primer intento `9b6ca1` no pudo lanzar el proceso (`spawn EPERM`) y no cuenta como RED funcional.
- **Contrato acordado antes de implementación:** reutilizar `Design.is_template`; guardar plantilla genera una copia, instancia sustituye `{{campo}}` literalmente, sin sustitución recursiva. Redimensionado `canvas|contain|cover`, identidad de marca y variantes con revisión de origen obligatoria y servicios comunes web/API/MCP. Los campos conservan espacios y cadenas vacías; se validan recursos/propiedad y se generan copias transaccionales con IDs independientes. Contrato OpenAPI ampliado y validado sin cambios rompedores (`ffa418`). RED backend de rutas ausentes `39ce11` / `319b84`; detalle por criterio se registra al integrar el informe del implementador.
- **RED/GREEN de integración backend:** guardar plantilla 404 frente a 201 (`39ce11`), instanciar/redimensionar/aplicar kit/variantes 404 frente a 201/200 (`319b84`) → GREEN 5/5, 50 aserciones (`10fb58`). MCP acciones inexistentes (`cc840b`) → GREEN (`9856e0`). Duplicar tras cambiar kit perdía referencia: RED esperaba rojo del nuevo kit y mantenía violeta antiguo (`33f119`) → GREEN 13/13, 101 aserciones (`83c652`). Regresión Studio+MCP **355/355, 2290 aserciones** (`45d895`, `testing/t09/raw/phpunit-studio-mcp.xml`), con almacenamiento desechable propio.
- **RED UI logos:** el diálogo real carecía del grupo `Logotipos de marca`, expect element not found (`acd520`) → selector de imágenes registradas implementado tras el fallo. REDs de navegador conservados en `testing/t09/raw/red-ui` y `red-logos`. Primera integración de escritorio pasa plantillas y detecta un error del propio test de variantes (buscaba dimensiones en nivel superior en vez de `document`, `20f918`); corregido el test contra el contrato, sin cambiar el producto por ese resultado. QA final pendiente.
- **Despliegue:** nueva migración nullable de `kit_reference` en diseños/revisiones. Intento local `a1f963` no pudo conectar con MySQL loopback y no acredita aplicación de la migración. Ninguna modificación del VPS ni de `.env`; el runner y PHPUnit aplican la migración real a sus bases desechables.

## Fase 3 — Capacidades creativas avanzadas

### T-10 — Tipografías, archivos, búsqueda y edición avanzada de imágenes
- **Estado**: borrador
- **Verificación**: Pruebas de fuentes cargadas realmente, aislamiento de recursos y comparación de imagen antes/después para filtros, máscara y quitar fondo. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Recursos por marca, fuentes cargadas realmente, recorte/máscara, filtros y quitar fondo con resultados comprobados.

### T-11 — Vídeo, audio, timeline y render MP4/GIF
- **Estado**: borrador
- **Verificación**: Timeline sincronizado en navegador y ffprobe/medidas de frames/audio sobre MP4/GIF renderizados; recorte, mezcla y errores de recursos. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Cortar/dividir, pistas, mezcla, overlays/transiciones, reproducción sincronizada y export audiovisual medida.

### T-12 — Presentaciones, documentos, pizarras y gráficos
- **Estado**: borrador
- **Verificación**: Abrir/editar/reabrir documentos, presentaciones, pizarras y gráficos; comprobar formatos reales, tablas y presentación con E2E. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Flujos propios operativos, texto refluible/tablas, presentación, dibujo/conectores y exportaciones.

### T-13 — Colaboración, comentarios, roles y enlaces
- **Estado**: borrador
- **Verificación**: Pruebas con dos usuarios/clientes simultáneos: permisos, comentarios, conflicto, enlaces y revocación. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Permisos reales, comentarios/versiones, conflicto y edición concurrente verificada con dos clientes.

### T-14 — IA creativa y automatizaciones
- **Estado**: borrador
- **Verificación**: Trabajo real de proveedor/modelo configurado, errores/cuotas y archivo resultante comprobado; separar mocks de smoke real. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Generación/edición real mediante proveedor o modelo configurado, estados de trabajo y errores; sin botones simulados.

### T-15 — Publicación de sitios y contenido interactivo
- **Estado**: borrador
- **Verificación**: E2E de construcción y navegación responsive, preview y publicación en destino propio autorizado; revocación y acceso. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Constructor, preview responsive, navegación y publicación propia con controles de acceso.

### T-16 — Integración externa oficial de Canva
- **Estado**: borrador
- **Verificación**: OAuth/scopes por marca/cuenta, contrato de retorno, capacidades del plan y job de exportación real; sin compra ni publicación automática. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] OAuth por cuenta/marca, capacidades por plan, diseños/retorno/exportación y vínculos externos explícitos.

## Fase 4 — Profundidad de gestión social

### T-17 — Media/metadatos por destino y preview por red
- **Estado**: borrador
- **Verificación**: Pruebas compartidas servicio/API/MCP y E2E con media diferente por destino que conserva metadatos y bytes al publicar en entorno simulado. Smoke real del usuario separado.

**Criterios de aceptación**

- [ ] Contrato compartido web/API/MCP, persistencia, validación y publicación usan los mismos medios por destino.

### T-18 — Opciones avanzadas y redes adicionales
- **Estado**: en-progreso
- **Verificación**: Contratos por red/formato y TDD de opciones; OAuth/scopes y smokes reales cuando el usuario los ejecute. No equiparar fake HTTP con autorización del proveedor.
- **Archivos**: `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php`, `app/Jobs/RefreshSocialAccount.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `routes/web.php`, `tests/**`, `docs/**`

**Criterios de aceptación**

- [ ] X y redes del inventario, portadas/alt/localización/etiquetados y opciones soportadas verificadas por contrato.
- **Ampliación explícita del usuario, 2026-11-04:** investigar peticiones frecuentes de reconexión y posible mezcla de marcas. No sustituye el resto de T-18 ni autoriza publicaciones reales o acceso SSH.
- **RED:** `RefreshTokensTest::ignores outcomes from credentials replaced in the same second` (tres resultados: success/permanent/transient) y `SocialAccountTest::ignores a late rejected request` fallan **4/4** (`78721c`): token nuevo sobrescrito por el anterior, conexión nueva marcada como reconexión y fallo transitorio atribuido a la nueva cadena. Causa demostrada: comparar solo segundos de `last_refreshed_at`, y marcar desde peticiones antiguas sin comparar generación de credenciales. GREEN **32/32,127 aserciones** (`997573`) tras comparar un marcador interno de ciphertext/identidad, sin exponerlo ni registrarlo.
- **RED OAuth/marcas:** iniciar dos autorizaciones del mismo proveedor sobrescribía el único state por proveedor; además un callback inválido consumía el state legítimo. Tres casos fallan (`4dc18d`) en ambos órdenes de retorno y tras callback ajeno. RED de expiración del intento a 31 minutos (`823f0d`). Se conserva cada intento por state, propietario y marca, de un solo uso, máximo diez por proveedor/30 minutos; las rutas bloquean la sesión durante mutaciones para no perder intentos concurrentes. Reintento ajeno ya no consume otra autorización. GREEN conexiones **16/16,137 aserciones** (`5688c6`); regresión conexiones/jobs/conectores **517/517,2581 aserciones** (`9e6d14`).
- **Alcance de la conclusión:** hay fallos reproducidos de carreras y pérdida de intentos, no prueba de asignación de credenciales a otra marca. El aislamiento se comprueba además con la misma cuenta externa en dos marcas. Pendiente revisión independiente y QA. El navegador/pipe de acceso actual no está disponible y MySQL local no escucha; no se afirma diagnóstico del evento concreto del VPS ni se alteran sus conexiones.

### T-19 — Aprobaciones, publicación manual, recurrencia y lotes
- **Estado**: borrador
- **Verificación**: Pruebas de estados, permisos, idempotencia, cola/reintento y E2E de aprobación/manual/recurrencia/lotes. Pendiente de implementar/ejecutar.

**Criterios de aceptación**

- [ ] Estados/colas/permisos reales, idempotencia y acciones de usuario verificadas.

### T-20 — Inbox, competidores y SmartLinks ampliados
- **Estado**: borrador
- **Verificación**: Contrato por proveedor y E2E de inbox/competidores/SmartLinks sobre servicios reales locales; cobertura externa explícita. Pendiente de ampliar/ejecutar.

**Criterios de aceptación**

- [ ] Cobertura por proveedor, bloques enriquecidos y flujos funcionales del inventario.

### T-21 — Ads e informes coherentes con la marca
- **Estado**: en-progreso
- **Cubre (tests)**: E2E-06
- **Verificación**: php vendor/bin/pest tests/Feature/Parity/AdsBrandContextTest.php tests/Feature/Reports; E2E-06 de marca seleccionada y generación. Constructor/reporting/Ads ampliados necesitan criterios y pruebas adicionales antes del cierre.
- **Archivos**: `app/Services/AdsService.php`, `app/Http/Controllers/AdsController.php`, `resources/js/Pages/Ads/**`, `app/Http/Controllers/ReportController.php`, `resources/js/Pages/Reports/**`, `tests/**`

**Criterios de aceptación**

- [ ] Cuenta origen, caché, filtros y selección inicial respetan marca; reporting ampliado y distribución.

## Fase 5 — Integración y cierre

### T-22 — QA integral y revisión independiente
- **Estado**: en-progreso
- **Archivos**: `playwright.config.ts`, `tests/e2e/isolated/**`, `tests/e2e/studio.spec.ts`, `tests/e2e/metrics-quality.spec.ts`, `tests/e2e/brand-reporting.spec.ts`, `docs/roadmap/2026-11-03-creative-social-suite/testing/**`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06
- **Verificación**: Revisión adversarial A/B/C/D según selector, suites focales/completa apropiadas, E2E del test-plan.md y qa-gate sobre JSON real; cero gaps Critical/Important pendientes.

**Criterios de aceptación**

- [ ] Matriz completa, regresiones, seguridad de permisos/datos, rendimiento y accesibilidad con evidencias; cada limitación externa permanece explícita.

### T-23 — Documentación, despliegue y entrega git
- **Estado**: en-progreso
- **Cubre (tests)**: M-01, M-02, M-03, M-04
- **Verificación**: Enlaces/docs/ledger lint, diff limpio, commits y push de la rama y PR verificable; requisitos de servidor documentados. No cerrar despliegue por tener PR.
- **Archivos**: `docs/**`, `CONTINUE-HERE.md`, `README.md`, `CHANGELOG*`

**Criterios de aceptación**

- [ ] Guías web/API/MCP, dependencias/render/colas, commits, push y PR; no afirmar producción verificada sin sus pruebas.

## Revisión de dos lentes — intento 1: métricas (T-03) — un gap Important confirmado

Lentes A+B de contexto fresco, sin cambios de los revisores. Scope-check exit 0 sobre `2b38fb8`, 100 archivos en alcance, sin exclusiones de usuario ni avisos. Selector acotado a los 13 archivos de producción de métricas: C=false, D=false, sin motivos. `model-tier.py` no localiza `agents/` en el paquete instalado; se usa el rol reviewer disponible y su configuración heredada. No hay Jira activado.

| Criterio T-03 | A | B / evidencia |
|---|---|---|
| Alcance, engagement de cuenta/campaña, nulos y ceros | ✓ | Sin defecto adicional; 86/86 focales, 772 aserciones por B |
| Identidad YouTube, stocks, días abiertos y medias | ✓ | Sin defecto adicional |
| Comparaciones, frescura y proyección web/API/MCP | ✓ | Node calidad 3/3 por B |
| Cobertura sobre la misma población que el total | ✗ | A1/B1 reproducido en SQLite en memoria |
| Contraste real separado de simulación/VPS | ✓ | Informe de lectura real, sin certificar operación |
| Verificación integral y paginación de todos los proveedores | Pendiente | T-03 permanece abierta; no hay cierre falso |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1/B1 | Important | `AnalyticsService.php:103`/`:174` excluía de cobertura cuentas desconectadas con snapshots que sí se sumaban. Dos cuentas, total 1100, se etiquetaban complete con solo la activa medida. | T-03 | Población de cobertura incluye cuentas desconectadas que aportan snapshots del periodo; las desconectadas sin contribución no generan expectativas. UI de conexiones conserva solo conectadas. | RED `2c8643`: complete en lugar de partial; GREEN `8f4902`, 5/5, 144 aserciones, resumen/detalle/agregado. Pendiente re-revisión intento 2. |

## Revisión de dos lentes — intento 2: métricas (T-03) — sin gaps pendientes

A+B de contexto fresco reevalúan únicamente A1/B1; conservan los criterios aprobados en el intento 1. A confirma población/cobertura, exclusión de desconectadas sin contribución y conexiones activas. B ejecuta 5/5, 144 aserciones de cobertura y 13/13, 182 aserciones de corrección/cobertura, además de una sonda de periodos independientes en SQLite en memoria: periodo actual partial 3/4 con dos cuentas, anterior complete 2/2 con una. Sin edición de los revisores ni acceso a BD real. A1/B1: **corregido**. No hay Jira activado.

QA posterior integrada: PHP completo **2566/2566, 15185 aserciones**, cero fallos (`2d2ec4`; `testing/raw/phpunit-initial.xml`). Navegador **12/12**, cero fallos/flaky/omitidos (`9699fb`; `testing/raw/playwright.json`), tres recorridos de métricas y nueve de Studio en escritorio/375/360 px. `qa-gate.py`: **VERDE** (`89656b`). Captura de métricas móvil inspeccionada y conservada en `testing/screenshots/metrics-mobile-360.png`. No se acredita operación del VPS ni recuperación de los snapshots ausentes.

## Revisión de dos lentes — intento 1: estudio (T-04, T-05, T-06, T-07, T-08) — gaps en corrección

Lentes A+B+C+D de contexto fresco. C activada por el cambio de ruta `AuthenticatedLayout` y revisa también los nuevos permisos/descargas/render. D activada por MCP scheduler, migración, exportaciones y bucles de cargas/serialización. Scope-check base `2b38fb8` exit 0, sin archivos fuera de alcance ni exclusiones de usuario. No hay Jira activado.

| Criterio del bloque | A | B | C | D |
|---|---|---|---|---|
| CRUD, propiedad, revisiones, kit y recursos | ✓ | ✓ | Sin vulnerabilidad confirmada | — |
| Contrato de documento compartido | ✗ A1/A2 | Duplicado A | Validación sin código/URL arbitraria | — |
| Lienzo/grupos/capas/historial | ✓ | ✗ B2 defaults invisibles | Sin hallazgo confirmado | Historial medido, sin gap |
| Responsive y recuperación de conflicto | ✓ | ✓ | Sin hallazgo confirmado | — |
| Cinco formatos y revisión fijada | ✗ A4 recursos | ✗ B1 dimensiones; A4 corregido confirmado | Sin hallazgo confirmado en versión inicial | ✗ D2 cargas por recurso |
| API/MCP permisos y descubrimiento | ✓ | ✓ | ✓ Bearer/abilities/ownership | ✗ D1 historial |
| API/MCP gestión y OpenAPI | ✗ A3/A5 | Sin otro defecto | Sin hallazgo confirmado | — |

| # | Grado | Gap | Tarea | Corrección / estado | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | Vídeo del editor incluye `trimEnd:null`, rechazado por PHP | T-04/T-05 | PHP admite final null, rango numérico compartido y final posterior al inicio | RED PHP `c7a0a8`; GREEN `22ccc2`, dos pruebas/5 aserciones junto con sombra; prueba de guardar y reabrir |
| A2 | Important | Rangos sombra/trazados no coinciden entre PHP y JS | T-04/T-05 | Blur 0–500, offsets ±32000, hasta 10000 comandos en ambos; recortes con condición equivalente | RED JS `18804b`; GREEN `b658ec`, dos pruebas; PHP `22ccc2` |
| A3 | Important | MCP sin archivo/restauración/duplicado/carpetas y API sin carpetas | T-08 | En corrección por backend; dos herramientas y rutas con servicios compartidos | RED `3a385d` |
| A4 | Important | Recursos embebidos del renderer rechazados por adaptador same-origin | T-07/T-08 | Origen sintético; mapa cerrado URL→bytes en Playwright, sin socket ni continuación de red; engine mantiene validación privada | RED Chromium con imagen real `c66647`; GREEN `9c789f`, 2/2; B confirma arreglo; C debe revisar nueva versión |
| A5 | Important | Respuesta pending `media_ids:null` contradice OpenAPI array | T-08 | En corrección, contrato nullable comprobado sobre respuesta real | RED `3a385d` |
| B1 | Important | Raster trunca dimensiones fraccionales frente a round del servidor | T-07 | En corrección por motor; canvas de salida con dimensiones redondeadas y viewport de escala exacta | RED Chromium `eb816c`; GREEN focal `5e61a8`; falta integración de subida |
| B2 | Important | Nuevas líneas/trazados sin stroke son invisibles | T-05 | Defaults con trazo visible conservando estilos explícitos | RED Chromium `eb816c`; GREEN focal `5e61a8` |
| D1 | Important | Historial vuelve a consultar cada exportación y marca; sin paginar | T-08 | `presentMany` valida colección una vez; API/MCP paginación en corrección | RED endpoint con 100 exports: 101 SELECT de export (`d2ed61`) |
| D2 | Important | Autorización de recursos del render lanza 2 SELECT por recurso | T-07/T-08 | `StudioAssets::many` filtra propietario/marca/ready en dos consultas agrupadas | RED 100 imágenes: 200 SELECT (`ac494e`); GREEN `e4c9bf`, <=2 SELECT |

No se cierra el bloque hasta la re-revisión y QA sobre las correcciones. C inicial ejecutó Studio PHP 42/42, 239 aserciones; no afirmó aislamiento de SO ni ejecutó Chromium. D reprodujo cargas con SQLite en memoria: 200 exports/400 SELECT y 500 recursos/1000 SELECT. Ningún revisor modificó archivos ni llamó a proveedores.

Correcciones listas para intento 2:

- A1/A2: PHP nullable fin de vídeo y blur 500; JS offsets ±32000, 10000 comandos y final numérico posterior al inicio. RED/GREEN indicados arriba.
- A3/A5: `manage_design` y `manage_design_folder`, API carpetas y nulabilidad alineada con respuestas reales; catálogo **36**, nueve herramientas nuevas. RED `3a385d` → GREEN ampliado **127/127, 963 aserciones** (`0457d1`); OpenAPI lint/diff exit 0, sin cambios rompedores (`96c0ba`).
- A4: renderer sirve origen sintético mediante mapa exacto y `route.fulfill`, abortando cualquier otra petición. Prueba Chromium real con imagen privada, RED/GREEN arriba; no se relaja `sourceForAsset` público.
- B1/B2: canvas raster con dimensiones redondeadas y viewport a escala exacta, sin estirar bitmap truncado; líneas/trazos con stroke visible por defecto y estilos explícitos conservados. RED `0f388a`/`eb816c` → GREEN **25/25 Node** (`59e029`), más **3/3 E2E** de subir/registrar PNG/JPEG/WebP 41²×0,5→21² (`b60339`, run `run-general-f4e40fb6-4288-4247-8846-c5b5c1662e1a`).
- D1: `presentMany` valida colección/marcas una vez; historial paginado 50 por defecto y 100 máximo, con metadatos/página siguiente en API/MCP. RED `d2ed61`/`851780` → GREEN **8/8 renderer, 34 aserciones** (`f1b730`), incluido <=2 SELECT de exportaciones para la consulta de 100 filas.
- D2: autorización por lote de recursos, GREEN <=2 SELECT para 100 recursos (`e4c9bf`).

## Coherencia de marca — T-21, implementación parcial

Ads toma la conexión Meta de la marca seleccionada; caché separada por propietario, marca, conexión y versión de credenciales. Informes preselecciona marca del contexto/query con su zona horaria y conserva la marca al encolar. RED cinco casos `cf61f9`, caché `895822`, redirect `f89d9a` → GREEN **51/51, 1653 aserciones** (`019736`), Pint restringido. No incluye administración de Ads ni constructor avanzado de informes; esas partes continúan pendientes. Revisión y E2E de coherencia aún necesarios.

## Revisión de dos lentes — intento 2: estudio (T-04, T-05, T-06, T-07, T-08) — sin gaps pendientes

Lentes A+B+C+D de contexto fresco, limitadas a las correcciones del intento 1 y conservando los criterios ya aprobados. Primera revisión de la coherencia de marca de T-21 en estas cuatro lentes; no se evalúan como cerradas las capacidades avanzadas de Ads/reporting.

| Criterio corregido | A | B | C | D | Evidencia |
|---|---|---|---|---|---|
| A1/A2 contrato PHP/JS y vídeo | ✓ | ✓ | Sin bypass nuevo | — | PHP de guardar/reabrir y Node documento 8/8 por A; rangos alineados |
| A3/A5 gestión API/MCP y nulabilidad | ✓ | ✓ | ✓ abilities por acción/ownership | — | API/MCP con carpetas, restore y respuestas reales contra OpenAPI; lint válido |
| A4 recursos privados del render | ✓ | ✓ | ✓ mapa exacto, MIME/bytes y abort resto | — | Chromium real con imagen; C ejecuta renderer 2/2 |
| B1/B2 píxeles y defaults visibles | ✓ | ✓ | Sin nueva ejecución arbitraria | Sin regresión confirmada | Node completo 25/25 por B, incluyendo borde fraccionario y líneas; E2E raster 3/3 previo |
| D1 historial | ✓ | ✓ | ✓ validación de colección | ✓ corregido | Default 50/max100, página siguiente; <=2 SELECT de exportaciones |
| D2 recursos agrupados | ✓ | ✓ | ✓ propietario/marca/ready | ✓ corregido | 100 recursos con <=2 SELECT en lugar de 200 |
| T-21 coherencia de marca | ✓ | ✓ | ✓ propietario/marca y credenciales | ✓ caché acotada y listado limitado | Tests Ads/Reports; E2E final pendiente en QA |

**Gaps pendientes: 0 Critical, 0 Important, 0 Minor.** A1–A5, B1–B2 y D1–D2 corregidos. No hay Jira activado. A no ejecuta verificaciones para suplir cierres ausentes; OpenAPI nuevo en la base hace n/a la comparación contractual con `2b38fb8`. B ejecuta 75/75 PHP, 650 aserciones y Node 25/25. C ejecuta 31/31 PHP, 302 aserciones y Chromium 2/2. D ejecuta 31/31, 302 aserciones con almacenamiento de prueba propio; descarta la ejecución que colisionó con Storage::fake de otra suite. No hay accesos a BD real/proveedores ni certificación de aislamiento del sistema operativo.

QA independiente final en curso con 18 E2E previstos. La segunda suite PHP amplia tuvo 5 incidencias de archivos temporales por ejecución concurrente de revisores; se conserva esa salida como diagnóstico, no como verde ni como defecto del producto. Se repite la suite completa con `LARAVEL_STORAGE_PATH` único para evitar compartir `Storage::fake`; no se relajan los tests ni se cambian sus aserciones.

## Comprobación final de límites del documento (T-02, T-04)

Se añaden dos regresiones de compatibilidad de datos antes del cierre: el presupuesto de 2 MiB se mide en UTF-8 sin expandir Unicode/barras, y un grupo vacío se rechaza antes de persistir porque el editor ya lo rechaza. No se ejecuta el JSON codificado: solo se mide su longitud. El documento validado se conserva sin alterar valores.

RED real `0341e1`, antes del cambio PHP:

```text
php vendor/bin/pest tests/Feature/Studio/StudioBackendTest.php --filter='canonical UTF|empty group' --colors=never
{"tool":"pest","result":"failed","tests":2,"passed":0,"assertions":3,"failed":2}
canonical UTF-8: Expected response status code [201] but received 422. El documento supera el límite de 2 MiB o contiene valores no finitos.
empty group: Expected response status code [422] but received 201.
```

GREEN real `6c1574`, después de las dos guardas:

```text
php vendor/bin/pest tests/Feature/Studio --colors=never
{"tool":"pest","result":"passed","tests":53,"passed":53,"assertions":352,"duration_ms":3500}
```

La prueba Node adicional de 10000 emojis y rechazo de 10001 ya pasaba (`1cf0fb`, 1/1); es caracterización del comportamiento existente, no se atribuye un RED ni un fix JS. Se añade esta distinción para no inventar evidencia TDD. Revisión suplementaria A/B de estas dos guardas en curso; la suite PHP completa se repite con almacenamiento único para incluirlas.

La revisión A suplementaria detectó que PHP todavía escapaba U+2028/U+2029. Se añadió `JSON_UNESCAPED_LINE_TERMINATORS` al cálculo de longitud y dos datasets: **RED `b2079e`**, tres casos, CJK pasa y separadores fallan 201 frente a 422; **GREEN `16a7ac`**, Studio **55/55, 356 aserciones**, JUnit `testing/raw/studio-boundaries-final.xml`. A/B suplemento intento 2: sin gaps. Ambos ejecutan PHP/Node sin BD y confirman igualdad de bytes, rechazo al superar 2 MiB y retorno intacto; B confirma que NAN/UTF-8 inválido siguen rechazados. La suite amplia previa a esos dos datasets pasó **2585/2585, 15376 aserciones** (`656ea0`, `testing/raw/phpunit.xml`); el focal de 55 cubre la última guarda. No se presenta como una ejecución amplia de 2587 casos.

## Revisión de dos lentes — intento 1: transporte QA (T-22) — corte de streaming confirmado

Scope limitado al runner de pruebas, configuración Chromium y vigilancia de errores. A aprueba aislamiento, origen/RP ID, veinte assets por socket, Host/cookies/cuerpo, cierre y errores/overflow. B reproduce un Important: `tests/e2e/isolated/http-server.cjs:39`, backend envía bytes parciales y cierra, pero el frontend queda esperando. RED de regresión `1c7a5f`: `hanging` frente a `aborted`.

## Revisión de dos lentes — intento 2: transporte QA (T-22) — sin gaps pendientes

A/B frescas confirman el aborto propagado al navegador, 502 antes de cabeceras y cancelación del backend al cerrar el cliente. GREEN `d4605e`, **3/3** con keep-alive/stream cortado/aislamiento. B ejecuta además error previo a cabeceras y cancelación del cliente: PASS. Selector sobre los cinco archivos de transporte efectivamente cambiados tras la revisión Studio: C=false/D=false, sin motivos; el test de reporting ya estaba evaluado en T-21. No hay Jira activado. [Diagnóstico causal](testing/debug-loopback/report.md), resúmenes NetLog sin credenciales y propuesta de gotcha pendiente de curación; no se promueve a conocimiento aprobado.

## QA final y cierre del bloque de fundamentos

QA independiente sobre el último helper de streaming: **18/18 E2E, cero failed/flaky/skipped/interrupted**, `qa-gate.py` exit 0. Los intentos 1/2 rojos y el 3 verde previo al arreglo de streaming permanecen conservados; el final corresponde a la cuarta ejecución. [Informe](testing/qa-final/report.md), [PDF](testing/qa-final/report.pdf), [JSON/gate](testing/qa-final/raw/final/qa-gate.json). Capturas de editor y métricas desktop/375/360 revisadas sin clipping ni overflow. Frontend completo **29/29** (`testing/raw/frontend.tap`), guardas Node **3/3**, build/Pint/TypeScript/OpenAPI correctos. La migración se aplicó exclusivamente al MySQL local verificado; sin VPS, publicaciones reales ni captura de snapshots del proveedor.

Se cierran **T-02 y T-04–08: 6/23**. El resto conserva su alcance original: T-03 mantiene contraste global/operación real abiertos; T-09–20 requieren sus funciones y pruebas; T-21 solo cierra su subbloque de coherencia. T-22/T-23 son cierres globales y siguen en progreso. No se declara clon completo ni se reemplazan los manuales del usuario por fakes. Los avisos de Changelog de tareas futuras no cerradas son informativos; las seis tareas cerradas tienen su entrada específica.

## Entrega git del bloque — 2026-11-04

- `6c33b0e`: métricas, cobertura/frescura y contraste de lectura.
- `48e453e`: contexto de marca de Ads/informes y sus regresiones.
- `e4c37b2`: estudio, API/MCP, exportaciones y transporte aislado.
- `d14957d`: contratos, inventario, guías y evidencias saneadas.
- Rama `feature/creative-social-suite` subida. [PR #19](https://github.com/daycry/social-scheduler/pull/19) **borrador**, base `fix/composer-refresh-after-save` (#18 aún abierta). Checks consultables en la PR; no se acredita despliegue ni merge.

La comprobación Pint global detectó formato del helper documental `render-report.php`; se corrigió sin cambiar comportamiento ni regenerar evidencias. Esta nota y el punto de continuación se comitean con esa corrección. No hay cambios en `.env`, tokens, cron/worker del VPS ni publicaciones reales.

## Revisión de dos lentes — intento 1: automatización T-09 y suplemento T-03 — cuatro gaps confirmados

Base `3d83f70`, diff más archivos nuevos; scope-check exit 0, 30 archivos, sin avisos/fuera de alcance/exclusiones de usuario (`a3ab8b` y comprobación posterior). Lentes A+B+D, C=false; D activada por migración. Roles reviewer con contexto fresco; `model-tier.py` no encuentra `agents/` en paquete instalado, configuración heredada. Sin Jira habilitado.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | Una referencia legacy null se convertía en `[]`, fuera del contrato object/null | T-09 | Referencia vacía canónica con paleta/fuentes/logos y presentación compatible con filas legacy | RED `57df51` → GREEN `978166` |
| B1 | Important | Undo de aplicar kit restauraba el documento, pero conservaba referencia del kit posterior; siguiente aplicación no reconocía sus estilos | T-09 | `kit_revision` opcional validada contra snapshot del mismo diseño/usuario; historial/autosave/recover lo preservan, API y MCP comparten servicio | RED `57df51` → GREEN `978166` |
| D1 | Important | Sustitución de campos podía expandir 100 KiB válidos hasta 200 MB antes de validar, agotando memoria | T-09 | Preflight por longitud Unicode y bytes JSON antes de materializar, límite por texto y documento | RED seguro en proceso 96 MiB (`57df51`) → GREEN `978166` |
| D2 | Important | Acumular colores con búsquedas lineales crecía cuadráticamente bajo lock | T-09 | Hashset normalizado conserva el primer orden; deduplicación hexadecimal equivalente | 36000 colores: 2470,40 ms (`583caa`) → 56,75 ms (`978166`) |

A/B ejecutan 24/24 PHP,126 aserciones aisladas; D usa sondas sin BD/proveedores. Todos los gaps se comprobaron antes de corregir; no se rebaten ni se rebajan. Implementador de corrección distinto, RED 4/4 (`57df51`), GREEN 4/4,19 aserciones (`978166`), regresión Studio/MCP **361/361,2319 aserciones** (`32e19a`), JUnit `testing/t09/raw/phpunit-studio-mcp-fixed.xml`. Pint/TypeScript/OpenAPI correctos, cero cambios rompedores. Re-revisión y QA pendientes; no se cierra T-09.

La suite PHP amplia de la primera integración pasó **2601/2601,15483 aserciones** (`923f0e`), anterior a estas correcciones y al nuevo bloque de conexiones. Se conserva ese alcance temporal; no se atribuye a la versión final.

## Revisión de dos lentes — intento 2: T-09 y fiabilidad de conexiones T-18 — dos gaps nuevos en OAuth

Lentes A+B+C+D de contexto fresco, en tandas por disponibilidad de tres slots. A1/B1/D1/D2 de T-09 quedan **corregidos**, sin reabrir los criterios anteriores. A confirma OpenAPI base exacta, cero errores/rompedores y focal 68/68,399 aserciones. C no halla un bypass adicional; el replay reproducido por B se incorpora a la tabla común. D mide preflight en 96 MiB sin OOM y 36000 colores en 54,60 ms. Scope-check exit 0,42 archivos sin avisos, C activada por `OAuthController.php`, D por migración. Sin Jira activado.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B2 | Important | Un GET normal que terminaba después del callback podía restaurar un state consumido o borrar un intento nuevo; bloquear solo dos rutas no protegía escrituras ordinarias | T-18 | Registro autoritativo de intentos en caché con lock corto para crear/consumir, vinculado a usuario/secreto CSRF de la sesión/proveedor, TTL y máximo diez. La sesión conserva únicamente puntero de conveniencia; no se acepta como alternativa al registro. | RED `e3364e`: dos `Store` con handler común y callback real aceptan dos veces. GREEN conexiones/reconexiones **62/62,353 aserciones** (`7a6a6f`); prueba adicional de otro navegador del mismo usuario: conexiones **18/18,145 aserciones** (`a3e6c0`). |
| D3 | Important | Lock de toda la sesión incluía las llamadas HTTP al proveedor y hacía caducar a los 10 s otro callback independiente | T-18 | Se elimina ese bloqueo de ruta; el lock del registro solo cubre estado local y termina antes del intercambio HTTP | D reproduce `LockTimeoutException` con StartSession real/ruta real y lock retenido durante ~10 s. Cambio pendiente de re-revisión, sin afirmar operación del VPS. |

El primer intento de registro vinculado al ID de sesión falló tres casos de las peticiones HTTP de prueba (`1a3dfc`); se usa el secreto CSRF estable de la sesión, que distingue navegadores y cambia cuando se renueva el contexto de autenticación. No se imprime ni registra dicho secreto. No hay fallback a snapshots viejos. El test del navegador ajeno comprueba que su rechazo no consume el intento legítimo. Revisión final y QA pendientes.

## Revisión de dos lentes — intento 3: fiabilidad OAuth T-18 — sin gaps pendientes

B+C+D de contexto fresco reevalúan exclusivamente B2/D3; se mantienen los criterios aprobados por A2/B2/C1/D2 de T-09, guard de credenciales, separación de marcas y contrato. **0 Critical, 0 Important, 0 Minor pendientes.** No se ha aceptado deuda ni rebatido un fallo sin evidencia. No hay Jira habilitado.

| Criterio corregido | B | C | D | Evidencia |
|---|---|---|---|---|
| Snapshot de sesión antiguo no resucita ni borra autorización | ✓ | ✓ | — | B: dos Store/handler compartido, ambos intercalados correctos; C: replay tras restauración rechazado |
| Consumo atómico, límites y caducidad | ✓ | ✓ | ✓ | B: diez consumibles/evicción del primero, válido a 1799 s/rechazado a 1800; C: caché database con SQLite y cache_locks, 8/8 sondas |
| Usuario/navegador/proveedor ajenos no consumen el legítimo | ✓ | ✓ | — | C comprueba los tres contextos; Connections 18/18,145 aserciones por B y C |
| Red fuera de lock, callbacks independientes | ✓ | ✓ | ✓ | D: primer callback suspendido en exchangeCode, segundo termina 302 en 0,41 ms; lock disponible, sin block de ruta |
| Trabajo local acotado | — | — | ✓ | D:1000 inicios en209,37 ms, máximo y final10 registros |

B ejecuta además Connections/SocialAccount/RefreshTokens/Backfill **62/62,302 aserciones** (scope distinto al bundle de 353 aserciones); C conserva los criterios previos y usa caché database en su sonda. D inicializó aplicación sin `.env`, con servicios simulados y Fiber; no midió producción. No hubo proveedores ni BD real.

Verificación global posterior de la misma versión: PHP **2618/2618,15568 aserciones**, cero fallos/errores/omitidos (`07d8f3`, `testing/t09/raw/phpunit-final.xml`); Studio Node/Chromium **26/26**, cero fallos/omitidos (`4dbba6`, `testing/t09/raw/frontend-final.tap`); Pint global, TypeScript y diff-check correctos (`7d2bb2`). Dos E2E desktop T-09 tras fix de kit_reference, logos reales, undo/redo y variantes: **2/2** (`8e6234`); QA independiente de los 24 integrados sigue en marcha. T-09 no se cierra aún.

## Cierre T-09 y entrega del suplemento de conexiones — 2026-11-04

QA independiente posterior al último fix: **24/24**, cero failed/flaky/skipped/interrupted, una ejecución sin reintentos; `qa-gate.py` **VERDE** (`bf558b`/`36d951`), doce capturas revisadas en 1440×900/375×812/360×740 sin overflow ni clipping. PNG de instancia 1080² y variante 1080×1920 reales, comprobados en los tres viewports. Ocho hashes de entrada iguales antes/después. [Informe](testing/t09/qa/report.md), [PDF de once páginas](testing/t09/qa/report.pdf), [JSON/gate](testing/t09/qa/raw/qa-gate.json); sin publicar trazas, cookies ni bases de datos.

Se cierra **T-09** con su alcance completo y evidencia de servicio/web/API/MCP, revisión y QA. El total pasa a **7/23**. T-03 sigue abierta por el contraste global/recolección del VPS; T-18 conserva las redes y opciones pendientes aunque su subbloque de reconexiones quede corregido y revisado. T-10–23 no se dan por implementadas mediante esta tanda. No se altera ninguna verificación real que el usuario mantuvo a su cargo.

Commits de código: `f64c677` conexiones, `9d1491f` backfill y `e3f1cef` automatización del estudio. Continúa la misma PR #19 en borrador; no se fusiona ni despliega automáticamente. La migración adicional de referencias de kit sigue pendiente del MySQL de la instalación; el intento local falló por ausencia del listener. Las bases desechables de pruebas sí aplicaron las migraciones reales. Sin cambios en `.env` o permisos del proveedor.
