---
spec: audit-hardening
descripcion: Dejar la aplicación perfectamente implementada, con todas las funciones operativas, y ampliarla, a partir de la auditoría integral del 2026-11-11
estado: borrador
creado: 2026-11-11
actualizado: 2026-11-11
evaluacion: n/a
design: n/a
plan: improvement-plan.md
---

# Endurecimiento y ampliación tras la auditoría integral

> **Evaluación:** n/a (la fuente es [`audit.md`](audit.md))
> **Plan de implementación:** [`improvement-plan.md`](improvement-plan.md) · ledger [`tasks.md`](tasks.md) · pruebas [`test-plan.md`](test-plan.md)

## Contexto y objetivo

La auditoría de `feature/creative-social-suite` ([`audit.md`](audit.md)) encontró 118 hallazgos con ID estable (A-01..A-12, B-01..B-20, C-01..C-18, D-01..D-21, E-01..E-20, F-01..F-27). El objetivo es **dejar la aplicación perfectamente implementada, con todas las funciones operativas, y ampliarla**: cerrar los riesgos, completar lo que está a medias, ordenar el código con una red de tests fiable y entregar las funciones nuevas de mayor valor.

## Alcance

- **Dentro:**
  - Los 118 hallazgos de `audit.md`: cada uno en una sola tarea del plan, salvo los tres declarados fuera (F-21, F-26, F-27).
  - De los hallazgos que dependen del usuario o de terceros (`U`, `V`, `[D]`): todo lo implementable con fakes, configuración y documentación.
  - Documentación de cada módulo tocado y CHANGELOG.
- **Fuera:**
  - F-21 (IA sobre comentarios de terceros), F-26 (ASR y Flows) y F-27 (X, Google Business Profile y Twitch): requieren una decisión del usuario. Se redacta `DECISIONES.md`.
  - Smokes con proveedores reales, protección de `main` en GitHub, despliegue en el VPS, destino externo de copias, claves VAPID/SMTP/IA reales: los hace el usuario (bloques M-xx).
  - Migraciones de versión mayor (Inertia 3, Tailwind 4): solo plan (E-14).

## Restricciones permanentes

Sin Docker; sin proveedores reales ni red externa en las pruebas; no editar `.env`; no tocar los datos del usuario 28; los merges los hace el usuario; una sola ejecución de Playwright a la vez y nunca con cambios de código en paralelo (GOT-005).

## Criterios de aceptación

- [ ] Cada ID de `audit.md` aparece en exactamente una tarea o en la sección «Fuera de este plan / decisión del usuario» (`scripts/check-traceability.py` → OK).
- [ ] Pest completo y E2E (tres tamaños) en verde al terminar; `composer audit` y `npm audit` sin avisos.
- [ ] Los refactores (C, D-11, D-12) no cambian comportamiento: Pest y E2E en verde antes y después de cada tarea.
- [ ] Cada tarea cerrada tiene su `Verificación` ejecutada, revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos y documentación actualizada.
- [ ] Lo que depende de terceros queda implementado hasta donde es posible, con lo pendiente anotado en la tarea y sin bloquear la fase.
- [ ] `DECISIONES.md` recoge cada decisión pendiente del usuario con opciones, coste y recomendación.

## Pruebas

Pest (filtros por tarea, fakes de red), Playwright (`test-plan.md`: E2E-xx automáticos), curl para API-xx y verificación manual M-xx por el usuario. Detalle en [`test-plan.md`](test-plan.md).

## Referencias

- [`audit.md`](audit.md) — fuente única de hallazgos.
- `docs/roadmap/2026-11-03-creative-social-suite/` — suite previa y su retro (lecciones GOT-005/GOT-006).
