---
project: metricool
scope: studio-document
category: gotcha
estado: propuesta
source: docs/roadmap/2026-11-03-creative-social-suite/tasks.md
confidence: observed
tags:
  - component:studio
  - runtime:php-js
  - topic:json-roundtrip
---

# PHP convierte filtros vacíos de objeto a lista al guardar documentos

**Síntoma.** La primera QA nativa de presentación AV guarda el documento con HTTP200, pero al recargar el editor queda en blanco y desaparece Presentar. La consola contiene `pages.0.elements.0.filters: debe ser un objeto.`.

**Causa probada.** El JSON de recarga del editor contiene `video.filters:[]`. La decodificación asociativa de PHP y su posterior codificación no conservan la identidad de `{}` vacío. El validador JS exigía un objeto incluso para los filtros vacíos. El documento exacto extraído de la traza falla; cambiando sólo ese campo a `{}` valida completo. Un roundtrip real con `php -n` reproduce cuatro contextos: v1/v2, imagen y vídeo, con y sin grupo.

**Qué hacer.** Normalizar únicamente la lista vacía sin propiedades enumerables extra dentro del campo de filtros a un objeto vacío canónico. Conservar los validadores de objetos, listas no vacías, claves y rangos. Comprobar estabilidad tras guardar y reabrir y que el documento recibido no se muta.

**Evidencia.** `tests/frontend/studio-document-filter-roundtrip.test.mjs`, RED funcional4/4 antes del cambio y GREEN7/7;28 vecinos puros pasan. Diagnóstico y raw inmutables en `storage/framework/testing/t12-presentation-reload-diagnosis1/`, con traza original SHA `a97f8f9f50d270d21dda69d07f9680c1bf33c571649dd5ecfa1d582288ca9ff8`. Esta propuesta registra la causa y la regresión pura; revisión independiente, compilación posterior y aceptación nativa del fix pendientes. No acredita reproducción audiovisual ni cierre del roadmap. Pendiente de knowledge-curator, sin promoción automática.
