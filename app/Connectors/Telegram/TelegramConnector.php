<?php

namespace App\Connectors\Telegram;

use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Models\SocialAccount;

/**
 * Conector de Telegram (T-87): se conecta con el token de un bot (BotFather) y el canal donde publica (`@canal` o su id numerico). El token se guarda cifrado
 * en `access_token` (lo recifra `app:reencrypt`) y no caduca. Solo se admite un CANAL (`type = channel`): los grupos y supergrupos tienen otras reglas de
 * permisos y de limite de envio que la documentacion leida no cubre, y la comprobacion de «puede publicar» (`can_post_messages`) es la de los canales.
 * `external_id` = id del chat, `name` = titulo del canal (o su @usuario).
 */
class TelegramConnector implements CredentialConnector
{
    public function provider(): string
    {
        return 'telegram';
    }

    public function networks(): array
    {
        return [Network::Telegram];
    }

    /** El token de un bot no caduca: nunca hay renovacion (`token_expires_at` queda en null). */
    public function refreshMarginHours(): int
    {
        return 0;
    }

    public function credentialIntro(): string
    {
        return 'Conecta un canal de Telegram con el token de un bot creado con BotFather. El bot tiene que ser administrador del canal con permiso para publicar mensajes. El token se guarda cifrado y puedes revocarlo desde BotFather cuando quieras.';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'bot_token', 'label' => 'Token del bot', 'type' => 'password', 'help' => 'Te lo da @BotFather al crear el bot con /newbot. Tiene el formato 123456789:AAA...'],
            ['name' => 'channel', 'label' => 'Canal', 'type' => 'text', 'help' => 'El @usuario público del canal (por ejemplo @micanal) o su id numérico (por ejemplo -1001234567890).'],
        ];
    }

    public function connectWithCredentials(array $input): array
    {
        $token = trim((string) ($input['bot_token'] ?? ''));
        $channel = trim((string) ($input['channel'] ?? ''));

        if ($token === '' || $channel === '') {
            throw new ConnectorException('Escribe el token del bot y el canal de Telegram.');
        }
        // El token va en la ruta de la peticion: solo se admite su forma real, nunca algo que pueda alterar la URL.
        if (preg_match('/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/D', $token) !== 1) {
            throw new ConnectorException('El token del bot no tiene el formato de Telegram (123456789:AAA...). Cópialo de @BotFather.');
        }
        if (preg_match('/^(?:@[A-Za-z][A-Za-z0-9_]{3,31}|-?\d{1,20})$/D', $channel) !== 1) {
            throw new ConnectorException('Escribe el canal como @usuario (por ejemplo @micanal) o con su id numérico.');
        }

        $api = new TelegramClient($token);

        try {
            $bot = $api->call('getMe');
            $botId = (string) ($bot['id'] ?? '');
            $chat = $api->call('getChat', ['chat_id' => $channel]);
            $chatId = (string) ($chat['id'] ?? '');

            if ($botId === '' || $chatId === '') {
                throw new ConnectorException('Telegram no devolvió los datos del bot o del canal.');
            }
            if (($chat['type'] ?? null) !== 'channel') {
                throw new ConnectorException('Ese chat no es un canal de Telegram. Solo se pueden conectar canales, no grupos ni chats privados.');
            }

            $member = $api->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
        } catch (TelegramException $e) {
            throw new ConnectorException(match (true) {
                $e->status === 401 => 'Telegram rechazó el token del bot. Comprueba que lo has copiado entero de @BotFather.',
                $e->status === 400 && str_contains(strtolower($e->description), 'chat not found') => 'Telegram no encuentra ese canal. Comprueba el @usuario o el id y que el bot es administrador del canal.',
                $e->status === 403 => 'Telegram no deja al bot acceder a ese canal. '.self::ADMIN_HELP,
                $e->status > 0 => 'Telegram no pudo validar el canal (error '.$e->status.').',
                default => $e->getMessage(),
            });
        }

        if (! self::canPost($member)) {
            throw new ConnectorException('El bot no puede publicar en ese canal. '.self::ADMIN_HELP);
        }

        $username = is_string($chat['username'] ?? null) && $chat['username'] !== '' ? $chat['username'] : null;
        $title = trim((string) ($chat['title'] ?? ''));

        return [new ConnectionCandidate(
            network: Network::Telegram,
            externalId: $chatId,
            name: $title !== '' ? $title : ($username !== null ? '@'.$username : $chatId),
            username: $username !== null ? '@'.$username : null,
            accessToken: $token,
            scopes: ['channel:post'],
            meta: array_filter([
                'bot_id' => $botId,
                'bot_username' => is_string($bot['username'] ?? null) ? $bot['username'] : null,
                'chat_type' => 'channel',
                'chat_username' => $username,
                'chat_title' => $title !== '' ? $title : null,
            ], fn ($v) => $v !== null),
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        return new RefreshResult((string) $account->access_token);
    }

    /** ¿Puede el bot publicar? El creador del canal siempre; un administrador, solo con `can_post_messages`. @param array<string, mixed> $member */
    private static function canPost(array $member): bool
    {
        return ($member['status'] ?? null) === 'creator'
            || (($member['status'] ?? null) === 'administrator' && ($member['can_post_messages'] ?? false) === true);
    }

    private const ADMIN_HELP = 'Abre el canal en Telegram > Administradores > Añadir administrador, elige a tu bot y activa «Publicar mensajes».';
}
