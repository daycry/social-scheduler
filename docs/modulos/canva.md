# Canva: conexión por marca, exportación y envío

Cada marca conecta su cuenta de Canva (Canva Connect API, OAuth 2.0 con PKCE) para listar y crear diseños, abrirlos en Canva, exportarlos a la biblioteca y enviar recursos a Canva. **No compra ni publica nada.** Todo está probado con el Canva falso del servidor E2E y `Http::fake`; el smoke real es CS-M-01 en [pendientes manuales](../referencia/pendientes-manuales.md).

## Alta de la app

1. En Canva Developers, crea la app (pública o privada; una privada solo sirve a su equipo) con OAuth 2.0 y PKCE.
2. Activa los scopes `profile:read`, `design:meta:read`, `design:content:read`, `design:content:write`, `asset:read` y `asset:write`.
3. Añade la **Redirect URL** `<APP_URL>/canva/callback` y activa la navegación de retorno con la **Return URL** `<APP_URL>/canva/return`.
4. Define `CANVA_CLIENT_ID` y `CANVA_CLIENT_SECRET` ([configuración](../referencia/configuracion.md)). Sin ellos la función aparece como «no configurada».

## Funciones según el plan

Al conectar, la app lee `/users/me/capabilities` (se cachea 15 min) y activa solo lo que el plan permite. Cada función desactivada muestra su motivo; por ejemplo, el PNG con fondo transparente exige una cuenta Pro y Brand Templates y Autofill no están implementados. La API devuelve este estado en `GET /api/v1/brands/{brand}/canva` (`features`).

## Origen en la biblioteca

Lo exportado entra en la biblioteca como un recurso más con **origen explícito**: `origin` (Canva, id del diseño y enlace) y, en el compositor, «Origen: Canva» con enlace al diseño. Las exportaciones admiten PNG, JPG, MP4 y PDF.

## Cola y límites

Los trabajos (`RunCanvaJob`) van en la cola **`media`** (`media.import_queue`) y necesitan su worker. Sondeo acotado: como máximo 40 consultas con 3 s de espera; hasta 3 trabajos activos a la vez; `$timeout` del job de 100 s.

| Límite | Detalle |
|---|---|
| Firma del JWT de retorno | **No se verifica con JWKS.** `resolveReturn` valida las reclamaciones (`type`, `aud`, `exp`) y, sobre todo, el `correlation_state` firmado por esta app y ligado al usuario. Verificar la firma exigiría una librería JWT directa; queda como mejora |
| PDF | No entra en la biblioteca: se devuelve solo como **enlace temporal de 24 h** de Canva (`download_urls`) |
| Host de descarga | Cada salto de redirección debe cumplir `services.canva.download_host_pattern` (`/^(?:[a-z0-9-]+\.)*canva\.com$/`) además de `UrlGuard`. Si el host real de Canva es otro, hay que ajustar el patrón |
| MP4 | Solo si el diseño es de vídeo (Canva rechaza el resto); se valida con `ffprobe` |
| Envío a Canva | Hasta 50 MiB por recurso |
| Conexión OAuth | Solo desde la web; la API y el MCP nunca ven tokens |

## API y MCP

Abilities `canva:read` (estado, diseños, trabajo) y `canva:write` (exportar, enviar): `GET /api/v1/brands/{brand}/canva`, `.../canva/designs`, `.../canva/jobs/{job}`, `POST .../canva/exports` y `.../canva/uploads` ([OpenAPI](../api/creative-suite.openapi.yaml)). MCP: `manage_canva` con `status`, `designs`, `export`, `send` y `get`.

## Despliegue

Registra las dos URLs en Canva ([redirect URIs](despliegue.md#redirect-uris-de-los-conectores-de-redes-y-de-canva)) y asegúrate de que corre el worker de la cola `media`.
