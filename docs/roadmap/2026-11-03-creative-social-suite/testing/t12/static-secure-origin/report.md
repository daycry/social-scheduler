# Render estático: contexto seguro y verificación SHA

2026-11-06. **Regresión focal aprobada; matriz integrada todavía pendiente. T12 sigue abierta.**

El escenario integrado del segundo intento del ciclo2 pasó las diez variantes desktop y después falló en el render SVG del servidor. [Informe histórico NO-PASS](../chart-ui-cycle2-qa2/report.md). Se aisló el fallo antes de repetir la matriz.

## Diagnóstico observado

La sonda ejecutó seis controles offline con Chromium y los cuerpos reales del registry, sin cambiar sus funciones. El origen HTTP sintético no ofrece crypto.subtle y falla al calcular digest. El mismo JSON291B/SHA se verifica y devuelve cuatro filas bajo HTTPS.

Los cuatro controles de fuentes separan el comportamiento de FontFaceURL del camino de la aplicación: la CSP original bloquea la URL directa, pero permite fetch→ArrayBuffer→FontFace, que es el utilizado. La corrección conserva esa CSP.

## Cambio y regresión real

Sólo cambia el protocolo de origin en scripts/studio/render.mjs: HTTPS. Las solicitudes siguen atendidas por un mapa cerrado, sin servidor ni red externa. Se conservan la comprobación SHA y los flags del navegador.

El entrypoint real de Node, con el bundle construido y la misma fixture de dos páginas, reproduce primero TypeError leyendo digest. Sólo la primera página está seleccionada; la segunda tiene un dataset sin descriptor y no debe cargarse. Tras el cambio mínimo genera:

| Formato | Bytes | Comprobación focal |
|---|---:|---|
| SVG |79949|XML, IDs de barras, página no seleccionada ausente |
| PNG |45497|CRC y1080×1080 |
| PDF |17461|Parse real, una página810×810pt |

El PNG fue visto por root; el PDF se verificó estructuralmente. Esta tanda no acredita todas las comparaciones de píxeles, permisos, accesibilidad,12kbarras ni otros viewports de la matriz E2E.

## Recursos y trazabilidad

Cada fase usa Job agregado3GiB COMMIT, preflight6GiB, piso3GiB/100ms y plazo90s. Node512MiB y entornoOSwhitelist, sin.envreal/PHP/DB/build. Diagnóstico de seis controles:0,738s/232.476.672B; CLIbaseline esperadofallo:0,570s/248.918.016B; CLIfixed3formatos:1,936s/280.952.832B. No son métricas RSS.

Las122fuentes de cada fase CLI permanecen iguales antes/después; entre fases sólo cambia renderer según allowed-origin-change.json. Los77assets y29módulosStudio coinciden con build2 durante el focal. Nodos/supervisores terminados y puertos libres, recibos terminal-root incluidos. El fingerprint global necesita build nuevo tras cambiar el script/contrato.

[SVG real](sample.svg) · [PNG real](sample.png) · [PDF real, parseado](sample.pdf). Fuente Space Grotesk bajo [OFL](OFL.txt). Los datos son ficticios y la fuente procede de las fixtures autorizadas del proyecto.
