<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;

/**
 * Traduce los errores de XRPC a las excepciones de la cola: sesion invalida -> reconectar; limites y red -> transitorio;
 * contenido o cuenta rechazados -> permanente con un mensaje legible. Nunca incluye credenciales.
 */
final class BlueskyErrors
{
    /** Errores de cuenta que no se arreglan reintentando ni reconectando con la misma app password. */
    private const ACCOUNT_BLOCKED = ['AccountTakedown', 'AccountSuspended', 'AccountDeactivated'];

    public static function translate(BlueskyException $e): PublishException|ReconnectRequiredException
    {
        if ($e->isSessionError()) {
            return new ReconnectRequiredException('Bluesky rechazo la sesion de la cuenta: vuelve a conectarla con una app password.');
        }

        if (in_array($e->errorCode, self::ACCOUNT_BLOCKED, true)) {
            return new PermanentPublishException('Bluesky ha suspendido o desactivado la cuenta; no se puede publicar.', 'cuenta_bloqueada');
        }

        if ($e->errorCode === 'RateLimitExceeded' || $e->status === 429) {
            return new TransientPublishException('Bluesky ha limitado temporalmente las peticiones de la cuenta; se reintentara.', 'limite_temporal');
        }

        if ($e->isTransient()) {
            return new TransientPublishException('Bluesky no respondio correctamente; se reintentara.', 'bluesky_no_disponible');
        }

        $message = trim($e->getMessage());

        return match (true) {
            $e->errorCode === 'BlobTooLarge' || (str_contains(strtolower($message), 'blob') && str_contains(strtolower($message), 'large')) => new PermanentPublishException('Bluesky rechazo un archivo por ser demasiado grande. Baja el tope de imagen o usa un archivo mas pequeno.', 'archivo_grande'),
            $e->errorCode === 'InvalidMimeType' || str_contains(strtolower($message), 'mime') => new PermanentPublishException('Bluesky no admite el formato de uno de los archivos.', 'formato_no_admitido'),
            default => new PermanentPublishException('Bluesky rechazo la publicacion'.($message !== '' ? ': '.mb_substr($message, 0, 200) : '.'), 'rechazada'),
        };
    }
}
