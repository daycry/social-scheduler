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

> **Nota del curator (needs_changes, 2026-10-20):** la evidencia sigue en `observation`; GOTCHA exige `validated_case`. (1) El fallo de persistencia del test user se vio "en los primeros intentos" sin reproducción ni causa. (2) La caducidad a 7 días no está confirmada en el spike: `spike-report.md` pide re-ejecutar `--check` a partir del 2026-10-26. (3) Mezcla dos aprendizajes (alta de test user / pasar a "En producción", que ya es la decisión G1). Reenviar tras confirmar la caducidad, citando el resultado, y separar o acotar a lo validado; el pase a producción con sus requisitos (páginas de marca) cabe mejor como ADR/decisión G1.
