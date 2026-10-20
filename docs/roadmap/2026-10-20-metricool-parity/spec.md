---
spec: "metricool-parity"
titulo: "Paridad funcional y de experiencia con Metricool"
estado: aprobada
creado: "2026-10-20"
origen: "Petición del usuario: «clona la aplicación de Metricool, déjala perfecta y sin errores, incluida la home, login, registro… todo»"
---

# Spec — Paridad funcional y de experiencia con Metricool

> Interpretación acordada: **clon funcional y de UX**, con identidad propia (nombre «Social Scheduler», logo, paleta, textos e ilustraciones propios). No se copian marca, logos, textos, ilustraciones ni código de Metricool. Uso personal (sin planes de pago, equipos ni facturación).

## Inventario de Metricool (recorrido 2026-10-20, solo lectura)

| Módulo Metricool | Submódulos | Estado en Social Scheduler | Decisión |
|---|---|---|---|
| Web pública | Hero, bloques por módulo, «todo en un panel», FAQ, CTA, footer legal | Welcome de Laravel | **Construir** (P1) |
| Auth | Login, registro, recuperar contraseña, verificar email | Breeze en inglés | **Rediseñar en español** (P1) |
| Shell | Barra superior con módulos, selector de marca, campana, menú de usuario; barra lateral contextual por módulo | Breeze básico | **Construir** (P1) |
| Analytics | Resumen, página por red (overview, posts), estados vacíos «Conecta X» | Hecho (Fase 7) | **Pulir + estados vacíos** (P1) |
| Analytics · Competidores | Comparar con cuentas de terceros | No existe | **Construir para Instagram** vía Business Discovery (P2) |
| Analytics · Hashtag Tracker | Seguimiento de hashtags | No existe | **Fuera**: requiere *Instagram Public Content Access* (revisión de Meta). Documentado |
| Planning · Calendario / Lista | Semana/mes/lista, crear post | Hecho (Fase 3) | **Pulir** (P1) |
| Planning · Mejores horas | Mapa de calor por red | No existe (fuera de v1) | **Construir** con métricas propias por hora/día (P2) |
| Planning · Biblioteca | Contenido guardado/borradores reutilizables | Borradores existen | **Construir** biblioteca (duplicar a programación) (P2) |
| Planning · Papelera | Publicaciones borradas recuperables | Borrado definitivo | **Construir** papelera con restaurar y purga a 30 días (P2) |
| Planning · Autolistas | Colas de contenido recurrente | No existe | **Construir** listas con franjas semanales y reciclado (P3) |
| Planning · Flows | Respuestas automáticas a DM/comentarios | No existe | **Fuera**: `instagram_manage_messages` + webhooks + revisión de Meta. Documentado |
| Inbox | Comentarios y DMs unificados | No existe | **Construir comentarios** de IG/FB (leer, responder, ocultar) con scopes de comentarios; DMs fuera (revisión de Meta) (P3) |
| Reporting | Informes PDF, dashboards, Looker | Hecho (Fase 8) | **Pulir** (P1) |
| SmartLinks | Microsite «link in bio» con analítica de clics | No existe | **Construir** página pública `/l/{slug}` con bloques y clics (P2) |
| Ads | Meta/Google/TikTok Ads | No existe | **Meta Ads solo lectura** (`ads_read`) (P3); Google/TikTok Ads fuera (token de desarrollador / app aparte). Documentado |
| Ajustes de marca | Nombre, zona horaria, avatar, redes | Parcial | **Construir** página de ajustes de marca (P1) |
| Asistente IA | Generar textos | No existe | **Opcional** tras flag `AI_ASSISTANT` con Claude API si hay clave (P3) |
| Apps móviles, equipos, planes | — | — | **Fuera** (uso personal) |

## Criterios de aceptación

- **PA-01** Ninguna pantalla muestra el Welcome de Laravel ni textos en inglés de Breeze; toda la UI en español.
- **PA-02** Portada pública con propuesta de valor, módulos, FAQ y CTA; enlaces a /privacy y /terms.
- **PA-03** Shell con selector de marca persistente y barra lateral por módulo; responsive (360 px → escritorio).
- **PA-04** Cada red sin cuenta muestra estado vacío con CTA que inicia el OAuth.
- **PA-05** Mejores horas: mapa de calor 7×24 por red a partir de las métricas propias, con mínimo de datos y «no disponible» si no hay suficientes.
- **PA-06** Biblioteca y papelera: guardar contenido reutilizable; borrar envía a papelera; restaurar; purga a 30 días.
- **PA-07** SmartLinks: crear/editar microsite con título, avatar, bloques de enlace; página pública sin login; contador de visitas y clics sin datos personales.
- **PA-08** Competidores de Instagram: añadir @usuario de cuenta Business/Creator, snapshot diario de seguidores/posts, comparativa.
- **PA-09** Autolistas: colas con franjas semanales que generan publicaciones programadas.
- **PA-10** Inbox de comentarios IG/FB: listar, responder y ocultar comentarios de publicaciones propias.
- **PA-11** Meta Ads (solo lectura): cuentas publicitarias, campañas y métricas básicas.
- **PA-12** Sin errores: suite verde, revisión adversarial sin gaps Critical/Important, E2E en navegador de los flujos principales, revisión de accesibilidad.
