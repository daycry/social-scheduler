<?php

namespace App\Connectors\Contracts;

use App\Connectors\Meta\CompetitorNotFound;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Fuente de DATOS PUBLICOS de cuentas de terceros (competidores), una por red: Instagram (Business Discovery), Bluesky (AppView publico)
 * y YouTube (Data API, con cuota). Es una LECTURA: un rechazo no es un fallo de publicacion. Los errores usan las excepciones de la
 * cola (`ReconnectRequiredException`, `QuotaExceededException`, `TransientPublishException`...) para que `GuardsInsightsErrors` los
 * reintente igual que el resto de la analitica; una cuenta inexistente o no admitida es `CompetitorNotFound`.
 *
 * @phpstan-type Profile array{username: string, name: ?string, profile_picture_url: ?string, followers_count: ?int, media_count: ?int, external_id: ?string}
 * @phpstan-type Post array{external_id: string, caption: ?string, media_type: ?string, permalink: ?string, likes_count: ?int, comments_count: ?int, posted_at: ?CarbonImmutable}
 */
interface CompetitorSource
{
    public function network(): Network;

    /** ¿Hace falta una cuenta PROPIA conectada de esa red (su token) para consultar? Bluesky no; Instagram y YouTube si. */
    public function needsAccount(): bool;

    /**
     * Normaliza lo que escribe el usuario (con o sin @, URL del perfil...) al usuario/handle canonico.
     *
     * @throws \InvalidArgumentException con un mensaje legible si no es un usuario valido de la red
     */
    public function normalize(string $input): string;

    /**
     * Perfil publico: nombre, avatar, seguidores y numero de publicaciones (null si la red no lo da).
     *
     * @return Profile
     *
     * @throws CompetitorNotFound
     */
    public function lookup(?SocialAccount $account, string $username): array;

    /**
     * Ultimas publicaciones publicas. `likes_count`/`comments_count` son null cuando la red no los da (nunca 0).
     *
     * @return list<Post>
     *
     * @throws CompetitorNotFound
     */
    public function media(?SocialAccount $account, string $username, ?string $externalId, int $limit): array;
}
