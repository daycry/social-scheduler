---
proposed_by: implementer
estado: propuesta
category: GOTCHA
evidencia: validated_case
fuentes:
  - tests/e2e/composer.spec.ts (expectPastDayInaccessible y regresiones de E2E-04)
  - resources/js/Components/Composer/DateTimePicker.vue (cuadrícula y navegación mensual)
  - docs/roadmap/2026-10-26-metricool-parity-3/tasks.md (T-77, depuración de frontera de mes)
tags:
  - area:testing
  - feature:selector-fecha
  - iniciativa:2026-10-26-metricool-parity-3
---

# El día pasado puede estar ausente del DOM al cambiar de mes

## Síntoma

El E2E del selector de fecha falla en los tres tamaños al ejecutar `toBeDisabled()` sobre el 31 de octubre. El navegador está en Madrid y la marca en Auckland: a las 20:00 UTC ya es 1 de noviembre en la marca. El error real es «element(s) not found».

## Causa raíz

La prueba suponía que ayer siempre existe como celda. El selector muestra el mes de hoy en la zona de la marca y rellena con `null` las posiciones de los meses adyacentes. Ayer puede pertenecer al mes anterior y, por tanto, estar ausente. La navegación a ese mes pasado está deshabilitada; no se detectó un día pasado seleccionable en el producto.

## Qué hacer en su lugar

Probar la inaccesibilidad según las fechas, sin adaptar el oráculo al número de elementos encontrados. Si ayer pertenece al mes mostrado, exigir su celda deshabilitada. Si pertenece al mes anterior, exigir su ausencia y el botón Mes anterior deshabilitado. Mantener hoy habilitado y enfocado y comprobar que ArrowLeft no retrocede a un día pasado.

Las regresiones deben cubrir ambos escenarios con fechas derivadas del calendario, sin modificar el reloj del servidor ni guardar una publicación.

## Evidencia

El [RED original](../../../roadmap/2026-10-26-metricool-parity-3/testing/diagnostic-calendar/red-original.json) reproduce el fallo. Las dos regresiones con el oráculo antiguo producen [un caso pasado y uno fallido](../../../roadmap/2026-10-26-metricool-parity-3/testing/diagnostic-calendar/red-boundary.json); el caso de cambio de mes falla por la celda ausente. Tras la corrección, el [mínimo de nueve casos](../../../roadmap/2026-10-26-metricool-parity-3/testing/diagnostic-calendar/green-minimal.json) y el [compositor completo de 81 casos](../../../roadmap/2026-10-26-metricool-parity-3/testing/diagnostic-calendar/green-composer.json) pasan en los tres tamaños, sin omitidos ni flaky. La [QA general posterior](../../../roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.md) pasa 502 casos y omite los dos previstos del shell en móvil, sin fallos ni casos sin ejecutar por errores; incluye las seis regresiones de fecha, todas pasadas.

Esta entrada es un candidato pendiente de curación y no modifica conocimiento aprobado.
