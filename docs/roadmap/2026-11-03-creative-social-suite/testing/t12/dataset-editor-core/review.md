# Núcleo del editor de datos: revisión aceptada

2026-11-06. Se acepta el núcleo puro E1–E4 tras dos intentos de revisión. La interfaz, los gráficos y T12 integral siguen pendientes; el total continúa en8/23 tareas cerradas.

El módulo conserva tipos, IDs y lexemas numéricos, incluidos0.0, -0.0 y enteros de64bits que JavaScript redondearía. Permite edición y conversión explícita, operaciones de tabla, historial acotado y lectura de páginas fijadas a revisión+SHA. El guardado genera JSON por filas, sin introducir objetos de representación interna en el archivo.

## Resultado y alcance

- 30/30 pruebas finales pasan; cero fallos, omisiones o cancelaciones. Los26 tests originales son prefijo byteidéntico. No se suman ejecuciones históricas como si fueran una suite nueva.
- Veinte RED genuinos del primer intento y cuatro de corrección; seis caracterizaciones que ya pasaban se mantienen identificadas. Errores iniciales de harness y el primer RED del historial no cuentan; el código del historial se borró y reescribió tras el RED corregido.
- Root comprobó2SHA de fuentes y185 artefactos originales más39 de corrección. Fuentes finales: servicio9953c98f…80190 ytestb328009c…742d5.
- Observador independiente PHP64→JS:13filas,22comprobaciones, salida canónica775bytes idéntica, SHA dca94bae50cd4b65364a2f416a4d243fc17e41fd04ffcc3b36e6747790a7bee1. Es una muestra pequeña; no acredita un editor de52MB ni la interfaz.

## Intento1: A+B+C

[Tabla completa y evidencia del intento original](raw/attempt1/review.md). A no encontró incumplimientos de requisitos y C no encontró vulnerabilidades introducidas. C se activó por RegExp.exec, que no ejecuta comandos. D no se activó.

| Hallazgo | Grado | Reproducción | Resultado |
|---|---|---|---|
| B1 | Important | Un hueco en values era omitido por forEach/map; el serializador producía ["keep",] inválido. | Corregido en intento2 |
| B2 | Important | Una columna externa congelada con getter conservaba una referencia cambiante; alteraba snapshots e historial tras capturarlos. | Corregido en intento2 |

Root reprodujo ambos defectos. No hubo rebates ni deuda aceptada. B ejecutó1500 comprobaciones independientes adicionales; no se presentan como casos de la suite30.

## Intento2: A+B; C1 preservada

El delta completo tiene146líneas en dos fuentes. Se conservan todos los criterios previamente aprobados. El selector canónico sobre las líneas añadidas devuelve C=false yD=false; no se modificaron sus heurísticas.

| Criterio | A2 | B2 | Evidencia actual o preservada |
|---|---|---|---|
| E1 módulo puro y23exports | ✓ | ✓ | Firmas idénticas; S1 |
| E1 lexemas numéricos | ✓ | ✓ | S5–7,40–46,120; T5–10,42–49,151–158 |
| E1 entrada52M/página8MiB y UTF8 | ✓ | ✓ | S46–59,320; T220–235 |
| E1 profundidad16 y duplicados | ✓ | ✓ | S14,29; T32–34,205–209 |
| E1 sin ejecución de texto | ✓ | ✓ | C1 preservada; T48–49 |
| E2 esquema, tipos, IDs y dimensiones | ✓ | ✓ | S62–117,134–155; T14–30,251–289 |
| E2 Unicode y controles | ✓ | ✓ | S99–105; T19–25,194–203 |
| E2 presupuesto canónico52M | ✓ | ✓ | S151–152; T237–248 |
| E2 sin mutar la entrada | ✓ | ✓ | S88–98,156–165; T180–185,194–201 |
| E2 conversión explícita | ✓ | ✓ | S169–176,258–271; T51–59,109–116 |
| E3 chunks JSON tipados | ✓ | ✓ | S120–132,168; T42–49,251–256 |
| E3 edición por identidad | ✓ | ✓ | S181–198; T61–71 |
| E3 estructura y copia | ✓ | ✓ | S208–240; T73–96 |
| E3 reemplazo literal y vacíos | ✓ | ✓ | S242–256; T98–107,211–214 |
| E3 conversión atómica con preview | ✓ | ✓ | S258–271; T109–116 |
| E3 transposición con encabezados | ✓ | ✓ | S293–298; T132–141 |
| E3 historial30 y datos compartidos | ✓ | ✓ | S158–165,273–290; T118–129 |
| E4 metadatos seguros | ✓ | ✓ | S300–340; T145–149 |
| E4 revisión/SHA/columnas/IDs/cursor | ✓ | ✓ | S342–359; T151–172 |
| E4 rechazo atómico y recuperación | ✓ | ✓ | S351–359; T167–171; probes propios |
| B1: listas propias y densas | ✓ | ✓ | S76–96; T251–267; RED01/02→GREEN |
| B2: captura de datos sin accessors | ✓ | ✓ | S62–96,158–165; T269–289; RED03/04→GREEN |
| Alcance, TDD y verificación | ✓ | — | Ledger y RAW; scope sin exclusiones/avisos/fuera de alcance |
| Integridad de fuentes/artefactos | ✓ | ✓ |2SHA iguales antes/después;39artefactos y prefijo26 verificados |
| Constitución/ADR/interop/OpenAPI/%cobertura | n/a | n/a | No constitución ni cambios de esas superficies; sin gate de porcentaje |
| Descripción y límites | ✓ | ✓ | E5–E7 y T12 global siguen pendientes |

A2 ejecutó43 comprobaciones propias y B2 ejecutó165, con cero getters invocados y fuentes intactas. No repitieron innecesariamente la suite30. Cero gaps pendientes de requisitos, corrección o seguridad del diff.

## Límites de la evidencia

Las listas exigen índices propios de datos, sin huecos, accesores ni propiedades extra. Se rechazan getters/setters sin ejecutarlos. Un freeze externo no demuestra que los valores sean un snapshot; sólo se reutilizan datos marcados por el propio núcleo.

Node usa V8oldspace512MiB, que no equivale a un límite de proceso, RSS o Job de Windows. La prueba informativa1000×100/31ediciones conserva filas/columnas compartidas, media17,23ms y máximo25,53ms. RSS68.476.928bytes es la medición final, no un pico. No es una aceptación de rendimiento de52MB en navegador.

Los ensayos del presupuesto52M usan strings compartidos y conteo por chunks; no se transforman en una importación real de52MB. El transporte grande sí tiene su [aceptación separada](../dataset-api-qa2/report.md). UI y su QA27 permanecen abiertas.
