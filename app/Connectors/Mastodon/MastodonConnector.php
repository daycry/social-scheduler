<?php

namespace App\Connectors\Mastodon;

use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Models\SocialAccount;

/**
 * Conector de Mastodon (T-87): se conecta con la URL de la instancia y un token personal de usuario (no OAuth). El token se guarda cifrado en
 * `access_token` (lo recifra `app:reencrypt`); no caduca, asi que no hay nada que renovar. `external_id` = `host:id` (el id de Mastodon solo es unico
 * dentro de su instancia), `name` y `username` = handle completo (`acct@host`) y en `meta` van la instancia, el perfil, el avatar y los limites.
 */
class MastodonConnector implements CredentialConnector
{
    public const SCOPES = ['write:statuses', 'write:media', 'read:accounts'];

    public function __construct(private readonly MastodonClient $client) {}

    public function provider(): string
    {
        return 'mastodon';
    }

    public function networks(): array
    {
        return [Network::Mastodon];
    }

    /** El token personal no caduca: nunca hay renovacion (`token_expires_at` queda en null). */
    public function refreshMarginHours(): int
    {
        return 0;
    }

    public function credentialIntro(): string
    {
        return 'Conecta Mastodon con un token de acceso personal con los permisos write:statuses, write:media y read:accounts. Se guarda cifrado y puedes revocarlo desde Mastodon cuando quieras.';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'instance_url', 'label' => 'Instancia de Mastodon', 'type' => 'text', 'help' => 'La dirección de tu servidor, por ejemplo mastodon.social. Debe usar https.'],
            ['name' => 'access_token', 'label' => 'Token de acceso', 'type' => 'password', 'help' => 'Créalo en Preferencias > Desarrollo > Nueva aplicación, con los permisos write:statuses, write:media y read:accounts, y copia «Tu token de acceso».'],
        ];
    }

    public function connectWithCredentials(array $input): array
    {
        $instance = trim((string) ($input['instance_url'] ?? ''));
        $token = trim((string) ($input['access_token'] ?? ''));

        if ($instance === '' || $token === '') {
            throw new ConnectorException('Escribe la instancia de Mastodon y el token de acceso.');
        }

        try {
            $base = $this->client->normalizeInstance($instance);
            $profile = $this->client->verifyCredentials($base, $token);
        } catch (MastodonException $e) {
            // Nunca se propaga el cuerpo de la instancia ni el token: los mensajes de los fallos de red y de la direccion ya son los de esta app.
            throw new ConnectorException(match (true) {
                in_array($e->status, [401, 403], true) => 'Mastodon rechazó el token. Comprueba que lo has creado en esa instancia y con los permisos write:statuses, write:media y read:accounts.',
                $e->status > 0 => 'Mastodon no pudo validar la cuenta (error '.$e->status.').',
                default => $e->getMessage(),
            });
        }

        $id = (string) ($profile['id'] ?? '');
        $acct = trim((string) ($profile['acct'] ?? ''));
        if ($id === '' || $acct === '') {
            throw new ConnectorException('Mastodon no devolvió los datos de la cuenta.');
        }

        $host = (string) parse_url($base, PHP_URL_HOST);
        $handle = str_contains($acct, '@') ? $acct : $acct.'@'.$host;

        return [new ConnectionCandidate(
            network: Network::Mastodon,
            externalId: $host.':'.$id,
            name: $handle,
            username: $handle,
            accessToken: $token,
            scopes: self::SCOPES,
            meta: array_filter([
                'instance' => $base,
                'account_id' => $id,
                'acct' => $acct,
                'avatar' => MastodonClient::safeUrl($profile['avatar'] ?? null),
                'profile_url' => MastodonClient::safeUrl($profile['url'] ?? null, $host),
                'limits' => $this->limits($base),
            ], fn ($v) => $v !== null),
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        return new RefreshResult((string) $account->access_token);
    }

    /** Limites de la instancia; si no se pueden leer ahora se usan los por defecto y el publicador los lee al publicar. @return array<string, mixed> */
    private function limits(string $base): array
    {
        try {
            return MastodonLimits::fromInstance($this->client->instance($base));
        } catch (MastodonException) {
            return [];
        }
    }
}
