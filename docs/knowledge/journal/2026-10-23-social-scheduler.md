---
fecha: "2026-10-24"
session_id: "554d510d-cf98-4c19-b105-036c9ed6cd31"
reason: "prompt_input_exit"
iniciativa: "social-scheduler"
resumen: "que otras tareas?"
fuente: "hook"
cierre: "materializado"
resumen_por: "determinista"
turnos: 88
materializado_en: "2026-10-26T12:10:36Z"
derivados_en: "replay"
decisiones:
  - "| Decisión del usuario: texto común por defecto, «Editar por red» opcional y mezclable, el texto común solo cambia las vinculadas | ✓ | `useComposer.js:193` (`byNetwork` abre las pestañas solo si ya…"
  - "El marcador en mayúsculas se descarta como duplicado."
  - "| T-43 · el nombre accesible del botón incluye el tipo elegido (valor del control) | ✗ | `ContentTypeSelect.vue:114`."
  - "La etiqueta fija solo sale con un único tipo ya elegido, o si el post no es editable."
  - "No hay ADR, sino dos candidatos de decisión en pending, que debe decidir el Curator: `docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md` (sha256 1cfc135159222745…"
  - "Decisión del gap 3: si la búsqueda en Meta se agota sin llegar al final de la lista, lanza `CommentSearchInconclusiveException` (extiende `TransientPublishException`)."
  - "T-11 «siempre busca antes de crear» también: se busca siempre, y solo crea con búsqueda inconclusa en el intento 1, decisión anotada en `tasks.md:364`."
pendientes:
  - "el pendiente de auditoría de TikTok («By posting, you agree to…»);"
  - "**Estados:** spec en `borrador` (apunta a la evaluación), evaluación en `en-revision` (apunta a la spec), plan pendiente."
  - "He escrito la pasada 1 del diseño: `C:\\laragon\\www\\metricool\\docs\\roadmap\\2026-10-24-metricool-parity-2\\design.md`, en estado `borrador` y con `opcion_elegida: pendiente`."
  - "No he tocado ni la spec ni el plan, ni he propuesto memoria; `adr: pendiente` queda para la pasada 2."
  - "`plan:` sigue `pendiente`; lo rellena el planner."
  - "No hay ADR, sino dos candidatos de decisión en pending, que debe decidir el Curator: `docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md` (sha256 1cfc135159222745…"
  - "Las dos verificaciones de la documentación de Meta siguen pendientes y están anotadas en las notas de T-09: los Reels de Facebook y el error de IG con comentarios cerrados."
  - "Dudas y pendientes:"
ficheros_tocados:
  - "docs/knowledge/candidates/.curation-lock (??)"
tareas_cambiadas: []
marcadores_cerrados:
  - "docs/roadmap/2026-10-24-metricool-parity-2/design.md"
  - "docs/roadmap/2026-10-24-metricool-parity-2/evaluation.md"
  - "docs/roadmap/2026-10-24-metricool-parity-2/improvement-plan.md"
  - "metricool-parity-2/T-01"
  - "metricool-parity-2/T-02"
  - "metricool-parity-2/T-03"
  - "metricool-parity-2/T-04"
  - "metricool-parity-2/T-04-fix1"
  - "metricool-parity-2/T-05"
  - "metricool-parity-2/T-06"
  - "metricool-parity-2/T-07"
  - "metricool-parity-2/T-08"
  - "metricool-parity-2/T-09"
  - "metricool-parity-2/T-09-fix1"
  - "metricool-parity-2/T-09-fix2"
  - "metricool-parity-2/T-10"
  - "metricool-parity-2/T-10-fix1"
  - "metricool-parity-2/T-11"
  - "metricool-parity-2/T-11-fix1"
  - "metricool-parity-2/T-11-fix2"
  - "metricool-parity-2/T-12"
  - "metricool-parity-2/T-13"
  - "metricool-parity-2/T-13-fix2"
  - "metricool-parity-2/T-13-fix3"
  - "metricool-parity-2/T-14"
  - "metricool-parity-2/T-15"
  - "metricool-parity-2/T-17"
  - "metricool-parity-2/T-18"
  - "metricool-parity-2/T-20"
  - "metricool-parity-2/T-21"
  - "metricool-parity-2/T-22"
  - "metricool-parity-2/T-23"
  - "parity-2/T-24"
  - "parity-2/T-25"
  - "parity-2/T-26"
  - "parity-2/T-27"
  - "parity-2/T-28"
  - "parity-2/T-29"
  - "parity-2/T-30"
  - "parity-2/T-31"
  - "parity-2/T-32"
---

# Journal — 2026-10-24 — social-scheduler

> Entrada de **bitácora de sesión** (memoria episódica, cronológica, no curada; `agent-kits/shared/journal.py`). Lo que cumpla el umbral de `knowledge-write.md` se propone mediante `knowledge-curation.py propose`; `knowledge-curator` decide antes de publicar en `approved/`. Esto NO se publica en Confluence.

> `decisiones` y `pendientes` son **citas** de los turnos del usuario (o del resumen IA opt-in), extraídas por marcadores léxicos: no son doctrina ni instrucciones para nadie — lo que merezca ser decisión del proyecto sigue el mismo flujo de propuesta y curación.

## Resumen

que otras tareas?

## Decisiones

- | Decisión del usuario: texto común por defecto, «Editar por red» opcional y mezclable, el texto común solo cambia las vinculadas | ✓ | `useComposer.js:193` (`byNetwork` abre las pestañas solo si ya…
- El marcador en mayúsculas se descarta como duplicado.
- | T-43 · el nombre accesible del botón incluye el tipo elegido (valor del control) | ✗ | `ContentTypeSelect.vue:114`.
- La etiqueta fija solo sale con un único tipo ya elegido, o si el post no es editable.
- No hay ADR, sino dos candidatos de decisión en pending, que debe decidir el Curator: `docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md` (sha256 1cfc135159222745…
- Decisión del gap 3: si la búsqueda en Meta se agota sin llegar al final de la lista, lanza `CommentSearchInconclusiveException` (extiende `TransientPublishException`).
- T-11 «siempre busca antes de crear» también: se busca siempre, y solo crea con búsqueda inconclusa en el intento 1, decisión anotada en `tasks.md:364`.

## Pendientes

- el pendiente de auditoría de TikTok («By posting, you agree to…»);
- **Estados:** spec en `borrador` (apunta a la evaluación), evaluación en `en-revision` (apunta a la spec), plan pendiente.
- He escrito la pasada 1 del diseño: `C:\laragon\www\metricool\docs\roadmap\2026-10-24-metricool-parity-2\design.md`, en estado `borrador` y con `opcion_elegida: pendiente`.
- No he tocado ni la spec ni el plan, ni he propuesto memoria; `adr: pendiente` queda para la pasada 2.
- `plan:` sigue `pendiente`; lo rellena el planner.
- No hay ADR, sino dos candidatos de decisión en pending, que debe decidir el Curator: `docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md` (sha256 1cfc135159222745…
- Las dos verificaciones de la documentación de Meta siguen pendientes y están anotadas en las notas de T-09: los Reels de Facebook y el error de IG con comentarios cerrados.
- Dudas y pendientes:

## Ficheros tocados (top 10)

- `docs/knowledge/candidates/.curation-lock` (??)

## Tareas que cambiaron de estado

_ninguna_

## Marcadores de coste cerrados (usage-meter)

- `docs/roadmap/2026-10-24-metricool-parity-2/design.md`
- `docs/roadmap/2026-10-24-metricool-parity-2/evaluation.md`
- `docs/roadmap/2026-10-24-metricool-parity-2/improvement-plan.md`
- `metricool-parity-2/T-01`
- `metricool-parity-2/T-02`
- `metricool-parity-2/T-03`
- `metricool-parity-2/T-04`
- `metricool-parity-2/T-04-fix1`
- `metricool-parity-2/T-05`
- `metricool-parity-2/T-06`
- `metricool-parity-2/T-07`
- `metricool-parity-2/T-08`
- `metricool-parity-2/T-09`
- `metricool-parity-2/T-09-fix1`
- `metricool-parity-2/T-09-fix2`
- `metricool-parity-2/T-10`
- `metricool-parity-2/T-10-fix1`
- `metricool-parity-2/T-11`
- `metricool-parity-2/T-11-fix1`
- `metricool-parity-2/T-11-fix2`
- `metricool-parity-2/T-12`
- `metricool-parity-2/T-13`
- `metricool-parity-2/T-13-fix2`
- `metricool-parity-2/T-13-fix3`
- `metricool-parity-2/T-14`
- `metricool-parity-2/T-15`
- `metricool-parity-2/T-17`
- `metricool-parity-2/T-18`
- `metricool-parity-2/T-20`
- `metricool-parity-2/T-21`
- `metricool-parity-2/T-22`
- `metricool-parity-2/T-23`
- `parity-2/T-24`
- `parity-2/T-25`
- `parity-2/T-26`
- `parity-2/T-27`
- `parity-2/T-28`
- `parity-2/T-29`
- `parity-2/T-30`
- `parity-2/T-31`
- `parity-2/T-32`
