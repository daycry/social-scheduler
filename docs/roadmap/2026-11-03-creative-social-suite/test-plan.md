# Pruebas del bloque de fundamentos y métricas

Ámbito: T-03, T-04–08 y coherencia de marca de T-21. Las demás capacidades de la matriz permanecen abiertas y necesitarán pruebas propias.

## Servicio y contrato

- PHPUnit/Pest: suite completa al integrar contratos compartidos y catálogo MCP. JUnit real, cero errores/fallos/omitidos para acreditar esta ejecución.
- Node: documento, geometría Fabric, cargas cancelables, vídeo recortado, historial, plantillas, límites, raster fraccionario y comparación de píxeles de líneas/trazos.
- Render aislado Chromium: SVG/PDF/PNG reales, imagen privada registrada sin cookies, origen sintético con mapa cerrado y sin continuación de tráfico externo. Probar redondeos y recursos sin simular el proceso en esta capa.
- OpenAPI: lint estructural; respuestas reales pending/kit/documento contra tipos/nulabilidad; paginación de diseños/exportaciones y catálogo MCP.
- Propiedad: otro usuario/marca no accede a diseño, carpeta, kit, recurso o descarga. API bearer sin permiso no escribe; herramientas mixtas seleccionan ability por acción.
- Consultas: preparar 100 recursos <=2 SELECT de recursos; presentar 100 exportaciones <=2 SELECT de exportaciones y primer lote limitado a 50, con página siguiente sin IDs repetidos.

## E2E automáticos

Los tests usan el runner local aislado con SQLite, almacenamiento y cuentas desechables. Las llamadas a redes se impiden; generar una publicación significa crear un **borrador**, no enviarla al proveedor. Ejecutar cada recorrido en `desktop`, `mobile` (375 px) y `mobile-360`.

| ID | Archivo/caso | Criterio observable |
|---|---|---|
| E2E-01 | `metrics-quality.spec.ts` | Cero real conserva cobertura parcial y fecha; comparación desigual se omite; denominador diario visible; sin errores JS ni overflow. |
| E2E-02 | `studio.spec.ts`, persistencia | Crear, añadir texto, guardar/reabrir; PNG 1080² real; subir/registrar y abrir borrador con adjunto en su marca. |
| E2E-03 | `studio.spec.ts`, historial/exportación | Undo/redo, páginas, JPEG/WebP/SVG/PDF con firmas/dimensiones/páginas reales; imagen portable sin URL privada. |
| E2E-04 | `studio.spec.ts`, conflicto | Adelantar revisión desde segundo escritor; 409 conserva trabajo local y permite copia JSON o recuperar versión guardada. |
| E2E-05 | `studio.spec.ts`, raster fraccionario | Diseño 41² a escala 0,5: PNG/JPEG/WebP 21²; subida y registro 201 en servidor. |
| E2E-06 | `brand-reporting.spec.ts` | Cambiar marca en Ads, abrir informes sin query y conservar marca; generar informe y mantener contexto/propiedad. |

Las capturas se revisan visualmente. `qa-gate.py` consume el JSON real de Playwright y debe dar VERDE; no se fabrica un resumen con menos casos que la ejecución. Las pruebas focales durante desarrollo no sustituyen el resultado final tras las correcciones de revisión.

## Manuales externos preservados

| ID | Responsable | Evidencia necesaria |
|---|---|---|
| M-01 | Usuario | Scheduler/worker del VPS ejecutándose, heartbeat y capturas recientes del periodo cerrado. No se pide SSH de nuevo. |
| M-02 | Usuario | Publicación real por red y smokes Bluesky/Threads previamente pendientes. |
| M-03 | Desarrollo + usuario al configurar | Renderer del servidor: Node/Chromium/fuentes/cola media, artefacto real con imagen/vídeo local y R2. Las pruebas locales no certifican Linux/R2 en producción. |
| M-04 | Usuario, cuando lo retome | Claude Desktop: sigue aplazado; no volver a abrir autorización. |

El fallo de un requisito externo permanece explícito. La suite no declara paridad completa ni operación en producción por tener estas pruebas locales correctas.
