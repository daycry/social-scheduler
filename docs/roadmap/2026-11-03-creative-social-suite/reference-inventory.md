# Inventario funcional de referencia y cobertura de la suite

Actualizado: 2026-11-10 (método estándar). Cada función del inventario lleva su **estado** (Cubierta / Parcial / Fuera), la **tarea** que la cubre y la **evidencia** (test Pest, `node --test` o E2E Playwright). Cubierta significa «verificada con servicio real local y `Http::fake`»: el smoke contra el proveedor real lo ejecuta el usuario y no se da por hecho. Este inventario no usa capturas ni datos de la sesión real de ninguna aplicación de referencia: se apoya solo en la documentación ya guardada (`docs/referencia/metricool-analisis-*.md`, fuentes oficiales enlazadas y el ledger).


## Evidencia utilizada

| Evidencia | Qué se observó | Qué no acredita |
|---|---|---|
| REA, captura pública controlada `ev_2837561e17e0c1d8f941b44c65501af862c5e255f6ae25ec79446deb3b38b632` | Edge Chromium 156 abrió Canva y encontró Cloudflare HTTP 403. Captura de red sin cuerpos ni valores de cabeceras. Proceso propio cerrado al terminar. | No se observó el editor ni se accedió a funciones premium por esta vía. El registro completo saneado permanece fuera de Git, en `storage/framework/testing/rea-canva-public.json`. |
| UI Automation nativa del Edge autenticado del usuario | Proyectos, diseño existente, Archivo, Redimensionar, guardado, Compartir, comentarios, Plantillas, Elementos, Texto, Marca, Subidos, Herramientas, Apps, páginas, capas, notas, temporizador, zoom y presentación. No se modificó el diseño. | No es evidencia CDP/REA. Una opción visible no demuestra que su versión premium esté habilitada en la cuenta gratuita. No se guardan URLs privadas del diseño. |
| Investigación del repositorio | Servicios, conectores, políticas, rutas, MCP, compositor, analítica, Ads, informes y almacenamiento actuales. | La existencia de una clase o botón no garantiza publicación real ni respuesta de una API externa. |
| Pruebas locales del nuevo estudio | Backend, serialización Fabric, conflictos, capas, páginas, cinco formatos de exportación, asociación de media y borrador; recorridos de navegador en escritorio y móviles. | No demuestra MP4/GIF, colaboración en tiempo real, IA configurada ni integración externa Canva. |
| Lectura directa de Meta | Actividad real reciente de dos cuentas de la marca local 55, contrastada con ausencia de snapshots recientes. | No acredita el estado del VPS ni que se hayan recuperado/escrito los datos. Véase [auditoría](testing/metrics/audit-2026-11-03.md). |

El inventario de las funciones de pago se apoya en documentación oficial. La cuenta Canva Free no permite observar todos sus comportamientos premium; esos detalles conservan esa limitación. Las plantillas nativas del proyecto son originales y no se copian catálogos privados de las aplicaciones de referencia.


Nota de método: la tabla anterior es evidencia **histórica ya guardada** (2026-11-03); no se ha vuelto a observar ninguna aplicación externa. Lo que dependía de aceptaciones del método antiguo (arneses nativos, presupuestos de 900 s) se sustituye por la verificación estándar de cada tarea, citada en la columna «Evidencia» de las matrices.

## Gestión social

Estado: **Cubierta** (implementada y verificada), **Parcial** (con límite declarado) o **Fuera** (sin implementar, con motivo). Los números son los del cierre de cada tarea en el [ledger](tasks.md). «Previa» = capacidad anterior a esta iniciativa, con suites propias que siguen verdes (Pest completo 3459 pass, 9 skip, 2026-11-10).

| ID | Capacidad | Estado | Tarea | Evidencia | Límite o brecha |
|---|---|---|---|---|---|
| M01 | Marcas y conexiones | Cubierta | T-18 | `RefreshTokens`/`SocialAccount`/OAuth/Connection 262/262; `NewNetworksOAuthFlow` 3; E2E `oauth.spec.ts` | Smokes reales de Bluesky, Threads, LinkedIn y Pinterest pendientes del usuario. |
| M02 | X/Twitter, LinkedIn, Pinterest, Google Business y Twitch | **Parcial** | T-18 | LinkedIn: `LinkedInConnectorTest` 8 + `LinkedInPublisherTest` 26; Pinterest: `PinterestConnectorTest` 19; `AdvancedOptionsContractTest` 50/50 (web, API, MCP); E2E `linkedin-pinterest.spec.ts` 9/9 | LinkedIn y Pinterest: **Cubiertas por contrato**. **X, Google Business y Twitch: Fuera, pendientes de decisión del usuario** (X exige plan de pago de la API; GBP exige aprobación de acceso; Twitch es analítica de streams, no publicación). Sin conector de ninguna de las tres. Métricas de LinkedIn/Pinterest: Fuera (sin lector; `unsupported`, `null`, nunca 0; `ProviderMetricsContractTest`). |
| M03 | Compositor global y presets por destino | Cubierta | T-17/18 | `TargetMediaContractTest` 13/13, `AdvancedOptionsContractTest` 50/50, E2E `composer.spec.ts`, `composer-presets.spec.ts` | `user_tags` de Instagram solo por API/MCP (la web no tiene editor de coordenadas). |
| M04 | Música en publicaciones | Cubierta (previa) | T-18 | E2E `instagram-audio.spec.ts`; suites de audio de Instagram | Búsqueda y selección solo donde el contrato de la red admite audio; no equivale a todos los catálogos. |
| M05 | Medios diferentes por destino | Cubierta | T-17 | `TargetMediaTest` 14/14 (payload por publicador: bytes, orden, alt), `TargetMediaContractTest` 13/13, YouTube 56/56, E2E `target-media.spec.ts` | La media propia no se guarda en plantillas de la biblioteca. |
| M06 | Preview por red | Cubierta | T-17 | E2E `target-media.spec.ts` y `composer.spec.ts` (3 proyectos) | Sin recorte ni relación de aspecto por destino (no existían, no se inventaron). |
| M07 | Portadas, alt, localización y etiquetado | Parcial | T-17/18 | Tabla de redes y opciones de «Cierre T-18»; `AdvancedOptionsContractTest`, `InstagramPresetsPublishTest` | Portada solo en Reel de Instagram y YouTube; alt donde la API lo admite; ubicación y etiquetas solo Instagram (imagen); sin búsqueda de lugares; Facebook `place` fuera. |
| M08 | Calendario/lista/mejores horas | Cubierta (previa) | T-19 | E2E `calendar-views.spec.ts`, `natural-schedule.spec.ts` | Recomendaciones con los datos disponibles. |
| M09 | Aprobación y roles | Cubierta | T-13/19 | `ApprovalTest` 11/11; `StudioCollaborationTest` 17/17 | Rol único `aprobador` por marca; las ocurrencias de una serie nacen sin aprobación. |
| M10 | Publicación manual y notificaciones | Cubierta | T-19 | `ManualPublishTest` 7/7 | Solo aviso en la aplicación (sin recordatorio repetido ni correo). |
| M11 | Recurrencia, autolistas y lotes | Cubierta | T-19 | `SeriesTest` 10/10; `tests/Feature/Workflow` 70 tests (recontado 2026-11-12); MCP `batch_posts`, `manage_post_series` | Sin cron ni worker reales en E2E (job invocado a mano). OpenAPI no ampliada con estos endpoints. |
| M12 | Biblioteca, textos, hashtags y notas | Cubierta (previa) | T-09/17 | E2E `hashtags.spec.ts`, `media-import.spec.ts`; `StudioBackendTest` (media por marca) | — |
| M13 | Analítica de cuentas | Cubierta | T-03 | `MetricsCorrectnessTest`, `MetricsCoverageTest` (5), `ProviderMetricsContractTest` (4), `CalculatorTest`; E2E `metrics-quality.spec.ts` | Operación de recolección del VPS sin comprobar; contraste con proveedor real solo de lectura (informe 2026-11-03). |
| M14 | Analítica de publicaciones | Parcial | T-03 | Lectores por proveedor con `Http::fake`: Meta, Threads, Bluesky, TikTok, YouTube | Solo publicaciones propias y presupuesto de lectura reciente, no todo el universo nativo. |
| M15 | Analítica de todas las marcas/campañas | Cubierta | T-03 | `AggregateAnalyticsTest`, `AllBrandsFlowTest`; E2E `all-brands.spec.ts`, `campaigns.spec.ts` | Aislamiento de propietario verificado. |
| M16 | Competidores | Parcial | T-20 | `CompetitorRangeTest` 8/8; E2E `competitors.spec.ts` | Solo Instagram (Business Discovery): no hay datos públicos de terceros en otras redes. |
| M17 | Inbox | Parcial | T-20 | `InboxProvidersTest` 13/13; E2E `inbox-triage.spec.ts`, `inbox-notes.spec.ts` | Instagram, Facebook, Threads, Bluesky y YouTube. **Fuera**: TikTok (sin API pública) y mensajes directos de Meta (requieren revisión de la app). Threads, Bluesky y YouTube solo por contrato simulado. |
| M18 | Informes y distribución | Cubierta | T-21 | `ReportBuilderTest` 14/14, `McpReportBlocksTest` 3/3; E2E `report-builder.spec.ts`, `brand-reporting.spec.ts` | Email real depende del SMTP del despliegue (T-23). |
| M19 | Ads | Parcial | T-21 | `AdsBrandContextTest` 6/6, `AdsTest` | Meta de solo lectura. **Fuera**: gestión de campañas, Google Ads y TikTok Ads. |
| M20 | SmartLinks | Cubierta | T-20 | `SmartLinkBlocksTest` 11/11 | Sin paridad API/MCP de SmartLinks. |
| M21 | Equipos/clientes y permisos | Parcial | T-13/19 | `StudioCollaborationTest` 17/17, `ApprovalTest` | Roles de diseño (propietario, editor, comentarista, lector) y de marca (`aprobador`); no hay organizaciones. |
| M22 | Integraciones/API/MCP | Cubierta | T-08/19/20/21 | `StudioApiMcpTest`, `AdvancedOptionsContractTest`; herramientas MCP del estudio, flujo, informes e inbox | No es paridad con todas las funciones de los dos productos. |

Referencias oficiales de alcance social: [introducción de Metricool](https://help.metricool.com/es/conoce-metricool-p0zdh), [planes premium](https://metricool.com/es/premium/), [roles y permisos](https://help.metricool.com/es/roles-y-permisos-en-metricool-rhljq), [SmartLinks](https://help.metricool.com/es/smartlinks-en-metricool-guia-completa-p7p83), [conexión de plataformas](https://help.metricool.com/es/como-conectar-las-redes-sociales-y-las-plataformas-de-anuncios-a-metricool-qrkvn) y [límites de su MCP](https://help.metricool.com/faqs-about-the-metricool-mcp-1i3w0). La aplicación propia puede exponer por MCP funciones adicionales; no se atribuyen al MCP de referencia.

## Estudio creativo

| ID | Capacidad | Estado | Tarea | Evidencia | Límite o brecha |
|---|---|---|---|---|---|
| C01 | Catálogo y carpetas por marca | Cubierta | T-04/06/08 | `StudioBackendTest`, `studio.spec.ts` | Sin miniaturas persistentes por diseño. |
| C02 | Documento propio y revisiones | Cubierta | T-04 | `StudioBackendTest` (409 optimista, snapshots) | No se ejecuta JSON de Fabric externo. |
| C03 | Lienzo, selección y geometría | Cubierta | T-05/06 | `node --test` documento/geometría; `studio.spec.ts` | — |
| C04 | Capas, grupos y bloqueo | Cubierta | T-05/06 | `node --test` documento; `studio.spec.ts` | — |
| C05 | Texto y tipografía | Cubierta | T-05/10 | `StudioFontsTest` 19/19; `studio-advanced-rendering` | Fuentes propias por marca (WOFF2 normalizado). |
| C06 | Formas y dibujo | Cubierta | T-05/12 | `StudioTablesConnectorsTest` 16/16; `studio-boards.spec.ts` 9/9 | Conectores rectos o en ángulo enganchados a formas. |
| C07 | Imágenes y recursos | Cubierta | T-04/08/10 | `StudioBackendTest`, `StudioApiMcpTest`; `studio-imaging` 3/3 proyectos | — |
| C08 | Crop, filtros, sombra y gradientes | Cubierta | T-05/10 | `studio-advanced-rendering`; E2E `studio-imaging` (píxeles del PNG) | Sin edición fotográfica avanzada. |
| C09 | Quitar fondo/edición localizada | Cubierta | T-10 | `test_background.py` 3/3 (BiRefNet local); `StudioImageJobsTest` 14/14; E2E `studio-imaging` con inferencia real | Sin relleno generativo ni borrador mágico (requieren proveedor o pesos: T-14). |
| C10 | Undo/redo y recuperación | Cubierta | T-04/06/13 | `StudioCollaborationTest` (historial, restauración, 409 con dos clientes) | — |
| C11 | Páginas | Cubierta | T-05/06 | `studio.spec.ts` | — |
| C12 | Plantillas y marca | Cubierta | T-09 | 14 plantillas originales, kit por marca; suites de T-09 | — |
| C13 | Redimensionar y variantes | Cubierta | T-09 | Variantes transaccionales (hasta doce) | Datasets por fila: T-14 (borrador). |
| C14 | PNG/JPEG/WebP | Cubierta | T-07/08 | E2E `studio.spec.ts` (firmas y dimensiones reales) | — |
| C15 | SVG/PDF | Cubierta | T-07/08 | E2E `studio.spec.ts`, `studio-boards.spec.ts` | PDF rasterizado, no vectorial editable. |
| C16 | PPTX y otros formatos | **Fuera** | T-12 | — | Sin exportación PPTX; no se anuncia. |
| C17 | Vídeo básico en lienzo | Cubierta | T-11 | Suites de T-11 y `studio-av-composite.spec.ts` | — |
| C18 | Timeline/audio/transiciones/subtítulos | Parcial | T-11 | Subtítulos SRT/WebVTT manuales | ASR y traducción: Fuera, T-14 (borrador). |
| C19 | MP4/GIF | Cubierta | T-11 | Jobs con cancel/retry/descarga/borrador; medidas A/V en casos concretos | — |
| C20 | Presentaciones | Cubierta | T-12 | `studio-presentations.spec.ts`, `studio-presentation-tools.spec.ts` (sesión, notas, audiencia, temporizador) | Sin exportación PPTX (C16). |
| C21 | Documentos | Cubierta | T-12 | Texto refluible y tablas: `StudioTablesConnectorsTest` 16/16 | — |
| C22 | Pizarras y gráficos | Cubierta | T-12 | `studio-boards.spec.ts` y `studio-charts.spec.ts` (sin `test.fixme`, comprobado 2026-11-12); gráficos enlazados a datasets | E2E-28 cubierto en ambos specs; sin proveedor externo implicado. |
| C23 | Comentarios y colaboración | Cubierta | T-13 | `StudioCollaborationTest` 17/17 (418 aserciones) | Sin edición en tiempo real; invitados no exportan. |
| C24 | IA generativa y asistencia | Cubierta (opcional) | T-14 | `StudioAiTest` + `StudioCaptionsTest` 98 tests; `studio-ai.spec.ts`; rutas `studio.ai.*`, `studio.ai-jobs.*`, `studio.ai-text` | Desactivada por defecto (`AI_ASSISTANT_ENABLED`, claves de proveedor); con `Http::fake`, sin smoke real con proveedor. |
| C25 | Sitios web/interactividad | Cubierta | T-15 | `SiteTest`; E2E de sitios con CSP y 0 errores de consola | Sin dominio propio ni aviso por correo. |
| C26 | Stock, apps y recursos premium | Parcial | T-10/16 | Subida propia y fuentes externas integradas | Sin derechos sobre catálogos de pago de Canva. |
| C27 | Integración oficial Canva | Cubierta por contrato | T-16 | `tests/Feature/Canva` (OAuth con PKCE, capacidades, diseños, retorno firmado, exportación, API y MCP) y E2E `canva.spec.ts` con Canva falso | Sin app registrada ni smoke real (pendiente del usuario); no verifica la firma del `correlation_jwt`; Brand Templates y Autofill desactivados con motivo; el PDF no entra en la biblioteca. |
| C28 | Automatización creativa por API/MCP | Cubierta | T-08/09 | `StudioApiMcpTest` | Automatizaciones avanzadas ligadas a T-14. |

Referencias oficiales de edición: [diseños](https://www.canva.dev/docs/apps/designs/), [elementos](https://www.canva.dev/docs/apps/elements/), [edición](https://www.canva.dev/docs/apps/design-editing/), [capas](https://www.canva.com/help/finding-and-arranging-layers/), [bloqueo](https://www.canva.com/help/lock-and-unlock-elements/), [kit](https://www.canva.com/help/brand-kit/), [plantillas de marca](https://www.canva.com/help/using-brand-templates/), [imágenes](https://www.canva.com/help/image-settings/), [vídeo](https://www.canva.com/help/creating-and-editing-videos/), [presentaciones](https://www.canva.com/en_gb/help/presentation-modes/), [pizarras](https://www.canva.com/en_gb/help/expand-to-whiteboard/) y [sitios](https://www.canva.com/help/publishing-canva-websites/).

## Integración externa frente a motor propio

El editor nativo usa un [contrato de documento propio](document-contract.md). Fabric aporta interacción y render de objetos, con licencia MIT; el formato persistido no depende de sus clases. PDF usa pdf-lib, también MIT. Se comparte el motor de render entre navegador y trabajos de servidor para reducir diferencias de geometría.

Canva Connect proporciona operaciones sobre diseños y trabajos externos; el SDK Apps se ejecuta dentro de Canva. No son una licencia para incrustar su editor completo ni convierten una cuenta gratuita en Pro. El complemento oficial se implementará de forma separada y conservará sus requisitos de registro, revisión, scopes y plan. [Connect](https://www.canva.dev/docs/connect/) · [integración Apps](https://www.canva.dev/docs/apps/integrating-canva/).

La API oficial de exportación enumera formatos según su contrato; no se asume SVG por existir en un editor visual. Los enlaces externos de exportación son temporales. Los artefactos nativos se guardan en almacenamiento privado y usan su propio enlace firmado de diez minutos. [Contrato oficial de exportación](https://www.canva.dev/docs/apps/rest-apis/reference/exports/create-design-export-job/).

Autofill/Brand Templates amplió la elegibilidad a Pro y planes superiores en septiembre de 2026; no se conserva la antigua suposición de Enterprise como único plan. Se comprobarán las capacidades de la cuenta realmente conectada al implementar T-16. [Anuncio oficial](https://community.canva.dev/t/autofill-apis-are-now-available-on-canva-pro-and-above/8922).

## Decisiones abiertas (pendientes del usuario)

| Tema | Estado | Qué falta |
|---|---|---|
| X/Twitter | Fuera | Decidir si se contrata el plan de pago de la API v2; sin él no hay credenciales ni forma de verificar el flujo. |
| Google Business Profile | Fuera | Solicitar la aprobación de acceso a la API del proveedor. |
| Twitch | Fuera | Es analítica de streams (`/evolution-twitch`), no publicación; decidir si se quiere un lector de métricas. |
| Smokes reales | Pendientes | Bluesky, Threads, LinkedIn, Pinterest, Instagram (`location_id`, `user_tags`), alt/portada por red; un `Http::fake` no acredita la autorización del proveedor. |
| Smokes de T-14 (IA) y T-16 (Canva) | Pendientes | Claves del proveedor de IA y app registrada en Canva Developers con su cuenta; implementados con contrato y mocks, falta el smoke real. |

## Puertas de cierre

Cada fila pendiente necesita servicio real, persistencia, autorización, contrato API/MCP cuando corresponda, UI usable y prueba del resultado. Una prueba con HTTP simulado no sustituye el smoke del proveedor; una captura de un botón no acredita su comportamiento. El estado global sigue **en progreso** hasta cerrar T-22 y T-23 (ledger).
