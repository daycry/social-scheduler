# Inventario funcional de referencia y cobertura de la suite

Fecha: 2026-11-03. Este inventario conserva el alcance completo solicitado y separa la existencia de código, las pruebas locales y los requisitos de proveedores externos. Las funciones pendientes continúan en el ledger; esta entrega no acredita paridad completa con Canva o Metricool.

## Evidencia utilizada

| Evidencia | Qué se observó | Qué no acredita |
|---|---|---|
| REA, captura pública controlada `ev_2837561e17e0c1d8f941b44c65501af862c5e255f6ae25ec79446deb3b38b632` | Edge Chromium 156 abrió Canva y encontró Cloudflare HTTP 403. Captura de red sin cuerpos ni valores de cabeceras. Proceso propio cerrado al terminar. | No se observó el editor ni se accedió a funciones premium por esta vía. El registro completo saneado permanece fuera de Git, en `storage/framework/testing/rea-canva-public.json`. |
| UI Automation nativa del Edge autenticado del usuario | Proyectos, diseño existente, Archivo, Redimensionar, guardado, Compartir, comentarios, Plantillas, Elementos, Texto, Marca, Subidos, Herramientas, Apps, páginas, capas, notas, temporizador, zoom y presentación. No se modificó el diseño. | No es evidencia CDP/REA. Una opción visible no demuestra que su versión premium esté habilitada en la cuenta gratuita. No se guardan URLs privadas del diseño. |
| Investigación del repositorio | Servicios, conectores, políticas, rutas, MCP, compositor, analítica, Ads, informes y almacenamiento actuales. | La existencia de una clase o botón no garantiza publicación real ni respuesta de una API externa. |
| Pruebas locales del nuevo estudio | Backend, serialización Fabric, conflictos, capas, páginas, cinco formatos de exportación, asociación de media y borrador; recorridos de navegador en escritorio y móviles. | No demuestra MP4/GIF, colaboración en tiempo real, IA configurada ni integración externa Canva. |
| Lectura directa de Meta | Actividad real reciente de dos cuentas de la marca local 55, contrastada con ausencia de snapshots recientes. | No acredita el estado del VPS ni que se hayan recuperado/escrito los datos. Véase [auditoría](testing/metrics/audit-2026-11-03.md). |

El inventario de las funciones de pago se apoya en documentación oficial. La cuenta Canva Free no permite observar todos sus comportamientos premium; esos detalles conservan esa limitación. Las plantillas nativas del proyecto son originales y no se copian catálogos privados de las aplicaciones de referencia.

## Gestión social

«Previa» significa capacidad existente con evidencia en los ledgers anteriores, que no se vuelve a atribuir a una ejecución de esta iniciativa. Web/API/MCP indica el canal disponible, no una garantía universal para todas las redes.

| ID | Capacidad | Estado actual y brecha concreta | Canales | Tarea |
|---|---|---|---|---|
| M01 | Marcas y conexiones | Previa: marcas con zona horaria y propiedad; seis redes implementadas. Smokes reales de Bluesky/Threads siguen pendientes del usuario. | Web/MCP | T-18 |
| M02 | X/Twitter, LinkedIn, Pinterest, Google Business y Twitch | Pendientes como conectores completos. Mantener autorización, scopes, publicación y métricas de cada proveedor por separado. | Pendiente | T-18 |
| M03 | Compositor global y presets por destino | Previa: texto, medios, ajustes globales y por cuenta; colaboradores/IA Instagram y título Facebook incorporados en iniciativa anterior. | Web/MCP | T-17/18 |
| M04 | Música en publicaciones | Previa: búsqueda paginada y selección cuando el contrato de la red admite audio; archivos con audio ya mezclado usan el flujo multimedia. No equivale a disponer de todos los catálogos para cualquier cuenta/red. | Web/MCP | T-18 |
| M05 | Medios diferentes por destino | Pendiente: el formulario actual comparte la lista de media. Se necesita persistencia, validación y publicación con overrides reales. | Pendiente | T-17 |
| M06 | Preview por red | Parcial: previews actuales; falta fidelidad por formato y opciones de cada destino. | Web | T-17 |
| M07 | Portadas, alt, localización y etiquetado | Parcial por conector. Inventariar campos por red/formato antes de anunciarlos como universales. | Parcial | T-18 |
| M08 | Calendario/lista/mejores horas | Previa: vistas, creación, reprogramación y recomendaciones con datos disponibles. | Web/MCP | T-19 |
| M09 | Aprobación y roles | Pendiente: propietario único no constituye un flujo de aprobación de equipo. | Pendiente | T-13/19 |
| M10 | Publicación manual y notificaciones | Pendiente: estados, avisos y confirmación del usuario para formatos no publicables automáticamente. | Pendiente | T-19 |
| M11 | Recurrencia, autolistas y lotes | Parcial: autolistas/CSV existentes; faltan recurrencias y automatización integral con idempotencia. | Parcial | T-19 |
| M12 | Biblioteca, textos, hashtags y notas | Previa: reutilización de contenidos y media por usuario; Studio añade pertenencia explícita de recursos a una marca. | Web/MCP | T-09/17 |
| M13 | Analítica de cuentas | Correcciones nuevas: pares de engagement, selección YouTube, comparación compatible, cobertura/frescura y avisos UI. Recolección operativa local presenta brecha; VPS sin comprobar. | Web/API/MCP | T-03 |
| M14 | Analítica de publicaciones | Previa con límites: publicaciones propias y presupuesto de lectura reciente, no todo el universo nativo. Ampliar importación histórica y cobertura por proveedor. | Web/MCP | T-03 |
| M15 | Analítica de todas las marcas/campañas | Previa más corrección de engagement de campañas y calidad de comparación; conservar el aislamiento de propietario. | Web/API/MCP | T-03 |
| M16 | Competidores | Parcial: cobertura actual centrada en Instagram. Pendiente ampliar proveedores y comparaciones compatibles. | Web/MCP parcial | T-20 |
| M17 | Inbox | Parcial: comentarios Facebook/Instagram. DMs y acciones adicionales requieren contratos/scopes de proveedor; no se simulan conversaciones. | Web/MCP parcial | T-20 |
| M18 | Informes y distribución | Previa: PDF/CSV por marca. Pendientes constructor avanzado, plantillas y distribución ampliada. Revisar selección de marca actual. | Web/MCP | T-21 |
| M19 | Ads | Parcial: lectura Meta; origen/caché deben respetar marca. Gestión de campañas y Google/TikTok Ads no implementadas. | Web | T-21 |
| M20 | SmartLinks | Parcial: módulo existente. Pendientes bloques enriquecidos, profundidad de editor y automatización. | Web | T-20 |
| M21 | Equipos/clientes y permisos | Pendiente: roles y pertenencia real, invitaciones y aislamiento verificable de colaboradores. | Pendiente | T-13 |
| M22 | Integraciones/API/MCP | MCP previo 27 herramientas; esta iniciativa añade nueve del estudio y API v1 compartida. No confundir esta cobertura con todas las funciones de los dos productos. | Web/API/MCP | T-08 |

Referencias oficiales de alcance social: [introducción de Metricool](https://help.metricool.com/es/conoce-metricool-p0zdh), [planes premium](https://metricool.com/es/premium/), [roles y permisos](https://help.metricool.com/es/roles-y-permisos-en-metricool-rhljq), [SmartLinks](https://help.metricool.com/es/smartlinks-en-metricool-guia-completa-p7p83), [conexión de plataformas](https://help.metricool.com/es/como-conectar-las-redes-sociales-y-las-plataformas-de-anuncios-a-metricool-qrkvn) y [límites de su MCP](https://help.metricool.com/faqs-about-the-metricool-mcp-1i3w0). La aplicación propia puede exponer por MCP funciones adicionales; no se atribuyen al MCP de referencia.

## Estudio creativo

| ID | Capacidad | Estado actual y brecha concreta | Web/API/MCP | Tarea |
|---|---|---|---|---|
| C01 | Catálogo y carpetas por marca | Nuevo: crear/listar/buscar/duplicar/archivar/restaurar, carpeta y paginación. Sin miniaturas persistentes de cada diseño todavía. | Sí | T-04/06/08 |
| C02 | Documento propio y revisiones | Nuevo: JSON tipado versionado, snapshots inmutables, guardado optimista 409. No se ejecuta JSON de Fabric externo. | Sí | T-04 |
| C03 | Lienzo, selección y geometría | Nuevo: selección simple/múltiple, mover/escalar/rotar/sesgar, alinear, ordenar y reflejar. | Web; geometría declarativa API/MCP | T-05/06 |
| C04 | Capas, grupos y bloqueo | Nuevo: grupos anidados, capas, visibilidad y bloqueo con serialización y pruebas de matrices reales. | Web; documento API/MCP | T-05/06 |
| C05 | Texto y tipografía | Nuevo: contenido, familia, tamaño, peso, cursiva, subrayado, alineación, espaciado. Familias dependen de fuentes instaladas; no hay catálogo/carga de fuentes propias. | Web; documento API/MCP | T-05/10 |
| C06 | Formas y dibujo | Nuevo: formas, líneas, trazos tipados y dibujo. Pendientes conectores inteligentes y bibliotecas enriquecidas. | Web; documento API/MCP | T-05/12 |
| C07 | Imágenes y recursos | Nuevo: subida real, asociación de media por marca y proxy privado con rangos; el MCP permite adjuntar media ya lista antes de usarla en un diseño. | Sí | T-04/08/10 |
| C08 | Crop, filtros, sombra y gradientes | Nuevo: recorte, brillo/contraste/saturación/desenfoque y filtros, sombras, opacidad y gradientes. Pendientes máscaras complejas y edición fotográfica avanzada. | Web; documento API/MCP | T-05/10 |
| C09 | Quitar fondo/edición localizada | Pendiente: pipeline real y proveedor/modelo configurado. No se entrega una imagen sin modificar como si fuera un resultado. | Pendiente | T-10/14 |
| C10 | Undo/redo y recuperación | Nuevo: historial local, autosave en cola, conflicto sin sobrescribir y copia JSON local de recuperación. Historial persistido por revisiones; falta interfaz avanzada para compararlas. | Web; revisiones API/MCP | T-04/06/13 |
| C11 | Páginas | Nuevo: añadir/duplicar/borrar/ordenar, cambiar de página sin introducir respuestas de cargas antiguas. | Web; documento API/MCP | T-05/06 |
| C12 | Plantillas y marca | Nuevo: 14 plantillas originales, biblioteca propia por marca, campos literales, copias independientes, kit aplicable y selector real de logos. Referencia trazable conservada con undo/redo. | Sí, servicios comunes web/API/MCP | T-09 |
| C13 | Redimensionar y variantes | Nuevo: canvas/contain/cover con geometría proporcional, todas las páginas y hasta doce variantes transaccionales; reapertura y exportación reales. El contenido de campos es común al lote; automatización de datasets por fila sigue en T-14. | Sí, servicios comunes web/API/MCP | T-09/14 |
| C14 | PNG/JPEG/WebP | Nuevo: píxeles reales, escala/calidad/fondo transparente, subida validada y uso como borrador. Revisión y páginas vinculadas. | Sí | T-07/08 |
| C15 | SVG/PDF | Nuevo: SVG con medios embebidos y PDF multipágina rasterizado; no PDF vectorial editable. Descarga privada temporal en servidor. | Sí | T-07/08 |
| C16 | PPTX y otros formatos | Pendiente: exportación real con contrato específico y prueba de apertura. | Pendiente | T-12 |
| C17 | Vídeo básico en lienzo | Nuevo parcial: añadir recurso, trimStart/trimEnd, fotograma preparado, volumen/muted y reproducción. Exportaciones actuales son estáticas. | Web; documento API/MCP | T-11 |
| C18 | Timeline/audio/transiciones/subtítulos | Pendiente: edición temporal real, pistas, sincronización y mezcla. | Pendiente | T-11 |
| C19 | MP4/GIF | Pendiente: render audiovisual y validación de duración, codec, audio y frames. No se anuncia como formato disponible en la API actual. | Pendiente | T-11 |
| C20 | Presentaciones | Parcial: páginas y plantillas panorámicas; faltan modo presentar/notas/temporizador y exportaciones específicas. | Parcial | T-12 |
| C21 | Documentos | Pendiente: flujo de texto refluible, tablas y páginas, distinto de texto colocado sobre un lienzo. | Pendiente | T-12 |
| C22 | Pizarras y gráficos | Parcial: dibujo/formas; faltan pizarra expandible, conectores y datos/gráficos editables. | Pendiente | T-12 |
| C23 | Comentarios y colaboración | Pendiente: roles, invitaciones, comentarios, enlaces y concurrencia real con dos clientes. | Pendiente | T-13 |
| C24 | IA generativa y asistencia | Pendiente: trabajos reales, resultados, costes/errores y credenciales del proveedor configurado. No basta con una UI de chat vacía. | Pendiente | T-14 |
| C25 | Sitios web/interactividad | Pendiente: editor específico, preview responsive, navegación, hosting/publicación y acceso. | Pendiente | T-15 |
| C26 | Stock, apps y recursos premium | Parcial: subida propia y fuentes externas ya integradas. No hay derechos automáticos sobre catálogos de pago de Canva. Cada proveedor conserva licencia y acceso. | Parcial | T-10/16 |
| C27 | Integración oficial Canva | Pendiente: OAuth, selección de marca/cuenta, editor externo de retorno, jobs y plan/capacidades elegibles. | Pendiente | T-16 |
| C28 | Automatización creativa por API/MCP | Nuevo: nueve herramientas nativas para diseños/kits/recursos/render, con paginación y exportación sin abrir la web. Automatizaciones avanzadas conservan sus tareas abiertas. | Sí para el alcance actual | T-08/09 |

Referencias oficiales de edición: [diseños](https://www.canva.dev/docs/apps/designs/), [elementos](https://www.canva.dev/docs/apps/elements/), [edición](https://www.canva.dev/docs/apps/design-editing/), [capas](https://www.canva.com/help/finding-and-arranging-layers/), [bloqueo](https://www.canva.com/help/lock-and-unlock-elements/), [kit](https://www.canva.com/help/brand-kit/), [plantillas de marca](https://www.canva.com/help/using-brand-templates/), [imágenes](https://www.canva.com/help/image-settings/), [vídeo](https://www.canva.com/help/creating-and-editing-videos/), [presentaciones](https://www.canva.com/en_gb/help/presentation-modes/), [pizarras](https://www.canva.com/en_gb/help/expand-to-whiteboard/) y [sitios](https://www.canva.com/help/publishing-canva-websites/).

## Integración externa frente a motor propio

El editor nativo usa un [contrato de documento propio](document-contract.md). Fabric aporta interacción y render de objetos, con licencia MIT; el formato persistido no depende de sus clases. PDF usa pdf-lib, también MIT. Se comparte el motor de render entre navegador y trabajos de servidor para reducir diferencias de geometría.

Canva Connect proporciona operaciones sobre diseños y trabajos externos; el SDK Apps se ejecuta dentro de Canva. No son una licencia para incrustar su editor completo ni convierten una cuenta gratuita en Pro. El complemento oficial se implementará de forma separada y conservará sus requisitos de registro, revisión, scopes y plan. [Connect](https://www.canva.dev/docs/connect/) · [integración Apps](https://www.canva.dev/docs/apps/integrating-canva/).

La API oficial de exportación enumera formatos según su contrato; no se asume SVG por existir en un editor visual. Los enlaces externos de exportación son temporales. Los artefactos nativos se guardan en almacenamiento privado y usan su propio enlace firmado de diez minutos. [Contrato oficial de exportación](https://www.canva.dev/docs/apps/rest-apis/reference/exports/create-design-export-job/).

Autofill/Brand Templates amplió la elegibilidad a Pro y planes superiores en septiembre de 2026; no se conserva la antigua suposición de Enterprise como único plan. Se comprobarán las capacidades de la cuenta realmente conectada al implementar T-16. [Anuncio oficial](https://community.canva.dev/t/autofill-apis-are-now-available-on-canva-pro-and-above/8922).

## Puertas de cierre

Cada fila pendiente necesita servicio real, persistencia, autorización, contrato API/MCP cuando corresponda, UI usable y prueba del resultado. Una prueba con HTTP simulado no sustituye el smoke del proveedor; una captura de un botón no acredita su comportamiento. El estado global sigue **en progreso** hasta resolver la matriz, las revisiones y los criterios del [ledger](tasks.md).
