---
category: GOTCHA
evidencia: observation
fuentes:
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (Meta, apartado b)
  - app/Connectors/Meta/MetaConnector.php
tags:
  - red:meta
  - area:oauth
---

# Meta: las Páginas de portfolios empresariales no salen en me/accounts

Las Páginas que pertenecen a un portfolio empresarial no aparecen en `me/accounts`. Hay que pedir `business_management` y leer `me/businesses` → `owned_pages` / `client_pages`. Así apareció la Página de prueba (Aipocalipsis) con su IG enlazada. `MetaConnector` lo implementa.
