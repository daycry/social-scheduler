# Servidor MCP y API keys

Servidor MCP por HTTP para que un agente (p. ej. Claude) programe y consulte publicaciones. Usa `laravel/mcp` ~1.0.2 y Sanctum.

## Piezas

| Pieza | Ruta |
|---|---|
| Registro de la ruta | `routes/ai.php` |
| Servidor | `app/Mcp/Servers/SchedulerServer.php` |
| Herramientas | `app/Mcp/Tools/*.php` (base: `SchedulerTool.php`) |
| Permisos | `app/Mcp/Abilities.php` |
| API keys (web) | `ApiKeyController`, `resources/js/Pages/ApiKeys/Index.vue` |
| Auditoría | `app/Services/AuditLog.php`, tabla `audit_logs` |

```php
Mcp::web('/mcp', SchedulerServer::class)->middleware(['auth:sanctum', 'throttle:mcp']);
```

## Autenticación

El Bearer es una API key personal con prefijo `msk_` (`SANCTUM_TOKEN_PREFIX`). Sanctum corre con `guard=[]`: una sesión web **no** autentica `/mcp`. La key se muestra una sola vez al crearla; en la base solo queda el hash. Caduca a los 365 días por defecto (`MCP_KEY_DEFAULT_DAYS`) con tope de 730 (`MCP_KEY_MAX_DAYS`). Crear y revocar quedan en `audit_logs` sin el secreto.

Límites: `throttle:mcp-ip` (120/min, antes de auth, frena la adivinación de keys) y `throttle:mcp` (60/min por key).

## Abilities

| Ability | Permite |
|---|---|
| `brands:read` | marcas, cuentas conectadas y media |
| `posts:read` | publicaciones, estado y calendario |
| `posts:write` | crear, programar, editar, cancelar y borrar |
| `stats:read` | estadísticas de marcas, redes y publicaciones |
| `reports:write` | generar y descargar informes |

`*` también vale. Si falta el permiso, la herramienta devuelve error y registra el intento `DENIED` en la auditoría.

## Herramientas (14)

`list_brands`, `list_accounts`, `list_media`, `get_calendar`, `get_post`, `create_post`, `update_post`, `reschedule_post`, `cancel_post`, `delete_post`, `get_stats`, `get_post_stats`, `generate_report`, `get_report`.

Flujo recomendado (de las instrucciones del servidor): `list_brands` → `list_accounts` (cuentas y tipos válidos) → `list_media` → `create_post`. `get_report` devuelve una URL firmada de 5 min.

## Conectar un cliente

```bash
claude mcp add --transport http scheduler https://<APP_URL>/mcp --header "Authorization: Bearer msk_..."
```

Nginx/Hestia debe pasar la cabecera `Authorization` a PHP-FPM (M-F6-2, pendiente). `MCP_ALLOWED_ORIGINS` añade orígenes de navegador permitidos; sin cabecera `Origin` siempre se permite. OAuth 2.1 vía Passport no está incluido (ampliación opcional).

Aislamiento: cada herramienta ejecuta sobre los servicios con el usuario de la key; un recurso ajeno responde "no encontrado" (`NotOwned`). Pruebas en `tests/Feature/Mcp/` y `tests/Feature/Isolation/`.
