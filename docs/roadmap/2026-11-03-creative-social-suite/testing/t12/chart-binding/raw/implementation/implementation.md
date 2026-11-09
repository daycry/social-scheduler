# Entrega de implementación — T12 chart binding

Candidato implementado, pendiente de revisión independiente. No cierra T12 ni una configuración de gráfico. Sólo dos fuentes nuevas asignadas; núcleo de datos intacto.

| Contrato | Evidencia |
|---|---|
| B1 referencia y selección | Criterios RED/GREEN 01–08: normalización propia inmutable, referencias id/revisión/SHA, selección única 1–12 y categoría distinta, campos cerrados/descriptores/densidad, coincidencia de snapshot y columnas por ID. |
| B2 tipos/diagnóstico/precisión | 06,09,11,12,16: lexemas/categorías Unicode/identidades/nulos/negativos/-0.0, texto exige conversión explícita con fila/columna/IDs; comparación decimal exacta antes del redondeo; aproximación con warnings por magnitud y underflow no nulo→0. |
| B3 límites y datos propios | 10,13 y tres caracterizaciones: validación del core 1000×100/52MB y números de su marca, sin getters; captura lineal de arrays/referencias; proyección máxima de 12.000 valores y salida propia congelada. |

Suite final real `17-suite.json`: **47/47 PASS**, 17 propios + 30 del core existente, cero fallos/skips/cancelaciones; cuatro SHA fuente iguales antes/después. Node V8 old-space limitado a 512 MiB (no es una cota RSS ni Job nativo). No se ejecutó Chromium, HTTP, aplicación, env, BD, PHP, build, PDF, dependencias o redes.

14 criterios con RED genuino por comportamiento antes de su implementación. Tres caracterizaciones ya verdes declaradas como tales. `manifest.json` contiene los comandos/títulos/errores/fecha para root ledger. `full.diff` representa dos ficheros nuevos; `source/` congela sus bytes.

Históricos conservados: el primer comando intentó una opción Node inexistente `--test-isolation=none`; `01-normalize-red.log` no cuenta como RED. Se corrigió al flag de Node22 `--experimental-test-isolation=none` y todos los RED contados tienen TAP de fallo por comportamiento, no import/syntax. `09-text-green.json` conserva una primera verificación fallida por redacción que no coincidía con el matcher; `09-text-green2.json` es el GREEN real tras corregir el mensaje. `15-suite.json` (46 PASS) es anterior a la aclaración de underflow de root; se conserva, sin presentarlo como la suite vigente.

API de salida actual: `{dataset,category_column:{id,label},precision,categories:[{row_id,type,label}],series:[{column_id,label,values:[{value,raw}|null]}],warnings:[{code,row_id,column_id,row,column,raw}]}`. type usa text/number/empty; empty tiene label vacío y sigue distinto del texto vacío por tipo. Warnings APPROXIMATE_COORDINATE o UNDERFLOW_COORDINATE, coordenadas fila/columna desde1. No se agrupan ni ordenan observaciones. SHA se contrasta con la identidad del snapshot suministrado, no acredita autorización del servidor.
