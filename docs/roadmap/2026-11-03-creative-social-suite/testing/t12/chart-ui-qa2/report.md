# T12 C3charts — QA local

Resultado automático de esta ejecución: **NO-PASS**. Casos realmente ejecutados: **1 de 12 previstos**. Inspección de figuras/PDF: **pendiente**, nunca inferida por colección, render o hash. No se cierra T12 ni el plan.

## Ejecución y límites

Cuatro escenarios × 375/768/1440, un worker, cero reintentos y fail-fast1. Las ramas no alcanzadas no quedan acreditadas. SQLite, almacenamiento, cachés, rutas y usuarios ficticios aislados antes de Kernel; proof de rutas antes de usuarios. Sólo loopback. InterCSS externo tiene fixture vacía exacta; ninguna otra red externa está autorizada. Fuentes privadas SpaceGrotesk TTF y WOFF2 reales por UI de marca.

Job agregado COMMIT3GiB, killOnClose y sin breakaway; default del worker av-runtime3GiB intacto. Preflight físico6GiB anterior al UUID/servidor, piso3GiB cada100ms y plazo900s; capacidad desconocida o bajo presupuesto produce REFUSED. PHP CLI/servidor256M, Node512M. No se confunden COMMIT, RSS y memoria PHP; no se anuncia estrés52MB. Cualquier aborto invalida un verde anterior. PDF del informe en Job separado después del cierre real de procesos y puerto8080 IPv4/IPv6.

## Resultados reales

| Proyecto | Escenario | Outcome |
|---|---|---|
| desktop | E2E28.01 ten real variants pinned saved dataset controls shared preview save reopen undo SVG PNG private font | unexpected |
| desktop | E2E28.02 server static SVG PNG PDF pinned archived historical selected pages actor brand boundary | skipped |
| desktop | E2E28.03 invalid precision negative proportions cancel original typed table keyboard Inspector focus accessibility | skipped |
| desktop | E2E28.04 real 1000 rows twelve series twelve thousand bars shared SVG PNG semantic independent oracle guarded | skipped |
| mobile | E2E28.01 ten real variants pinned saved dataset controls shared preview save reopen undo SVG PNG private font | skipped |
| mobile | E2E28.02 server static SVG PNG PDF pinned archived historical selected pages actor brand boundary | skipped |
| mobile | E2E28.03 invalid precision negative proportions cancel original typed table keyboard Inspector focus accessibility | skipped |
| mobile | E2E28.04 real 1000 rows twelve series twelve thousand bars shared SVG PNG semantic independent oracle guarded | skipped |
| mobile-360 | E2E28.01 ten real variants pinned saved dataset controls shared preview save reopen undo SVG PNG private font | skipped |
| mobile-360 | E2E28.02 server static SVG PNG PDF pinned archived historical selected pages actor brand boundary | skipped |
| mobile-360 | E2E28.03 invalid precision negative proportions cancel original typed table keyboard Inspector focus accessibility | skipped |
| mobile-360 | E2E28.04 real 1000 rows twelve series twelve thousand bars shared SVG PNG semantic independent oracle guarded | skipped |

## qa-gate literal

```json
{
  "verdict": "NO-VERDE",
  "reason": "el runner reportó errores de ejecución",
  "errors": [
    "\u001b[31mTesting stopped early after 1 maximum allowed failures.\u001b[39m"
  ]
}
```

El recount exige 12 resultados sin skip/flaky/retry y todos los observadores, axe, red, materialización, deriva y cierre de procesos. El gate aislado no sustituye esa evidencia.

## Observadores y alcance

XML y matrices SVG se leen con DOM nativo y un segundo parser independiente stdlib. IDs de barras, línea cero, categorías/series, colores y signos/null/cero/apilado/proporciones se contrastan con álgebra de fixtures propias, sin importar escena/geometría/renderer del producto. Tolerancia de coordenadas0,75px contempla el centro de ejes de1px; escala de amplitud0,012. PNG valida firma/CRC/filtros y píxeles/longitudes frente a SVG+fixture. Barras subpixel de la tanda12.000 se cuentan individualmente en SVG; PNG se contrasta además con cobertura fraccional/source-over independiente, error medio≤6/p95≤18 y centros de ejes/rejilla±2px excluidos. No se afirman12.000 píxeles separables.

El preview real se compara con el recorte correspondiente del PNG final: error RGB máximo4 y medio0,25. Glifos privados se contrastan después de exportar con Canvas2D cargado desde TTF original bajo otra familia eliminada al terminar: IoU≥0,80 y margen≥0,03 frente a Arial. SVG debe contener los bytes WOFF2 cuyo SHA/tamaño se observaron por HTTP200/MIME real. Los umbrales anteriores son criterios fijados antes de ejecutar, no resultados asumidos.

PDF del diseño es raster: una página y un XObject RGB realmente dibujado a tamaño correcto. Su RGB debe coincidir píxel a píxel con el PNG entregado, donde se verifican los glifos; no se presenta como texto extraíble/vectorial. La entrada real de Node y sus archivos materializados se observan pasivamente, sin reescritura: referencias seleccionadas, revisión antigua archivada y SHA original. Cancelación retiene una respuesta PHP200 real y conserva sus bytes; cierre del cliente y ausencia de aplicación tardía son aserciones explícitas.

Descargas reales guardan bytes, SHA y URL privada; las URLs publicables se reducen a ruta. Las cabeceras nativas se observan en el frontend propio; los Blob del cliente declaran que sus cabeceras no son observables, sin GET adicional. Errores HTTP sólo admiten método/ruta/status predeclarados y afirmados, y abortos exactos confirmados. Axe exige cero violaciones e incomplete, sin exclusiones.

## Checklist manual pendiente

- [ ] Root inspecciona cada figura y cada página del PDF y registra su SHA actual en visual-inspection.json.
- [ ] Persona confirma legibilidad/densidad y calidad de diseño en375/768/1440 con sus datos reales.
- [ ] Otras22configuraciones, AV, Linux/VPS/R2 y datos52MB necesitan fases/pruebas propias; esta tanda no los acredita.

## Evidencias visuales

### images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-bar-preview.png

![images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-bar-preview.png](images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-bar-preview.png)

### images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column-preview.png

![images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column-preview.png](images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column-preview.png)

### images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column.png

![images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column.png](images/studio-charts-E2E28-01-ten-293e6-n-undo-SVG-PNG-private-font-desktop-column.png)


## Fallo observado y diagnóstico pendiente

Un caso desktop falló y once no se ejecutaron. La espera de la fuente pasó esta vez; el recorrido generó SVG/PNG de column y SVG de bar, con sus previews. Se detuvo en el oráculo categórico de bar: «Category text outside its semantic row band». También se registraron tres GET de exportación JSON de dataset con net::ERR_ABORTED; el observador pasivo independiente del servidor conserva tres respuestas200 terminadas con los mismos bytes/SHA. Se investiga su causa; no se ocultan ni se declaran aceptadas.

Estos archivos son evidencia parcial de un intento NO-PASS, no aceptación de las diez variantes, píxeles, fuentes, accesibilidad ni exportación integrada. La causa entre métricas del oráculo y renderizado se investiga mediante fuentes, SVG real y métricas de la fuente antes de corregir. No se relaja ninguna tolerancia por este resultado.

Core31,070s, COMMIT694.661.120B y mínimo físico9.642.672.128B, sin fallo del guard. Finalize0,118s/31.961.088B separado; PIDs propios muertos y8080IPv4/IPv6 libres.306fuentes verificadas intactas antes del informe. No es RSS. El reportador revisado admite ahora el terminal fallido cerrado y conserva NO-PASS sin el adaptador del intento1.

[SVG column observado](sample-column.svg) · [SVG bar observado](sample-bar.svg)

![Fallo durante la segunda variante](images/failed-second-variant.png)

![Preview column realmente renderizado](images/column-preview-render.png)

![Preview bar realmente renderizado](images/bar-preview-render.png)
