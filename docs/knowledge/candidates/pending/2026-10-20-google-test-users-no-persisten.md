---
category: GOTCHA
evidencia: observation
fuentes:
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (YouTube)
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (Decisión G1)
tags:
  - red:google
  - area:oauth
---

# Google: el alta de usuarios de prueba no persistió y el refresh token caduca a 7 días en Testing

En el spike, añadir un test user desde la consola de Google no persistió en los primeros intentos: recargar y comprobar. Sin estar en la lista, el login falla con "Acceso bloqueado". En modo Testing el refresh token caduca a los 7 días; la decisión G1 pasó el consentimiento a "En producción" sin verificar (uso personal) para evitarlo. Esa opción exige página principal, política de privacidad y condiciones publicadas.
