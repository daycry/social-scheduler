<?php

namespace App\Connectors\Meta;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use InvalidArgumentException;

/**
 * Instagram Business Discovery (PA-08): datos PUBLICOS de otra cuenta profesional (Business/Creator) consultados
 * desde una cuenta propia: `GET /{ig-user-id}?fields=business_discovery.username(X){...}` con el token en Bearer.
 * Solo sirve para cuentas profesionales; una personal, privada o inexistente responde un error de "usuario no valido"
 * que aqui se traduce a `CompetitorNotFound`. Es una LECTURA (`$read = true`): un rechazo no es un fallo de publicacion.
 */
class BusinessDiscovery
{
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._]{1,30}$/';

    private const FIELDS = 'username,name,profile_picture_url,followers_count,media_count';

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    /**
     * @return array{username: string, name: ?string, profile_picture_url: ?string, followers_count: ?int, media_count: ?int}
     *
     * @throws CompetitorNotFound cuenta inexistente o no profesional
     */
    public function lookup(SocialAccount $account, string $username): array
    {
        $username = ltrim(trim($username), '@');

        // El nombre va dentro de la expresion `fields`: solo caracteres de usuario de Instagram (sin llaves ni parentesis).
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException('Nombre de usuario de Instagram no valido.');
        }

        try {
            $json = $this->graph->get(
                $account->external_id,
                ['fields' => "business_discovery.username($username){".self::FIELDS.'}'],
                (string) $account->access_token,
                true,
            );
        } catch (QuotaExceededException|TransientPublishException $e) {
            throw $e;
        } catch (PermanentPublishException $e) {
            if ($e->errorCode === 'permisos') {
                throw $e;
            }

            throw new CompetitorNotFound('No encontramos esa cuenta o no es una cuenta profesional (Business/Creator) de Instagram.', previous: $e);
        }

        $data = $json['business_discovery'] ?? null;

        if (! is_array($data) || ! isset($data['username'])) {
            throw new CompetitorNotFound('No encontramos esa cuenta o no es una cuenta profesional (Business/Creator) de Instagram.');
        }

        return [
            'username' => (string) $data['username'],
            'name' => isset($data['name']) ? (string) $data['name'] : null,
            'profile_picture_url' => isset($data['profile_picture_url']) ? (string) $data['profile_picture_url'] : null,
            'followers_count' => isset($data['followers_count']) ? (int) $data['followers_count'] : null,
            'media_count' => isset($data['media_count']) ? (int) $data['media_count'] : null,
        ];
    }
}
