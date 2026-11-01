---
spec: metricool-composer-parity
descripcion: Paridad funcional del creador de publicaciones con Metricool en las seis redes existentes y Twitter/X, incluida musica, media por destino y opciones avanzadas por red.
estado: borrador
creado: 2026-10-31
actualizado: 2026-10-31
evaluacion: pendiente
design: pendiente
plan: pendiente
---

# Paridad completa del creador de publicaciones

> Archivo del alcance inicial, conservado como backlog. La instrucción posterior del usuario acota la entrega a audio para Reels y mejoras relacionadas; manda la [spec vigente](spec.md). Este documento conserva la investigación anterior, no acredita implementación ni aprobación del resto del inventario.

> Inventario contrastado: [feature-matrix.md](feature-matrix.md).
> Investigación y cuestiones técnicas: [discovery.md](discovery.md).
> Base de código inspeccionada: `8fe2f230853d6edc46667a61d08d23d0110edf5d`.

## Contexto y objetivo

El usuario pide que se puedan insertar canciones y amplía el requisito a «todas las opciones que tiene Metricool para crear publicaciones, ha de tener lo mismo». Al concretar las redes indica Threads y Twitter. La aplicación ya contiene seis conectores: Instagram, Facebook, YouTube, TikTok, Bluesky y Threads. Twitter/X es nuevo. El usuario está consultando esta instalación local a través de ngrok.

El objetivo es equivalencia funcional de creación, adaptación, programación y publicación. No basta con mostrar botones: la opción debe conservarse al guardar, validarse y llegar al publicador correcto, o llevar al flujo manual cuando ese sea también el funcionamiento de referencia. El diseño visual será propio.

Esta iniciativa amplía el alcance de los MVP anteriores sin cambiar sus criterios históricos ni declarar superados sus smokes pendientes. La PR #16 y sus cinco commits de continuación permanecen separados de este trabajo, en la rama `feature/metricool-composer-parity`.

## Decisiones confirmadas del usuario

1. Música forma parte del requisito, además de las opciones restantes del compositor.
2. Se busca paridad completa, no otro recorte de MVP que descarte silenciosamente funciones de creación.
3. Incluir Threads y Twitter/X. Threads requiere completar la configuración local; X necesita conector nuevo.
4. Conservar las otras redes ya implementadas. LinkedIn, Pinterest y Google Business se inventarían como referencia, pero no se incorporan como redes nuevas por esta selección. Si el usuario amplía después esa selección, se actualiza expresamente el alcance.
5. Las pruebas del VPS y los smokes reales previos siguen a cargo del usuario. Claude Desktop continúa aplazado y no bloquea esta iniciativa.
6. El flujo principal también es conversacional: el agente envía contenido mediante el MCP de Metricool y el usuario observa música en la publicación final, pero desconoce cómo se añade. Nuestro MCP debe permitir seleccionar música nativa cuando se solicite, sin obligar a completar la canción en el editor web; no se da por demostrado que el MCP de referencia la seleccione.

Ejemplo aportado por el usuario: https://www.instagram.com/p/DfHNz86ku9E/. Tras fallar el lector web, se inspeccionaron sus pestañas abiertas con autorización: Instagram muestra «aipocalipsis · Botón automático»; Metricool no muestra una pista seleccionada y su adjunto MP4 ya contiene audio no silencioso. Véase [evidencia y límites de la conclusión](discovery.md#resultado-de-la-inspección-real-del-ejemplo). La expresión «reels de audio incrustados» no confirma una selección de catálogo por el MCP. El posible reconocimiento automático de Instagram queda como inferencia.

El detalle de aprobaciones, la arquitectura, la viabilidad de cada API y el plan aún no están aprobados. El estado `borrador` refleja esas cuestiones pendientes; no implica que la funcionalidad esté implementada.

## Alcance

### Dentro

- Compositor común: plantilla y adaptación de texto, media y metadatos por destino; menús, contadores, menciones, emojis, textos guardados, hashtags, UTM, enlaces, notas y persistencia de preferencias.
- Música de catálogo donde Metricool la ofrece, preescucha, asociación a la publicación y control de disponibilidad por cuenta y formato. Instagram Reels y TikTok se verifican por separado.
- Media: biblioteca, subida e importación, fuentes externas e integraciones de diseño, ordenación, edición de imagen/vídeo, alt text, portadas y fotogramas. Las integraciones de terceros necesitan su vía oficial y condiciones verificadas.
- Opciones de Instagram, Facebook, TikTok, YouTube, Bluesky, Threads y X recogidas en la matriz; perfiles/tipos de cuenta y formatos condicionan su disponibilidad.
- Borrador, biblioteca, programar, publicar ahora, recurrencia, varias marcas, aprobación, avisos para publicación manual, duplicación y recuperación cuando formen parte del flujo de creación de referencia.
- Previsualización específica por destino y formato, móvil/escritorio; aviso de aproximación y distinción entre preescucha de una canción y mezcla final.
- Paridad de datos y validación entre web y MCP para las nuevas opciones de publicaciones; no se fija aquí un número de herramientas nuevo.
- Accesibilidad, aislamiento entre usuarios, control de capacidades, tratamiento de errores y smokes de publicación con evidencia.

### Fuera de esta iniciativa

- Incorporar LinkedIn, Pinterest o Google Business sin ampliar la selección de redes.
- Replicar el resto de productos de Metricool: analítica, reporting, inbox, Flows, facturación, agencia o marca blanca, salvo dependencias estrictas de una opción de creación enumerada.
- Replicar sus planes comerciales o restringir artificialmente nuestras opciones porque Metricool las cobre. Las cuentas/licencias y costes reales de proveedores sí deben verificarse.
- Descargar música fuera de las vías autorizadas, extraer credenciales de Metricool o utilizar sus endpoints privados como backend.
- Declarar autopublicación de una opción solo por existir en Metricool: la integración y el tipo de acceso de esta aplicación deben acreditarse.

## Flujo esperado

1. Elegir marca, cuentas y formato; ver los requisitos de conexión/configuración.
2. Escribir una plantilla y adjuntar media; adaptar cada destino cuando se necesite.
3. Configurar las opciones específicas. Buscar y escuchar una canción si ese destino la admite.
4. Ver la previsualización, errores, incompatibilidades y modalidad automática/manual por destino.
5. Guardar, programar, publicar ahora, enviar a revisión o generar una recurrencia. Las acciones con varias publicaciones muestran el resultado previsto antes de confirmarlo.
6. El servidor valida propiedad y capacidades, conserva todos los campos y publica con los mismos datos vistos en el editor.
7. Seguir el estado por destino y distinguir publicado, pendiente, manual pendiente, rechazado, error y reintento. No tratar una notificación como publicación confirmada.

## Requisitos de datos y comportamiento

- Media común con herencia y desvinculación por destino, conservando el orden. El diseño decidirá cómo se representa sin romper los posts existentes.
- Opciones tipadas por red y tipo; normalizar y rechazar campos incompatibles también en backend y MCP.
- Identificador estable del audio y metadatos mínimos para mostrarlo. Las URL temporales de preescucha no se convierten en identificadores permanentes ni credenciales públicas.
- Distinguir audio incluido en el archivo multimedia de una pista asociada nativamente por la plataforma. La aceptación del segundo caso exige comprobar la asociación e identidad de la pista en el resultado; escuchar música dentro del vídeo por sí solo no lo acredita.
- El agente debe poder consultar las capacidades y el catálogo autorizado, identificar y seleccionar una pista inequívoca, crear o editar el post, consultar la selección conservada y enviarlo a publicar. Los nombres y parámetros concretos de las herramientas se fijarán al verificar los contratos oficiales; no se inventan a partir del resultado visual de Metricool.
- Una URL de un Reel, una URL de audio y un identificador de pista no son intercambiables. La resolución de referencias, si se admite, debe usar una vía oficial y validar cuenta y destino. La compatibilidad de Instagram no implica compatibilidad de Facebook.
- Configuración de mezcla/fragmento solo cuando la API del destino lo admita y el comportamiento de referencia esté comprobado. No trasladar los límites de Instagram a TikTok.
- Capacidad por instalación, proveedor, cuenta y formato. Una cuenta Business y una personal no reciben promesas idénticas.
- Recurrencias con zona horaria y DST, destinos/marcas definidos, vista previa y deduplicación. Cambiar la programación no reutiliza accidentalmente una publicación ya enviada.
- Aprobaciones con política explícita, revisores autorizados y reinicio tras editar cuando corresponda. Un rechazo bloquea la publicación. Durante el desarrollo se usan correos y proveedores simulados.
- Mantener la idempotencia de `PublishPostTarget`, checkpoints de subida y contenedores, cuotas y reintentos; no generar duplicados al reintentar música o metadatos.

## Manejo de errores

| Caso | Resultado requerido |
|---|---|
| Conector sin configurar | Identificar claramente la configuración pendiente; no simular una cuenta conectada. Revisar la descubribilidad de Threads en la UI. |
| API o permiso sin acceso | Explicar el requisito; conservar borrador y selección; no eliminar opciones silenciosamente al publicar. |
| Audio retirado o no disponible | Mostrar el motivo y permitir cambiarlo; no publicar silenciosamente sin la canción seleccionada. |
| Preescucha caducada | Recuperar una URL válida cuando la API lo permita; no reutilizar una URL expirada como pista. |
| Catálogo o proveedor temporalmente caído | Error recuperable; conservar el trabajo del usuario y evitar que una búsqueda bloquee todo el compositor. |
| Media/opción incompatible | Error del destino afectado, reproducible en web y MCP, antes del envío. |
| Un destino falla y otro se publica | Conservar ambos estados; no repetir el ya publicado. |
| Edición de post aprobado | Aplicar la política de revisión definida; no publicar una versión no aprobada. |

## Criterios de aceptación

- [ ] CA-01 — Cada fila activa de la matriz tiene estado, fuente, condición de disponibilidad, implementación o incidencia justificada y prueba correspondiente; no quedan exclusiones heredadas del MVP sin decisión expresa.
- [ ] [GWT] CA-02 — Dadas varias cuentas, al cambiar formato se muestran solo las opciones aplicables y sus condiciones, conservando las selecciones válidas.
- [ ] [GWT] CA-03 — Dada una plantilla con destinos desvinculados, al modificar texto/media/metadatos de uno y guardar/reabrir, los demás conservan su contenido y la herencia funciona según la decisión de diseño.
- [ ] [GWT] CA-04 — Dado un Reel elegible, al buscar, escuchar y seleccionar música, guardar y publicar conserva la pista elegida; un fallo no provoca publicación silenciosa sin audio. El flujo completo también funciona desde MCP, sin completar la selección en la web; la evidencia final acredita la pista nativa y no solo una banda sonora dentro del archivo. Facebook se verifica separadamente y no hereda este resultado.
- [ ] [GWT] CA-05 — Dadas cuentas TikTok de tipos distintos, al editar música aparecen las opciones realmente admitidas por cada una y se respetan sus incompatibilidades.
- [ ] [GWT] CA-06 — Dado contenido con portada, alt text, etiquetas, ubicación u otra opción admitida, guardar/reabrir y el envío conservan cada valor en el destino correcto.
- [ ] [GWT] CA-07 — Dado un formato de X admitido, OAuth, validación, subida y publicación usan su cuenta y permisos; cuotas o errores no duplican el post.
- [ ] [GWT] CA-08 — Dada una cuenta Threads configurada, las opciones ampliadas de Threads se guardan y llegan a su API según su capacidad verificada; sin configurar no se declara utilizable.
- [ ] [GWT] CA-09 — Dada una recurrencia o copia entre marcas, la vista previa y el resultado respetan fechas, zonas y redes elegidas, con confirmación y sin duplicados.
- [ ] [GWT] CA-10 — Dado un post enviado a revisión, el worker solo publica la versión y estado permitidos por la política; editar o rechazar aplica el bloqueo correspondiente.
- [ ] [GWT] CA-11 — Dada publicación manual, el aviso permite completar el trabajo y el estado distingue aviso enviado de publicación real.
- [ ] [GWT] CA-12 — Dado el mismo payload web y MCP, la propiedad, límites, campos y errores son equivalentes; un usuario no accede a media, cuentas o catálogos privados de otro.
- [ ] CA-13 — Regresión automática, revisión independiente y QA con evidencia pasan; los smokes reales pendientes quedan explícitos y no se cuentan como aprobados.
- [ ] CA-14 — La paridad no se declara completa hasta contrastar la matriz con el compositor real por las redes y tipos de cuenta seleccionados; los elementos aún no verificables conservan su incidencia abierta.

## Verificación previa a construir

1. Cerrar inventario de producto con las fuentes primarias y la UI real cuando sea accesible. El enlace privado de Metricool no fue accesible con el lector web; no se afirma haberlo inspeccionado hoy.
2. Contrastar las capacidades y contratos con documentación oficial de cada red, no inferir sus parámetros desde la UI de Metricool. La documentación de Meta Audio devolvió HTTP 429 en esta sesión.
3. Resolver las incógnitas de acceso de Meta, TikTok Business y X y los proveedores de media/diseño.
4. Evaluar, diseñar y planificar la ampliación con trazabilidad a la matriz. Los requisitos pendientes no se convierten en contratos inventados.

## Supuestos e incógnitas

- El usuario pide paridad de producto; no se presupone acceso a APIs privadas o programas de partners.
- La elección concreta de revisores y política de colaboración necesita concretarse al diseñar el flujo.
- La presencia de código no acredita conexión/configuración ni publicación real. Threads está habilitado localmente, pero faltan su App ID y secreto.
- Hay discrepancias entre artículos oficiales de Metricool en límites y opciones; se registran en `discovery.md` y se resuelven antes de fijar constantes.
- Este documento no contiene estimaciones inventadas ni acredita una implementación nueva. Las matrices históricas del 23 de octubre son contexto, no estado actual de nuestro código.
