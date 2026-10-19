# Informe del spike de plataformas — Social Scheduler

> Plantilla a rellenar durante T-01..T-05. Pegar evidencia real (IDs, respuestas de API, fechas). Sin datos aún.

## Preparación
| Plataforma | App creada (nombre/ID) | Modo (desarrollo/testing) | Roles/cuentas propias | Dominio HTTPS / redirect URI | Estado |
|---|---|---|---|---|---|
| Meta | | | | | pendiente |
| TikTok | | | | | pendiente |
| Google/YouTube | | | | | pendiente |

Dominio HTTPS elegido (túnel / VPS Hestia): _pendiente_

## Meta
- (a) ¿Basta el modo desarrollo para publicar y leer insights en cuentas propias? **OAuth e insights de Página: sí** (2026-10-19, app en modo desarrollo, usuario con rol admin). Publicación: pendiente de prueba.
- (b) ¿Login de IG vía Facebook Login o Instagram Login? Motivo: en curso con Facebook Login for Business (`/dialog/oauth` + `scope` funciona; no exigió `config_id`). **Ninguna de las 3 Páginas autorizadas tiene IG Business/Creator enlazada** → IG sin probar.
- (c) ¿Existe `impressions` o solo `views`? Métricas aceptadas/rechazadas (Página, Graph v23.0): aceptadas `page_media_view`, `page_post_engagements`, `page_follows`; **rechazadas** `page_impressions` y `page_fans` (`(#100) must be a valid insights metric`, deprecadas). IG: sin probar.
- Hallazgos de configuración (gotchas para F2): en apps por casos de uso, cada permiso debe **añadirse dentro del caso de uso** («Invalid Scopes» si no); hace falta el dominio en «Dominios de la app» + plataforma Sitio web; el redirect se registra en *Facebook Login for Business → Configurar* (el primer guardado no persistió: verificar recargando).
- Token: larga duración sí; `data_access_expires_at` 2027-01-17 (90 días, renovar con re-login).
- Tipos publicados con evidencia (FB texto/foto, IG imagen/reel/story):
- Límites observados (publicaciones/24h, tamaños, caducidad de tokens):
- Veredicto: viable / limitado / no viable

## TikTok
- Permisos y `privacy_level_options` devueltos por `creator_info/query`:
- ¿Solo `SELF_ONLY` sin auditoría?
- ¿PULL_FROM_URL exige dominio verificado?
- Duración access/refresh token:
- Límite de usuarios/día; coste y plazo de la auditoría:
- Veredicto: viable / limitado / no viable

## YouTube
- Visibilidad real del vídeo subido (¿privado forzado?):
- Unidades de cuota por subida y subidas/día reales vs. volumen del usuario:
- Caducidad del refresh token en Testing (7 días) — fecha de emisión / fecha de fallo:
- Proceso de verificación/auditoría de Google (plazo):
- Veredicto: viable / limitado / no viable

## Tabla de viabilidad
| Plataforma | Publicar | Insights | Veredicto | Riesgo principal |
|---|---|---|---|---|
| Facebook | | | | |
| Instagram | | | | |
| TikTok | | | | |
| YouTube | | | | |

## Decisión G1
Pendiente del usuario (T-05). Elegir por red (TikTok, YouTube):
- **A** — Continuar con la red tal cual (con limitaciones documentadas).
- **B** — Continuar e iniciar la auditoría/verificación de la plataforma en paralelo.
- **C** — Posponer la red (T-13, T-14, T-26, T-27, T-33, T-34 pasan a `cancelado`).
- **D** — Sustituir por flujo manual asistido (p. ej. subida desde YouTube Studio / bandeja de TikTok).

Meta: confirmación de continuar — _pendiente_
Decisión TikTok: _pendiente_ · Decisión YouTube: _pendiente_
