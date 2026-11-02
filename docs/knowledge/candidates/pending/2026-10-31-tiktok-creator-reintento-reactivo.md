---
proposed_by: implementer
estado: propuesta
category: GOTCHA
evidencia: validated_case
fuentes:
  - resources/js/Composables/tiktokCreator.js
  - tests/frontend/tiktok-creator.test.mjs
  - tests/e2e/tiktok-audio.spec.ts
  - docs/roadmap/2026-10-31-metricool-composer-parity/tasks.md (T-09)
tags:
  - area:frontend
  - feature:tiktok
  - iniciativa:2026-10-31-metricool-composer-parity
---

# Un error de creator_info puede realimentar el watchEffect

## Síntoma

El compositor de TikTok no llega a reposo de red cuando falla creator_info. La traza E2E aislada registró 30 respuestas 500 y 304 respuestas 429 en un minuto, sin acciones del usuario.

## Causa raíz

`watchEffect` llama al cargador asíncrono, que lee `state[accountId]` antes del primer await. Ese estado queda como dependencia del efecto. Al resolver un error se cambia el estado y el efecto vuelve a cargar, porque solo se cacheaban carga y éxito. Una prueba mínima de proveedor 503 produjo seis llamadas en 100 ms.

## Qué hacer en su lugar

Cachear también el error y reintentar solo por acción explícita. Mantener el bloqueo de consulta en vuelo; no corregirlo aumentando timeouts o rate limits. La fixture positiva de UI debe simular creator_info y la negativa comprobar el error y su recuperación.

El error se detecta por su presencia, no por la veracidad de su mensaje: cadenas vacías, false o 0 no significan éxito. Normalizar mensajes inesperados y validar la forma de las respuestas 200 antes de guardarlas como datos; null/false/0 como datos también pueden realimentar el efecto.

## Evidencia

`node --test --experimental-test-isolation=none tests/frontend/tiktok-creator.test.mjs`: RED 6 ≠ 1 antes del fix, luego RED para mensajes vacíos y respuestas 200 mal formadas; GREEN final **12 tests**, una llamada fallida y una segunda solo por reintento explícito. `tests/e2e/tiktok-audio.spec.ts` verifica el botón de recuperación y alcanza reposo de red. Ver T-09 del ledger para la traza original. Candidato pendiente de curación; no se escribe en approved ni se publica en un backend externo.
