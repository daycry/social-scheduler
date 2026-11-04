---
estado: propuesta
category: gotcha
project: metricool
scope: connections
source: docs/roadmap/2026-11-03-creative-social-suite/testing/connections/audit-2026-11-04.md
confidence: observed
tags: [area:connections, protocol:oauth, failure:concurrency]
---

# Las respuestas antiguas y las escrituras de sesión pueden producir reconexiones falsas

**Síntoma:** una cuenta recién reconectada vuelve a pedir autorización; dos marcas abiertas en pestañas distintas pierden uno de los intentos. Una página ordinaria que termina después puede restaurar un state ya consumido.

**Causa raíz probada:** `last_refreshed_at` con precisión de segundos no distingue tokens reemplazados en ese segundo. Marcar reconexión desde una petición antigua sin comparar la generación atribuye su fallo a la conexión nueva. Un state único por proveedor sobrescribe el de otra marca. Bloquear únicamente las rutas OAuth no impide que otras rutas guarden snapshots de sesión obsoletos.

**Qué hacer:** comparar bajo lock un marcador interno de identidad y ciphertext original antes de aplicar resultados o fallos. Mantener un registro autoritativo de intentos vinculado al usuario y contexto del navegador, con creación/consumo atómicos, caducidad y límites. Liberar el lock antes de las llamadas HTTP al proveedor. Un puntero de sesión no puede restablecer una autorización ya consumida.

**Evidencia:** `tests/Feature/Jobs/RefreshTokensTest.php` reproduce los tres resultados antiguos dentro del mismo segundo; `tests/Feature/Connectors/SocialAccountTest.php` verifica la petición rechazada tardía y el aislamiento entre marcas; `tests/Feature/Connections/ConnectionsTest.php` comprueba dos órdenes de callback, state ajeno, caducidad, dos Store con el mismo handler y otro navegador. RED `78721c`, `4dc18d`, `e3364e`; informe enlazado en `source`. La corrección y su revisión no diagnostican por sí solas los eventos reales del VPS.

Pendiente de curación; no se exporta ni se considera aprobado automáticamente.
