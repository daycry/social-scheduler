---
id: metricool.GOT-004
version: 1
estado: aprobado
category: GOTCHA
evidencia: validated_case
fuentes:
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (Meta, apartado c)
  - docs/roadmap/2026-10-19-social-scheduler/ui-reference.md
  - app/Connectors/Meta/Insights.php
tags:
  - red:meta
  - area:analitica
---

# Meta: impressions y page_fans retiradas; usar views y page_media_view

En Graph v23.0 `page_impressions` y `page_fans` devuelven `(#100) must be a valid insights metric`, y en Instagram `impressions` ya no existe (cuenta ni media): se usa `views`. Aceptadas: `page_media_view`, `page_post_engagements`, `page_follows`; en IG `views`, `reach`, `profile_views`, `accounts_engaged`. `follower_count` no admite `metric_type=total_value` y se pide como serie diaria. La UI de Metricool avisa del mismo cambio.
