# T-12: núcleo de datos de tablas y gráficos

2026-11-05. **A2/B2 sin gaps; verificación pura final57/57,88 aserciones. T-12 sigue abierta.** No se acredita editor, gráficos renderizados, XLSX, fuentes conectadas, documentos/pizarras/presentaciones ni exportaciones Office.

## Cambio y alcance

`StudioChartData` normaliza la tabla tipada interna y codifica/decodifica CSV/TSV con límites, Unicode, quoting y errores localizados. No accede a DB, red o archivos ni evalúa fórmulas. [Contrato previo](../../../t12-chart-data-contract.md). Es una pieza interna, sin handlers ni descarga pública. CSV conserva intercambio textual, sin tipos ni IDs; antes de exponerlo para hojas externas debe resolverse la exportación segura prevista en el contrato.

Dos archivos nuevos desde baseline ausente. Snapshot1 conservado, deltas del ajuste y SHA2 en [manifiesto](manifest.json). [Evidencia TDD](raw/tdd-evidence.md) y raw originales copiados; el ledger canónico permanece en tasks.md.

## Revisión independiente

Scope-check HEAD: cero archivos fuera, avisos, info y exclusiones de usuario. C/D=false. Instalación parcial sin model-tier aplicable: modelo heredado. Sin constitución/dev.json/cobertura mínima configurada; no OpenAPI, interop ni archivos generados tocados. Revisores leyeron los dos diffs completos415 líneas en intento1; intento2 tiene64 líneas de delta. Se preservaron todos los ✓ y solo se reabrió el diagnóstico confirmado.

| Criterio de A1/A2 | Resultado y evidencia |
|---|---|
| Identidad de fuentes/diffs y alcance | ✓ Dos SHA y tamaños, snapshots1/2 y deltas exactos; Archivos T12 declara los dos PHP. |
| Estructura cerrada, listas, IDs y unicidad | ✓ Servicio:16,59,66; dataset de validación. |
| Tipos, números finitos, UTF-8 y controles | ✓ Servicio:42,74; no coerción silenciosa ni HTML ejecutado. |
| 1000×100, longitudes y2MiB | ✓ Servicio:23,32,45,51; frontera exacta y un byte excedido probados. |
| Parser CSV/TSV, comillas y BOM | ✓ Servicio:108,175; rechazos y roundtrip. |
| Diagnóstico coherente de sintaxis/contenido | ✓ Tras B1, registro CSV desde1 incluyendo encabezado; multiline cuenta uno; normalize directo conserva índice de datos. Tests:192–209. |
| CRLF interior→LF, espacios, fórmulas como texto, sin inferencia | ✓ Servicio:110,125,172 y tests:85,100. |
| Exportación validada, CRLF, escape y number/null lexical | ✓ Servicio:203,218 y tests:133,142. |
| Roundtrip textual, sin prometer tipos/IDs | ✓ Tests:148,162 y contrato. |
| U+FEFF literal inicial | ✓ Servicio:222 y test:169; no se confunde con firma BOM. |
| Pureza y puerta futura de CSV seguro | ✓ No hay acceso DB/FS/red ni endpoint; no se afirma protección para abrir fórmulas en hojas externas. |
| RED/GREEN auténticos | ✓ Lectura de los raws antes del código; errores de harness y downstream no contabilizados como criterios. |
| Verificaciones declaradas | ✓ A1 leyó50/74 y vecino CsvWriter intacto3/15; A2 leyó57/88 y ejecutó7/14; lint/Pint reales correctos. |
| Aceptación completa T-12 | Pendiente explícita; el núcleo no prueba integración ni sustituye los flujos del roadmap. |
| Constitución/config/OpenAPI/interop | No aplican a este diff; ausencia comprobada y ningún archivo pertinente tocado. |

B1 ejecutó normalización28/28,33 aserciones y codec/frontera23/23,44; un probe independiente completó32400 roundtrips con/sin encabezado, espacios/comillas/LF/Unicode/BOM. Encontró un Minor confirmado por root: `h\nvalid\n"bad<NUL>value"` señalaba fila2 en vez de registro3, mientras un error sintáctico en el mismo registro señalaba3. La validación rechazaba el dato pero dirigía al registro equivocado para corregirlo.

El ajuste usa contexto compartido de validación para la importación, sin reescribir mensajes mediante regex ni cambiar `normalize` público. REDnuevo: cuatro fallos reales y tres caracterizaciones ya verdes; GREEN7/7,14 aserciones. A2/B2 confirman B1 corregido, sin gaps nuevos. B2 ejecutó además160 comprobaciones de diagnóstico CSV/TSV: encabezado/sin encabezado, multiline, etiquetas, columnas e índices de normalize/export. **Cero Critical/Important/Minor pendientes.**

## Verificación final y límites

Root ejecutó el harness puro tras A2/B2: **57/57,88 aserciones,115ms,exit0**, dos SHA iguales antes/después. [Registro completo](root-verification.json), [stdout](raw/root.stdout.txt). Pest usa test-directory UUID, `--no-configuration` y autoload, sin bootstrap Laravel, DB, .env ni procesos gráficos nativos. Los conjuntos focales anteriores se solapan y no se suman como una suite global.

Fuentes congeladas para coordinar la futura QA T11: servicio `b110018f…91845`, test `3eeacf81…a1fd0`. Esta revisión no acredita la QA de interfaz de T12 ni el objetivo completo; commits/push/PR finales y auditoría Claude CLI siguen sujetos a las puertas del roadmap.
