---
id: metricool.GOT-001
version: 1
estado: aprobado
category: GOTCHA
evidencia: validated_case
fuentes:
  - docs/roadmap/2026-10-19-social-scheduler/spike-report.md (Meta, hallazgos de configuración)
  - config/social.php (meta.scopes)
tags:
  - red:meta
  - area:oauth
---

# Meta: los permisos se añaden dentro del caso de uso de la app

En apps de Meta creadas por casos de uso, pedir un scope en `/dialog/oauth` no basta: cada permiso debe estar añadido dentro del caso de uso, o el login devuelve "Invalid Scopes". Además hacen falta el dominio en "Dominios de la app" y la plataforma Sitio web, y el redirect URI se registra en *Facebook Login for Business → Configurar*; el primer guardado no persistió en el spike, así que hay que recargar y comprobar.
