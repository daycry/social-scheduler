---
proposed_by: architect
category: DECISION
evidencia: human_confirmed_rule
fuentes:
  - docs/roadmap/2026-10-26-metricool-parity-3/design.md (opciones O1 y T1, validadas por el usuario el 2026-10-27)
  - docs/roadmap/2026-10-26-metricool-parity-3/spec.md (§Decisiones de diseño y §Decisiones confirmadas, punto 3)
  - app/Connectors/Contracts/Connector.php:20-54 (contrato solo OAuth)
  - app/Jobs/RefreshSocialAccount.php:70-87,120 (refresh y permanentReason)
  - app/Http/Middleware/HandleInertiaRequests.php:106 (shell.networks)
  - docs/modulos/despliegue.md:113,147 (workers --timeout=120)
  - lexicons app.bsky.embed.images / app.bsky.video.uploadVideo (bluesky-social/bsky, consultados 2026-10-26)
  - developers.facebook.com/docs/threads (get-started, long-lived-tokens, posts, insights, overview; consultados 2026-10-26)
tags:
  - area:conectores
  - area:publicacion
  - feature:bluesky
  - feature:threads
  - iniciativa:2026-10-26-metricool-parity-3
---

# Conectores: contrato segregado (OAuth y credenciales), catálogo de redes en `Network` y Threads como proveedor propio

## Contexto

`Connector` obligaba a implementar `authorizationUrl()` y `exchangeCode()`. Bluesky se conecta con una app password (`createSession`/`refreshSession`), así que no hay redirección OAuth.

Las listas de redes estaban repetidas en unas 22 rutas y ficheros:

- rutas;
- la IA;
- el MCP;
- `Metric::EXPECTED_FLOWS` y `AnalyticsService::SUPPORTED`;
- Vue;
- los tests.

En esta ola entran dos redes seguidas (Bluesky y Threads). Threads exige su propia app de Meta: otro ID y otro secreto, OAuth en `threads.com` y la API en `graph.threads.com`.

## Decisión

**Contrato (C-01, O1).**

- `Connector` se queda con lo común: `provider`, `networks`, `refresh` y `refreshMarginHours`.
- Hay dos interfaces hijas:
  - `OAuthConnector`, con `authorizationUrl` y `exchangeCode`. La implementan Meta, Google, TikTok y Threads;
  - `CredentialConnector`, con `credentialFields` y `connectWithCredentials`. La implementa Bluesky.
- `RefreshSocialAccount` sigue llamando a `Connector::refresh()`.
- El secreto de credencial va en la columna nueva `social_accounts.credential_secret` (`encrypted` y `hidden`).
- Bluesky deja `refresh_expires_at = null`: si su sesión caduca, se recrea con la app password, y así `permanentReason()` no marca la reconexión.

**Catálogo de redes.**

- `Network` es la fuente única de los metadatos de cada red: etiqueta, proveedor, etiqueta corta, color y `authKind`, con `providers()` derivado y `offered()`.
- El frontend lo lee de `shell.networks`.
- Los mapas de datos por red se quedan en su sitio, pero un **test de cobertura** recorre `Network::cases()` y exige en cada red:
  - tipos y reglas de media;
  - `EXPECTED_FLOWS` y `SUPPORTED`;
  - icono, color y etiqueta.

**Threads (C-02, T1).**

- Proveedor `threads` propio, con `ThreadsConnector`, `ThreadsClient`, `ThreadsPublisher` y `ThreadsInsights`.
- Copia la máquina de estados de contenedores de `InstagramPublisher` sin heredar de ella. De `GraphClient` solo se extraen las tablas de códigos de error.
- Se registra solo si `THREADS_APP_ID` está configurado.

**Topes de Bluesky** (decididos por el usuario):

- imagen de **2.000.000 bytes**, configurable, con recompresión GD a una copia derivada;
- vídeo de **unos 50 MB**, configurable. Por encima se rechaza al guardar: `uploadVideo` es una sola petición y el worker corta a los 120 s.

## Alternativas descartadas

- **O2. Contrato único ampliado** (`authKind` más métodos que lanzan) **y listas editadas en su sitio.** Deja métodos muertos en cuatro conectores, y la segunda red vuelve a cazar listas con el mismo riesgo de olvidar alguna.
- **O3. Una `NetworkDefinition` por red, al estilo plugin.** Supone una regresión amplia en las cuatro redes actuales por una ganancia que solo se cobraría a partir de la séptima. `ContentType` no se puede partir fuera de su enum.
- **T2. Núcleo `ContainerFlow` común con Instagram.** Refactoriza el publicador más delicado y ya en producción. Los estados de IG y los de Threads se parecen, pero no son iguales.
- **T3. Threads dentro de `MetaConnector`.** No es viable: Threads usa otro ID de app y otro OAuth. Además, pedir un permiso no activado rompe todo el login de Meta («Invalid Scopes»).
- **Worker de cola con un timeout largo para el vídeo de Bluesky.** El usuario lo descarta para no cambiar el despliegue de HestiaCP.

## Consecuencias

- Una red nueva que olvide una superficie falla en CI.
- Si Bluesky retira las app passwords, `BlueskyConnector` pasa de `CredentialConnector` a `OAuthConnector` sin tocar el resto.
- Los vídeos de Bluesky de más de unos 50 MB no se admiten mientras no se suba el tope por configuración.
