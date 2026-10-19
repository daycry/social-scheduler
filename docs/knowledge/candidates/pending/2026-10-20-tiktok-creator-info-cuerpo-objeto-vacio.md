---
category: GOTCHA
evidencia: observation
fuentes:
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (TikTok)
  - app/Connectors/TikTok/TikTokPublisher.php (cabecera de clase)
tags:
  - red:tiktok
  - area:api
---

# TikTok: creator_info/query exige un objeto JSON vacío {}

`/v2/post/publish/creator/info/query/` responde `invalid_params` si el cuerpo es `[]`; debe enviarse el objeto `{}`. En PHP, un array vacío se serializa como `[]`: hay que forzar objeto.
