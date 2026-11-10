---
retro: 2026-11-03-creative-social-suite
fecha: 2026-11-10
---

# Retro — 2026-11-03-creative-social-suite

Suite social y creativa por marcas: 23/23 tareas cerradas entre el 2026-11-03 y el 2026-11-10, con la PR #19 lista para revisión (apilada sobre #18).

## Estimado frente a real

| Medida | Estimado | Real | Desviación |
|---|---|---|---|
| Horas IA | sin estimar (no hubo `evaluation.md`) | sin medir (ninguna tarea registró `(medido)`) | no calculable |
| Tokens | sin estimar | sin medir | no calculable |
| Coste (EUR) | sin estimar | — | no calculable |
| Alcance | 23 tareas | 23 tareas cerradas | 0 % |

**Esta retro no calibra.** La iniciativa salió de `/dev-cycle` sin pasar por `evaluator`, y el `usage-meter` no se ejecutó por tarea. Por eso la fila de `CALIBRATION.md` va marcada `(estimado)` y queda fuera de la mediana.

## Lo que salió del ledger (causas objetivas)

1. **El arnés nativo de 900 s costó caro y se abandonó.**
   - Las tareas T-09..T-12 se verificaron con un arnés propio (observadores, presupuestos de proceso, manifiestos SHA, revisiones con veredicto firmado).
   - Generó decenas de ficheros de contrato (`t12-*-contract.md`) y un `CONTINUE-HERE.md` ilegible.
   - El usuario eligió el «Método estándar» (implementador → revisor A+B+C → correcciones → Pest y Playwright en 3 tamaños con axe), y desde entonces las tareas cerraron a ritmo constante: T-13..T-21, T-14 y T-16 en dos días.
2. **Rojos E2E que no eran defectos.**
   - **Puerto 8080 compartido:** Playwright usa ese puerto fijo, y dos agentes que lo lanzaban a la vez chocaban.
   - **Código cambiando a mitad de pasada:** la pasada completa de T-22 dio 6 rojos mientras otros agentes cambiaban código y recompilaban assets. En la repetición sobre el HEAD congelado no apareció ninguno (772/0).
3. **CI en rojo desde el 2026-11-04 sin que se notara.** El runner de GitHub no tenía `ffmpeg`, así que fallaban los 16 tests de audio del estudio. Se detectó al preparar la PR y se arregló en `2f3b33d`.
4. **El ledger creció hasta 776 KB.** El tope de lectura es 256 KiB, así que solo se puede leer por secciones con awk.
5. **La revisión adversarial por tarea encontró 1-2 gaps Important en casi todas las tareas.** Todos se corrigieron con test. La auditoría global encontró además dos Important que se daban *entre* funciones: la baja de informes (CSRF frente a one-click y escáneres de correo) y las series que fallaban en silencio. Ninguna revisión por tarea los había visto.
6. **La documentación se quedó atrás.** La auditoría de documentación encontró 16 problemas Important: recuentos de MCP contradictorios, 38 rutas fuera del OpenAPI, la cola `studio` sin documentar y variables sin declarar.

## Aprendizajes

- Para trabajo de producto, el método estándar (revisor de contexto fresco más E2E de Playwright) da cierres verificables. Un arnés propio que se mide a sí mismo consume el tiempo en la herramienta, no en el producto.
- Una sola ejecución de Playwright a la vez, y nunca mientras otro agente compila o cambia código. La pasada final siempre sobre el HEAD congelado.
- Comprobar que CI está en verde después de cada push de la rama, no solo al abrir la PR.
- Incluir una auditoría global entre funciones antes de cerrar: encuentra lo que la revisión por tarea no puede ver.
- Actualizar la documentación al cerrar cada tarea (MCP, OpenAPI, despliegue, variables), no dejarla para la tarea final.
- Pasar por `evaluator` y activar `usage-meter` por tarea, para que la retro pueda calibrar.

## Ajuste sugerido

Iniciativas grandes (más de 20 tareas): evaluación previa obligatoria; un ledger por fase o `ledger-archive.py` al pasar de 256 KiB; y la documentación como criterio de cierre de cada tarea.

## Conocimiento aprobado

El `knowledge-curator` aprobó las dos lecciones el 2026-11-10, tras contrastarlas con el repo:

- `metricool.GOT-005`: una sola pasada de Playwright, sobre código congelado (`docs/knowledge/approved/gotchas/GOT-e2e-una-pasada-sin-cambios-concurrentes.md`).
- `metricool.GOT-006`: el runner de CI necesita ffmpeg y ffprobe (`docs/knowledge/approved/gotchas/GOT-ci-runner-necesita-ffmpeg.md`).
