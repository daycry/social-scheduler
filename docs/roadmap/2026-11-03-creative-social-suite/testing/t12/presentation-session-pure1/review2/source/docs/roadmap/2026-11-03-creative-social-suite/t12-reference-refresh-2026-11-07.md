# Referencia de gráficos y presentaciones, 2026-11-07

Lectura documental actual, separada del runtime. REA `list_browser_targets` con endpoint127.0.0.1:9222 y orígenes Canva/Metricool devolvió `provider_unavailable`, detalle `endpoint_unreachable`. No hubo observación nueva del editor autenticado, sesión abierta, modificación de configuración ni uso de funciones premium. Esto limita la evidencia de referencia; no bloquea implementar y probar los módulos locales.

## Gráficos: alcance que sigue abierto

Canva documenta más de treinta opciones. Su índice distingue barras, líneas, circular/anillo, áreas, dispersión/burbujas/puntos, jerarquía, carreras de barras, infográficos, histogramas, embudos y radar. La cifra32 del contrato local sigue siendo una meta de cobertura del proyecto, no un recuento nuevo observado en la cuenta del usuario. [Introducción](https://www.canva.com/help/charts/), [índice oficial](https://www.canva.com/help/chart-types/).

Las variantes de barras incluyen agrupadas, apiladas, proporcionales y multicolor, con orientación vertical u horizontal. La tabla permite selección múltiple, inserción/eliminación y orden ascendente/descendente. Se conservará la distinción entre identidad y orden visual; el soporte del editor de tablas debe cotejar esas acciones, además del render. [Barras](https://www.canva.com/help/bar-charts/).

Circular/anillo añade agregación, etiquetas numéricas o porcentuales y tooltips. La referencia documenta filtros de pago, hasta tres, con botones o rango, color interactivo y vista predeterminada guardada. Son requisitos abiertos de T12 y del contenido interactivo T15, sin atribuirlos al bloque actual de barras. [Circular y anillo](https://www.canva.com/help/pie-and-donut-charts/).

## Presentaciones: comportamientos que P1 no cubre

La referencia ofrece pantalla completa, presentador, grabación y reproducción automática. Describe salto por número, edición en vivo activada por defecto con opción de ocultarla, trazos temporales, atajos/animaciones, cursor configurable y temporizador con música. También documenta control remoto compartido que se puede pausar/reanudar y preguntas de audiencia mediante Canva Live. [Presentar diseños](https://www.canva.com/help/presenting-designs/).

El módulo P1 de navegación mantiene una copia estable como base del modo sin edición en vivo. No sustituye el modo live ni su control explícito. Las vistas de audiencia/presentador, notas persistidas, sincronización, trazos, temporizador, medios, grabación y Office siguen en T12; acceso y revocación de control remoto colaborativo en T13; la experiencia interactiva publicada se enlaza con T15. Se verificarán localmente por comportamiento, permisos y archivos reales antes de anunciar equivalencia.

## Efecto en la aceptación

No hay cierre de tareas ni aumento de cobertura por esta lectura. El cliente01 permanece en preparación y su contrato conserva las diez variantes completas; otros tipos y los controles descritos arriba siguen abiertos. La implementación P1 aislada y sus pruebas puras no acreditan una presentación usable. Este documento complementa el inventario anterior y evita convertir un componente inicial en reducción del objetivo completo.
