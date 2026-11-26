<?php

namespace App\Connectors\Telegram;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publicacion en un canal de Telegram (T-87), siempre como texto plano (sin `parse_mode`: ningun caracter se interpreta como formato):
 *  - `telegram_text`: `sendMessage` (1 a 4096 caracteres).
 *  - `telegram_photo`: `sendPhoto` por multipart (hasta 10 MB; ancho + alto <= 10000 y proporcion <= 20) con el texto como `caption` (hasta 1024). El limite
 *    de cada texto se valida al guardar (`media_specs`), asi que no falla al publicar por longitud. Varias fotos (`sendMediaGroup`) no estan en el contrato leido.
 *
 * La URL publicada es `https://t.me/<usuario>/<message_id>` si el canal tiene @usuario publico; en un canal privado Telegram no da enlace publico y la URL es null.
 * Telegram no admite idempotencia: si una peticion acaba en timeout con la respuesta perdida, el reintento puede duplicar el mensaje (`findPublished` no puede comprobarlo).
 */
class TelegramPublisher implements Publisher
{
    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $token = (string) $account->access_token;
        if ($token === '' || $account->external_id === '') {
            throw new ReconnectRequiredException('La cuenta de Telegram no tiene el bot o el canal guardados: vuelve a conectarla.');
        }

        $api = new TelegramClient($token);
        $text = trim((string) $request->text);

        try {
            $message = match ($request->type) {
                ContentType::TelegramText => $api->call('sendMessage', ['chat_id' => $account->external_id, 'text' => $text]),
                ContentType::TelegramPhoto => $this->sendPhoto($api, $account, $request, $text),
                default => throw new PermanentPublishException('Telegram no admite este tipo de contenido.', 'tipo_no_admitido'),
            };
            $request->budget()->spent();
        } catch (TelegramException $e) {
            throw TelegramErrors::translate($e);
        }

        $id = (string) ($message['message_id'] ?? '');
        if ($id === '') {
            throw new PermanentPublishException('Telegram no devolvió el identificador del mensaje.', 'sin_id');
        }

        $username = (string) ($account->meta['chat_username'] ?? '');

        return PublishResult::published($id, $username !== '' ? 'https://t.me/'.$username.'/'.$id : null, ['date' => $message['date'] ?? null]);
    }

    /** Telegram no deja comprobar si un mensaje ya salio: un reintento vuelve a enviarlo. */
    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        return null;
    }

    /** @return array<string, mixed> */
    private function sendPhoto(TelegramClient $api, SocialAccount $account, PublishRequest $request, string $caption): array
    {
        $media = $request->firstMedia();
        if ($media === null || $media->isVideo() || $media->disk === null || $media->path === null) {
            throw new PermanentPublishException('Falta la foto que publicar en Telegram.', 'media_invalida');
        }

        try {
            $bytes = (string) Storage::disk($media->disk)->get($media->path);
        } catch (Throwable) {
            throw new PermanentPublishException('No se pudo leer la foto.', 'media_ilegible');
        }
        if ($bytes === '') {
            throw new PermanentPublishException('No se pudo leer la foto.', 'media_ilegible');
        }
        if (strlen($bytes) > 10 * 1024 * 1024) {
            throw new PermanentPublishException('La foto pesa más de los 10 MB que admite Telegram.', 'archivo_grande');
        }

        // Ancho + alto <= 10000: es una regla de Telegram que `media_specs` no puede expresar (suma de dos lados).
        $size = @getimagesizefromstring($bytes);
        if ($size !== false && $size[0] + $size[1] > 10000) {
            throw new PermanentPublishException('La foto es demasiado grande para Telegram (ancho + alto superan los 10000 píxeles).', 'dimensiones');
        }

        return $api->call('sendPhoto', array_filter(['chat_id' => $account->external_id, 'caption' => $caption], fn ($v) => $v !== ''), ['name' => 'photo', 'contents' => $bytes, 'filename' => basename($media->path)]);
    }
}
