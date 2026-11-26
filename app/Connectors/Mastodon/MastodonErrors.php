<?php

namespace App\Connectors\Mastodon;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;

/**
 * Traduce los errores de la API de Mastodon a las excepciones de la cola: 401/403 -> reconectar; 429 (o las cabeceras `X-RateLimit-*`) -> cuota con
 * espera; red y 5xx -> transitorio; 422 y el resto -> permanente con un mensaje legible. Nunca incluye el token.
 */
final class MastodonErrors
{
    public static function translate(MastodonException $e): PublishException|ReconnectRequiredException
    {
        if (in_array($e->status, [401, 403], true)) {
            return new ReconnectRequiredException('Mastodon rechazó el token de la cuenta (revocado o sin permisos): vuelve a conectarla con un token con los permisos write:statuses, write:media y read:accounts.');
        }

        if ($e->status === 429) {
            return new QuotaExceededException('Mastodon ha limitado temporalmente las peticiones de la cuenta; se reintentará.', $e->retryAt ?? now()->addMinutes(5), 'limite_temporal');
        }

        if ($e->isTransient()) {
            return new TransientPublishException('Mastodon no respondió correctamente; se reintentará.', 'mastodon_no_disponible');
        }

        $detail = $e->detail !== '' ? ': '.$e->detail : '.';

        return $e->status === 422
            ? new PermanentPublishException('Mastodon no admite el contenido de la publicación'.$detail, 'contenido_rechazado')
            : new PermanentPublishException('Mastodon rechazó la publicación'.$detail, 'rechazada');
    }
}
