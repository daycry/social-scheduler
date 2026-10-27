# `docs/knowledge/journal/` — bitácora de sesión (memoria episódica)

Una entrada por sesión, **generada** por `agent-kits/shared/journal.py` (hook `SessionEnd`; la
última se reinyecta al arrancar/retomar). Cronológica y **no curada**: lo que cumpla el umbral de
`knowledge-write.md` se propone mediante `knowledge-curation.py propose`; `knowledge-curator`
decide antes de publicar en `approved/`. **Excluida de
Confluence** (`docs/knowledge/journal/**`). Nombre: `AAAA-MM-DD-<iniciativa activa | sesion>.md` (sufijo `-2`
si otra sesión del día ya lo usó). **Las entradas se versionan** (memoria del proyecto, como ADR y lecciones);
quien no quiera versionarlas añade `docs/knowledge/journal/*.md` (no este README) a su `.gitignore`.
Este índice lo regenera `journal.py index`; no lo edites.

**Campos de frontmatter de cada entrada** (`agent-kits/shared/journal.py:render`):

| Campo | Valores | Qué significa |
|---|---|---|
| `cierre` | `materializado` · `recuperado_sin_cierre` | Con qué garantía se cerró la sesión: envelope de `SessionEnd` verificado, o recuperada del log de prompts sin cierre observado |
| `fuente` | `hook` · `recover` · `manual` | Quién generó la entrada: `replay` del envelope del hook, `recover` sobre una sesión huérfana, o `--enrich` a mano |
| `reason` | motivo del envelope (`clear`, `resume`, `logout`, `prompt_input_exit`, `other`) o `orphan_recovery` | Motivo de cierre; `orphan_recovery` = nunca hubo envelope |
| `derivados_en` | `replay` | `ficheros_tocados`/`tareas_cambiadas` se calculan con git al materializar (también para huérfanas), nunca en el teardown de `SessionEnd` (CA-01) |
| `materializado_en` | timestamp ISO UTC | Cuándo se escribió la entrada final; la `fecha` es la del cierre (`captured_at`) |
| `log_hasta` | timestamp ISO UTC (solo `recuperado_sin_cierre`) | mtime del log de prompts con el que se recuperó; si el log cambia después, `recover` rehace la entrada |
| `objetivo` · `punto_actual` · `siguientes_pasos` · `bloqueos` · `descartados` | texto · texto · lista · lista · lista (opcionales) | Continuidad declarada con `--enrich`; clave ausente = nunca declarada, vacía = vaciada a propósito |

| Fecha | Iniciativa | Resumen | Fuente |
|---|---|---|---|
| [2026-10-24](2026-10-23-social-scheduler.md) | social-scheduler | que otras tareas? | hook |
| [2026-10-21](2026-10-21-social-scheduler.md) | social-scheduler | <agent-message from="a2f5a01efa4b8d629"> [Subagent hand-back] The text below is the final report of… | recover |
| [2026-10-19](2026-10-19-sesion.md) | n/a | al ser una herramienta local, van a haber problemas con la api de las redes sociales? | hook |
