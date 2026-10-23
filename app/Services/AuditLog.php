<?php

namespace App\Services;

use App\Models\AuditLog as AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de uso y auditoria de las acciones por MCP: cada llamada a una herramienta (y cada acceso
 * denegado) deja una fila con usuario, key, accion y resultado. Nunca registra el secreto de la key ni el
 * contenido de las publicaciones. Si la escritura falla no tumba la peticion: se deja en el log.
 */
class AuditLog
{
    public const OK = 'ok';

    public const DENIED = 'denied';

    public const ERROR = 'error';

    public function record(?User $user, string $action, string $outcome, ?string $resource = null, int|string|null $resourceId = null, ?string $detail = null, string $channel = 'mcp'): void
    {
        $tokenId = $user?->currentAccessToken()?->id ?? null;

        try {
            AuditLogEntry::create([
                'user_id' => $user?->id,
                'token_id' => $tokenId,
                'channel' => $channel,
                'action' => $action,
                'outcome' => $outcome,
                'resource' => $resource,
                'resource_id' => is_numeric($resourceId) ? (int) $resourceId : null,
                'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
                'ip' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::error('audit.write_failed', ['action' => $action, 'outcome' => $outcome, 'error' => $e->getMessage()]);
        }

        if ($outcome === self::DENIED) {
            Log::warning('access.denied', ['user_id' => $user?->id, 'token_id' => $tokenId, 'channel' => $channel, 'action' => $action, 'resource' => $resource, 'resource_id' => $resourceId, 'ability' => $action]);
        }
    }
}
