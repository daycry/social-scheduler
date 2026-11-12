<?php

namespace App\Connectors\Meta;

use App\Connectors\Contracts\CompetitorSource;
use App\Connectors\Network;
use App\Models\SocialAccount;
use InvalidArgumentException;

/** Fuente de competidores de Instagram: Business Discovery (`BusinessDiscovery`) con el token de una cuenta propia (PA-08, T-20). */
class InstagramCompetitors implements CompetitorSource
{
    public function __construct(private readonly BusinessDiscovery $discovery) {}

    public function network(): Network
    {
        return Network::Instagram;
    }

    public function needsAccount(): bool
    {
        return true;
    }

    public function normalize(string $input): string
    {
        $username = strtolower(ltrim(trim($input), '@'));
        if (preg_match(BusinessDiscovery::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException('Escribe un usuario de Instagram valido (letras, numeros, puntos y guiones bajos).');
        }

        return $username;
    }

    public function lookup(?SocialAccount $account, string $username): array
    {
        return $this->discovery->lookup($this->account($account), $username) + ['external_id' => null];
    }

    public function media(?SocialAccount $account, string $username, ?string $externalId, int $limit): array
    {
        return $this->discovery->media($this->account($account), $username, $limit);
    }

    private function account(?SocialAccount $account): SocialAccount
    {
        return $account ?? throw new \LogicException('Instagram necesita una cuenta propia conectada para consultar competidores.');
    }
}
