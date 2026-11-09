---
estado: en-progreso
creado: 2026-11-03
actualizado: 2026-11-04
---

# Suite social y creativa por marcas

El usuario solicita una aplicación unificada con equivalencia funcional de Metricool y Canva, incluidas funciones de sus planes de pago, experiencia moderna y usable, APIs y MCP. Autoriza trabajo independiente hasta completar el alcance. Su cuenta de referencia Canva es gratuita y está autenticada en Edge. Añade expresamente revisar la corrección de las métricas.

## Alcance

- Gestión social: compositor, formatos y opciones por destino, media y preview por red, planificación y recurrencias, aprobación, publicación automática/manual, conexiones, analítica, competidores, Ads, inbox, SmartLinks, informes y organización por marcas.
- Estudio propio: diseños editables, páginas, lienzo, texto, formas, imágenes, capas, grupos, transformaciones, alineación, bloqueo, filtros, recorte, plantillas, kits de marca, historial, autosave y control de versiones.
- Capacidades creativas adicionales: exportaciones, adaptación de tamaños, documentos, presentaciones, vídeo/audio, colaboración, generación/edición asistida por IA y publicación web. Se mantiene la matriz completa; una función sin implementar sigue abierta aunque pertenezca a un plan de pago de referencia.
- Cada capacidad tiene acceso coherente por interfaz, API y MCP, compartiendo servicios, validación, propiedad de usuario/marca y estado de trabajos.
- Integración oficial Canva como complemento para cuentas elegibles: OAuth, diseños, plantillas, retorno del editor y exportación. El documento externo se distingue del documento nativo.
- Auditoría de métricas: contratos por red, fechas y zonas horarias, stocks/flujos, nulos, cobertura, paginación, frescura, caché, agregados y tasas. Contrastar con datos del proveedor cuando se disponga de lectura autorizada.

## Restricciones de ejecución

Los diseños y kits tienen propietario y marca explícitos. La marca actual del shell no concede acceso a un recurso. Una exportación identifica la revisión que representa; modificar un diseño no cambia archivos ya publicados. Los borradores y posts existentes se preservan.

Se construyen funciones propias y se usan motores/bibliotecas con licencia compatible. Los catálogos o servicios externos conservan sus derechos y requisitos reales; un botón o una respuesta simulada no acreditan una capacidad equivalente. No se compran planes ni se publican posts reales durante desarrollo. Se mantienen los smokes de servidor/redes y Claude Desktop que el usuario dejó pendientes.

## Criterios de aceptación

- CA-01: inventario por pantalla/flujo, con evidencia REA, observación nativa y fuentes oficiales separadas de inferencias; estados verificables existente/parcial/ausente/bloqueado por requisito externo.
- CA-02: marcas, diseños, kits, activos, exportaciones y resultados sin cruces accidentales. Cambiar marca desde un detalle vuelve al contexto correcto y protege cambios pendientes.
- CA-03: crear, editar, guardar, reabrir, duplicar, archivar/restaurar y buscar diseños conserva el documento y su resultado; conflicto de revisión no sobrescribe cambios.
- CA-04: herramientas de lienzo, texto, objetos, imágenes y páginas operativas con ratón y teclado; undo/redo y guardado funcionan con grupos y capas bloqueadas.
- CA-05: plantillas y kit por marca se aplican a copias independientes; colores, fuentes y activos disponibles se identifican correctamente.
- CA-06: exportaciones producen archivos reales con dimensiones, orden, contenido y tipo correctos. El resultado se reutiliza en el compositor sin perder trazabilidad al diseño/revisión.
- CA-07: APIs y MCP permiten los mismos flujos con validación compartida, errores claros, permisos y paginación completa; no requieren terminar pasos manuales en la web para operaciones soportadas.
- CA-08: métricas y tasas se calculan con cobertura compatible; nulo no se transforma en cero y los límites/fallos/frescura de los proveedores se muestran.
- CA-09: cada brecha social y creativa de la matriz tiene implementación y verificación propias antes de declararse completa; no se marca paridad total por cerrar solo el editor gráfico.
- CA-10: responsive y accesibilidad verificados, pruebas de servicio/contrato y E2E sobre flujos reales locales; revisión independiente y QA con evidencias. Estado del VPS y publicaciones externas permanece separado.

## Datos y supuestos

Base local inicial `2b38fb8`, rama `feature/creative-social-suite`. Laravel 13/PHP 8.3+, Vue 3/Inertia/Tailwind. La parte social tiene seis redes y 27 herramientas MCP antes de esta iniciativa. Media está hoy separada por usuario; el estudio incorpora una asociación explícita por marca.

El esquema del estudio es propio y versionado, independiente de URLs temporales y secretos. Fabric es un adaptador de interacción, no el formato público del documento. Los trabajos devuelven estado e identificación de salida; las URLs privadas se regeneran al consultarlas.

[Plan](improvement-plan.md) · [Ledger](tasks.md) · [Contrato del documento](document-contract.md).
