# T-12: referencia y preparación

2026-11-05. Investigación previa, sin implementación ni cierre de T-12. Se conserva el alcance de presentaciones, documentos refluibles, pizarras/gráficos y exportaciones reales por marca, API y MCP.

## Autoridad de las observaciones

REA está conectado, pero `list_browser_targets` sobre el endpoint loopback127.0.0.1:9222 devuelve `provider_unavailable/endpoint_unreachable`. Computer Use devuelve inventario vacío con pipe nativo y host de navegador no disponibles. No se observó el editor autenticado en esta sesión, no se cambió la configuración de clientes ni se volvió a pedir autorización. El inventario del2026-11-03 conserva su observación UI histórica; no se presenta como una captura nueva ni como evidencia REA actual.

Las fuentes siguientes son documentación oficial leída durante esta preparación. Una función documentada no demuestra acceso premium en la cuenta gratuita del usuario ni implementación en nuestra aplicación. Las decisiones técnicas que se enumeran después son inferencias para construir una alternativa propia.

## Requisitos concretos de referencia

| Área | Comportamiento documentado que conservar en el alcance | Fuente |
|---|---|---|
| Presentación | Pantalla completa con navegación, vista de audiencia y vista del presentador con notas/próximas diapositivas; reproducción automática y presentación con grabación. | [Modos de presentación](https://www.canva.com/en_gb/help/presentation-modes/) |
| Documento | Texto estructurado, enlaces, bloques visuales, imágenes y diseños; exportación PDF/DOCX. Comentarios/sugerencias y permisos siguen también en T-13; publicación web en T-15. | [Canva Docs](https://www.canva.com/en_gb/help/about-canva-docs/) |
| Office | PPTX para presentaciones y DOCX para Docs; no se debe anunciar el mismo formato en cualquier tipo de diseño. Las fuentes y objetos no soportados pueden afectar el resultado externo. | [Tipos de descarga](https://www.canva.com/help/download-file-types/) |
| Pizarra | Nodos y conectores representan relaciones editables en mapas conceptuales. No basta un PNG dibujado. | [Mapas conceptuales](https://www.canva.com/help/visualize-ideas-concept-maps-whiteboards/) |
| API externa | Sus formatos admitidos son contrato independiente del editor nativo. Canva Connect/Apps se implementan como complemento en T-16, con requisitos reales de cuenta/scopes. | [API de exportaciones](https://www.canva.dev/docs/apps/rest-apis/reference/exports/) |

## Criterios para la implementación propia

La persistencia debe distinguir contenido refluible y objetos de lienzo, conservar revisiones y marca, y permitir editar tablas/datos de gráficos sin convertirlos prematuramente en imágenes. El render derivado no sustituye al documento editable. Documento, presentador y pizarra tendrán UI adecuada a su tarea y reutilizarán recursos/kits/fuentes/autorización existentes.

PPTX/DOCX requieren archivos Office válidos y apertura comprobada, con notas/tablas/contenido verificables. Un archivo renombrado, una captura incrustada como único contenido o una respuesta simulada no acreditan equivalencia. Se especificará qué queda editable y se comprobará su estructura y representación en un lector real. Importación y formatos no soportados mantendrán límites explícitos sin perder el objetivo global.

Como candidatos técnicos, [ProseMirror](https://prosemirror.net/docs/guide/) permite un esquema propio y operaciones de texto estructurado; su [licencia](https://github.com/ProseMirror/prosemirror-state/blob/master/LICENSE) es MIT. [PptxGenJS](https://github.com/gitbrent/PptxGenJS) ofrece generación Office y documentación de [tablas](https://gitbrent.github.io/PptxGenJS/docs/api-tables/) y [gráficos](https://gitbrent.github.io/PptxGenJS/docs/api-charts.html). Estas son posibilidades de implementación, no dependencias instaladas ni prueba de que resuelvan todos los requisitos. La selección final debe cubrir también lectura/edición/reapertura, rendimiento y licencia; no reducir el alcance a lo que exporta una librería.

La siguiente especificación técnica fijará contratos de contenido/tipo, recursos/fuentes por marca, versiones y exportaciones antes de añadir endpoints o migraciones. Durante la QA congelada de T-11 solo se han realizado lecturas y escrito esta nota independiente; no se ha tocado código del producto ni contratos que usa esa ejecución.


## Disponibilidad de referencia autenticada — 2026-11-06

La lectura REA del endpoint CDP localhost9222, filtrada a Canva/Metricool, devolvió provider_unavailable/endpoint_unreachable. Computer Use inicializó correctamente @oai/sky; inventarios list_apps y list_windows no expusieron ninguna ventana de navegador/Canva/Metricool. No se lanzó navegador ni se alteró configuración, sesión o aplicación ajena. No se deriva ninguna observación del editor premium de esta comprobación. Se conserva el inventario documental de fuentes oficiales; la implementación continúa con contratos propios y pruebas locales, sin afirmar paridad observada de controles no accesibles.
