---
category: GOTCHA
content_origin: agent
estado: aprobado
evidencia: validated_case
fuentes:
  - .github/workflows/ci.yml (commit 2f3b33d)
  - tests/Feature/Studio/StudioAudioTest.php
  - docs/roadmap/2026-11-03-creative-social-suite/retro.md
id: metricool.GOT-006
proposed_by: retro
tags:
  - area:ci
  - area:estudio
version: 1
---
# CI: el runner de GitHub necesita ffmpeg/ffprobe para los tests del estudio

`ubuntu-latest` no trae `ffmpeg`. Sin él, `StudioAudioTest` falla con 503 y con «exec: ffmpeg: not found» (16 tests), mientras en local, donde ffmpeg está instalado, pasa. La rama `feature/creative-social-suite` tuvo la CI en rojo del 2026-11-04 al 2026-11-10 sin que se notara. El workflow instala ahora `ffmpeg` con apt antes de los tests. Si se añade otro binario nativo (ffprobe, Chromium o Python), hay que declararlo también en `ci.yml`, y conviene comprobar la CI después de cada push, no solo al abrir la PR.
