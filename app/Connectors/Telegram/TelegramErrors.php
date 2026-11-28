<?php

namespace App\Connectors\Telegram;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;

/**
 * Traduce los errores de la Bot API a las excepciones de la cola: `retry_after` (flood control) -> espera; 401/403, «bot was kicked» o «not enough rights» ->
 * reconectar; red y 5xx -> transitorio; el resto -> permanente con un mensaje legible. Nunca incluye el token ni la URL.
 */
final class TelegramErrors
{
    /** Fragmentos de `description` (en minusculas) que significan «el bot ya no puede publicar en el canal». */
    private const NO_ACCESS = ['bot was kicked', 'not enough rights', 'bot is not a member', 'have no rights to send', 'chat not found'];

    public static function translate(TelegramException $e): PublishException|ReconnectRequiredException
    {
        $description = strtolower($e->description);

        if ($e->retryAfter !== null || $e->status === 429) {
            return new QuotaExceededException('Telegram ha limitado temporalmente los envíos al canal; se reintentará.', now()->addSeconds($e->retryAfter ?? 30), 'limite_temporal');
        }

        if (in_array($e->status, [401, 403], true) || array_filter(self::NO_ACCESS, fn (string $needle) => str_contains($description, $needle)) !== []) {
            return new ReconnectRequiredException('Telegram rechazó al bot (token revocado, bot expulsado del canal o sin permiso para publicar): vuelve a conectar el canal y comprueba que el bot sigue siendo administrador con permiso para publicar mensajes.');
        }

        // Timeout o corte despues de enviar: Telegram puede haber publicado ya y no deja comprobarlo. Reintentar duplicaria el mensaje: se para y el usuario lo revisa.
        if ($e->ambiguous) {
            return new PermanentPublishException('Telegram no confirmó el envío (tiempo agotado o conexión cortada): revisa el canal antes de volver a publicar para no duplicar el mensaje.', 'resultado_incierto');
        }

        if ($e->isTransient()) {
            return new TransientPublishException('Telegram no respondió correctamente; se reintentará.', 'telegram_no_disponible');
        }

        return new PermanentPublishException('Telegram rechazó la publicación'.($e->description !== '' ? ': '.$e->description : '.'), 'rechazada');
    }
}
