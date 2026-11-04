# Entrada de implementación de T-10

Exploración de código y dependencias realizada el 2026-11-04 por un agente de solo lectura. **No es implementación ni cierre de T-10.** Conservar fuentes reales, recursos por marca, búsqueda, máscaras, filtros y eliminación general de fondo; no reducir el alcance a un filtro o fondo uniforme.

## Fronteras actuales

- `config/media.php`, `MediaUploadService::kindFor/extensionFor` y `MediaProbe::image` solo admiten imágenes/vídeos. Una fuente TTF/WOFF necesita recurso/modelo/servicio tipográfico propio, sin hacerse pasar por `MediaAsset kind=image`.
- `StudioAssets` aporta el patrón de propiedad explícita usuario/marca, ready, proxy privado, nosniff y límites. Mantener tipografías en un namespace separado.
- `StudioService::kit/updateKit` almacena nombres de familias; no instala archivos. Preservar esa compatibilidad y la referencia trazable de T-09.
- `resources/js/Studio/canvas.js::waitForStudioFonts` espera `document.fonts.load/ready` con timeout, pero no registra `FontFace` ni detecta familia ausente sin fallback. Editor, miniaturas y render deben compartir el cargador real antes de medir texto.
- Máscaras: descriptor tipado limitado en `StudioDocument.php` y `Studio/document.js`, construcción de clipPath desde clases conocidas en `canvas.js`, serialización y actualización. No admitir clases arbitrarias de Fabric.
- Eliminar fondo debe crear un PNG derivado auténtico, conservando original/dimensiones/alpha y asociación de marca. Hace falta un motor/modelo real y su licencia; no hay evidencia de uno utilizable instalado.
- `StudioRenderer.php`, `scripts/studio/render.mjs` y `render-entry.js` empaquetan bytes propios y los sirven en un mapa cerrado sin red. Añadir fonts por bytes/ArrayBuffer con autorización y presupuesto combinado. CSP actual `font-src data:`; no introducir URL arbitraria para resolverlo.
- `Studio/previews.js` debe incorporar identidad de marca/versión de fuentes a su caché y cargar las mismas fuentes; hoy usa nombres locales y recursos vacíos.

## Lo existente y lo que falta probar

Hay brillo, contraste, saturación, blur, grises, sepia, inversión y `cropX/Y` en motor/Inspector y contratos espejo. La búsqueda de recursos es local por nombre. No se encontró validación de crop contra el tamaño real del archivo ni pruebas que midan píxeles de cada filtro/crop, fuentes subidas, máscara o eliminación de fondo.

Reutilizar tests de RGBA/export real en `tests/frontend/studio-rendering.test.mjs` y renderer Chromium en `studio-renderer.browser.test.mjs`. Los tests PHP de renderer simulan el proceso y no acreditan la inferencia o rasterización real.

Primeros RED propuestos: aislamiento entre marcas del mismo propietario; tipografía corrupta y familia inexistente; RGBA antes/después de filtros/crop/máscara; alpha con conservación de sujeto tras inferencia real. Ningún mock cierra el criterio de quitar fondo.

## Dependencias observadas, a comprobar antes de usarlas

Node 22.23.2, Fabric/Playwright/pdf-lib y Chromium resuelven; FFmpeg/ffprobe 9.0.2 ejecutables. Python Laragon 3.13 importa onnxruntime 1.30.0 y Pillow 12.3.0, proveedores CPU/Azure; torch/cv2 localizables, sin inferencia comprobada. No se encontraron rembg, onnx o fontTools. Python 3.14 registrado carece de esos paquetes. No se encontró modelo de segmentación en las ubicaciones acotadas, sin afirmar ausencia global. No copiar fuentes de Windows ni activos de terceros sin comprobar su licencia.

Repartir propiedad entre backend (recurso tipográfico/derivados/ejecutor), motor (contratos/cargador/máscaras/render cerrado) y frontend (gestión/búsqueda/selectores/Inspector), acordando el descriptor antes de modificar archivos compartidos. T-11 permanece separada.
