# Canva: conexión por marca, exportación, envío y Autofill

Cada marca conecta su cuenta de Canva (Canva Connect API, OAuth 2.0 con PKCE) para listar y crear diseños, abrirlos en Canva, exportarlos a la biblioteca, enviar recursos a Canva y, si el plan lo permite, rellenar plantillas de marca (Autofill). **No compra ni publica nada.** Todo está probado con el Canva falso del servidor E2E y `Http::fake`; el smoke real es CS-M-01 en [pendientes manuales](../referencia/pendientes-manuales.md).

## Alta de la app

1. En Canva Developers, crea la app (pública o privada; una privada solo sirve a su equipo) con OAuth 2.0 y PKCE.
2. Activa los scopes `profile:read`, `design:meta:read`, `design:content:read`, `design:content:write`, `asset:read` y `asset:write`. Para plantillas de marca y Autofill activa además `brandtemplate:meta:read` y `brandtemplate:content:read` y define `CANVA_BRAND_TEMPLATES=true` (sin esa variable la app no los pide a Canva y la conexión funciona como antes).
3. Añade la **Redirect URL** `<APP_URL>/canva/callback` y activa la navegación de retorno con la **Return URL** `<APP_URL>/canva/return`.
4. Define `CANVA_CLIENT_ID` y `CANVA_CLIENT_SECRET` ([configuración](../referencia/configuracion.md)). Sin ellos la función aparece como «no configurada».

## Funciones según el plan

Al conectar, la app lee `/users/me/capabilities` (se cachea 15 min) y activa solo lo que el plan permite. Cada función desactivada muestra su motivo; por ejemplo, el PNG con fondo transparente exige una cuenta Pro. La API devuelve este estado en `GET /api/v1/brands/{brand}/canva` (`features`).

| Función (`key`) | Capacidad del plan | Permisos de la conexión |
|---|---|---|
| `brand_templates` (ver plantillas de marca y sus campos) | `brand_template` | `brandtemplate:meta:read` y `brandtemplate:content:read` |
| `autofill` (crear un diseño rellenando una plantilla) | `brand_template` y `autofill` | los anteriores y `design:content:write` |

Sin la capacidad (o sin los permisos) la página de Canva no muestra la sección de plantillas, los endpoints responden 403 `capability_required` y **no se llama a Canva**. Si la instalación no pide los permisos (`CANVA_BRAND_TEMPLATES` apagado), el motivo lo dice en lugar de pedir reconectar.

## Origen en la biblioteca

Lo exportado entra en la biblioteca como un recurso más con **origen explícito**: `origin` (Canva, id del diseño y enlace) y, en el compositor, «Origen: Canva» con enlace al diseño. Las exportaciones admiten PNG, JPG, MP4 y PDF.

## PDF en la biblioteca

El PDF exportado entra en la biblioteca como media de tipo **`pdf`** (`MediaAsset::KIND_PDF`), no como enlace temporal de 24 h. Se valida con el tipo real (los primeros bytes deben ser `%PDF-`, además de que `finfo` lo reconozca como `application/pdf`; un HTML o una imagen con cabecera de PDF queda `invalido`) y con un tamaño máximo (`MEDIA_MAX_PDF_BYTES`, 50 MiB). En la página de Canva aparece con un **icono de documento** (no se genera miniatura), el nombre, el tamaño, «Origen: Canva» y las acciones **Ver** (`GET media/{asset}/file`, en línea) y **Descargar** (`GET media/{asset}/download`, adjunto); solo su dueño las abre. **Un PDF no se puede publicar en redes:** no aparece en el selector del compositor (`GET media`) ni en `list_media` del MCP, el compositor y la importación por URL o Drive lo rechazan con «Los PDF no se pueden publicar en redes…», el validador de publicaciones da el mismo aviso si un PDF llega adjunto, y la página de Canva lo explica con el aviso común (`PermissionNotice`, el mismo de los permisos de Meta Ads y el buzón). Solo el export de Canva trae PDF a la biblioteca (`RemoteMediaImporter::allowsDocument`).

## Contrato verificado contra la documentación oficial

Consultado el 2026-11-12 en la [guía de navegación de retorno](https://www.canva.dev/docs/connect/return-navigation-guide/): la Return URL recibe `?correlation_jwt=<JWT>`; Canva publica sus claves como JWKS en `https://api.canva.com/rest/v1/connect/keys` y el ejemplo oficial valida el JWT contra ese conjunto con `audience = {CLIENT_ID}` y lee `sub`, `team_id` y `type` (`rti`). Autofill y plantillas de marca: [guía de Autofill](https://www.canva.dev/docs/apps/rest-apis/autofill-guide/) y las referencias de `Create design autofill job`, `Get design autofill job`, `List brand templates` y `Get brand template dataset` (consultadas el 2026-11-12): `POST /autofills` con `{type: create_from_brand_template, brand_template_id, data: {CAMPO: {type: text, text}}, title}`, `GET /autofills/{id}` (`in_progress`, `success` con `result.design`, `failed`), capacidades `brand_template` y `autofill`, planes Pro/Teams/Enterprise y los scopes `brandtemplate:meta:read` (listar) y `brandtemplate:content:read` (campos). **Sin verificar:** los códigos de `error` de un autofill fallido (se muestra un mensaje genérico) y el comportamiento real con una cuenta que tenga Autofill; el algoritmo (RS256, el de la especificación de la tarea; la guía no lo nombra), que no se exija `iss` (la guía no lo documenta) y el comportamiento real contra Canva, que depende del smoke del usuario (CS-M-01). Las pruebas usan un par RSA generado en el test y `Http::fake` del endpoint de claves.

## Cola y límites

Los trabajos (`RunCanvaJob`) van en la cola **`media`** (`media.import_queue`) y necesitan su worker. Sondeo acotado: como máximo 40 consultas con 3 s de espera; hasta 3 trabajos activos a la vez; `$timeout` del job de 100 s.

| Límite | Detalle |
|---|---|
| Firma del JWT de retorno | `CanvaJwks` verifica la firma **RS256** del `correlation_jwt` con las claves públicas de Canva (`GET https://api.canva.com/rest/v1/connect/keys`, sin credenciales), el `kid`, la caducidad (`exp` obligatorio) y la audiencia (`aud` = `CANVA_CLIENT_ID`); `resolveReturn` exige además `type = rti` y el `correlation_state` firmado por esta app. Las claves se cachean 1 h y un `kid` desconocido las renueva como mucho una vez por minuto (rotación). Si Canva no responde o la firma no cuadra, el retorno se rechaza. Librería: `firebase/php-jwt` (dependencia directa) |
| PDF | Entra en la biblioteca como documento (hasta `MEDIA_MAX_PDF_BYTES`); no es media publicable. La columna `canva_jobs.download_urls` se eliminó (migración `2026_12_21_090000`) |
| Autofill | Solo campos de **texto**; imagen, vídeo, gráfico y hoja conservan el valor de la plantilla (Canva usa el de la plantilla en los campos omitidos). Hasta 50 campos de 2000 caracteres |
| Host de descarga | Cada salto de redirección debe cumplir `services.canva.download_host_pattern` (`/^(?:[a-z0-9-]+\.)*canva\.com$/`) además de `UrlGuard`. Si el host real de Canva es otro, hay que ajustar el patrón |
| MP4 | Solo si el diseño es de vídeo (Canva rechaza el resto); se valida con `ffprobe` |
| Envío a Canva | Hasta 50 MiB por recurso |
| Conexión OAuth | Solo desde la web; la API y el MCP nunca ven tokens |

## Plantillas de marca y Autofill

Con la capacidad del plan, la página de Canva lista las plantillas de marca (`GET /brand-templates`), muestra los campos de la elegida (`GET /brand-templates/{id}/dataset`) y crea un diseño nuevo con los textos que rellenes (`POST /autofills`, modo `create_from_brand_template`). Es un trabajo asíncrono más (`kind = autofill`, `RunCanvaJob`, sondeo acotado): al terminar guarda el `design_id` del diseño creado y la página ofrece «Abrir el diseño en Canva» (enlace fresco con el `correlation_state` de retorno). Los campos vacíos se omiten. No exporta ni publica nada: para llevarlo a la biblioteca se exporta después como cualquier diseño.

**Pendiente (usuario):** probarlo con una cuenta cuyo plan incluya Autofill; hasta entonces el flujo solo está comprobado con `Http::fake` y con el Canva falso del servidor E2E.

## API y MCP

Abilities `canva:read` (estado, diseños, plantillas, trabajo) y `canva:write` (exportar, enviar, Autofill): `GET /api/v1/brands/{brand}/canva`, `.../canva/designs`, `.../canva/templates`, `.../canva/templates/{template}/dataset`, `.../canva/jobs/{job}`, `POST .../canva/exports`, `.../canva/uploads` y `.../canva/autofills` ([OpenAPI](../api/openapi.yaml)). MCP: `manage_canva` con `status`, `designs`, `templates`, `export`, `send`, `autofill` y `get`.

## Despliegue

Registra las dos URLs en Canva ([redirect URIs](despliegue.md#redirect-uris-de-los-conectores-de-redes-y-de-canva)) y asegúrate de que corre el worker de la cola `media`.
