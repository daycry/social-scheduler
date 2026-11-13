<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\CompetitorSource;
use App\Connectors\Meta\CompetitorNotFound;
use App\Connectors\Network;
use App\Models\SocialAccount;
use App\Support\SafeUrl;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Competidores de Bluesky (T-25) con la API PUBLICA del AppView, sin cuenta ni token propios:
 *  - perfil: `app.bsky.actor.getProfile` (`did`, `handle`, `displayName`, `avatar`, `followersCount`, `postsCount`);
 *  - publicaciones: `app.bsky.feed.getAuthorFeed` con `filter=posts_no_replies` (cada `post` trae `likeCount` y `replyCount`).
 * Los reposts del autor se descartan (no son suyos) y un perfil inexistente, suspendido o desactivado es `CompetitorNotFound`.
 * Los limites de ritmo (429) llegan como transitorios y los reintenta `GuardsInsightsErrors`.
 */
class BlueskyCompetitors implements CompetitorSource
{
    /** Handle de dominio (sintaxis oficial de atproto): etiquetas de 1 a 63 caracteres, al menos dos, la ultima empieza por letra. */
    public const HANDLE_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    public function __construct(private readonly BlueskyClient $client) {}

    public function network(): Network
    {
        return Network::Bluesky;
    }

    public function copy(): array
    {
        return [
            'username' => 'Usuario de Bluesky (por ejemplo ana.bsky.social)', 'placeholder' => 'ana.bsky.social', 'followers' => 'Seguidores', 'media' => 'Publicaciones',
            'note' => 'Solo se leen datos públicos: seguidores y número de publicaciones, una vez al día. No hace falta conectar una cuenta de Bluesky.',
        ];
    }

    public function needsAccount(): bool
    {
        return false;
    }

    public function normalize(string $input): string
    {
        $handle = trim($input);
        // Acepta la URL del perfil (https://bsky.app/profile/ana.bsky.social).
        if (preg_match('~^https?://(?:www\.)?bsky\.app/profile/([^/?#\s]+)~i', $handle, $m) === 1) {
            $handle = $m[1];
        }
        $handle = strtolower(ltrim($handle, '@'));

        if (strlen($handle) > 253 || preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            throw new InvalidArgumentException('Escribe un usuario de Bluesky valido (por ejemplo ana.bsky.social).');
        }

        return $handle;
    }

    public function lookup(?SocialAccount $account, string $username): array
    {
        $p = $this->get('app.bsky.actor.getProfile', ['actor' => $username]);

        if (empty($p['did']) || empty($p['handle'])) {
            throw new CompetitorNotFound('No encontramos esa cuenta de Bluesky.');
        }

        return [
            'username' => strtolower((string) $p['handle']),
            'name' => isset($p['displayName']) && $p['displayName'] !== '' ? mb_substr((string) $p['displayName'], 0, 255) : null,
            'profile_picture_url' => isset($p['avatar']) && SafeUrl::isSafe((string) $p['avatar']) ? mb_substr((string) $p['avatar'], 0, 1000) : null,
            'followers_count' => isset($p['followersCount']) ? (int) $p['followersCount'] : null,
            'media_count' => isset($p['postsCount']) ? (int) $p['postsCount'] : null,
            'external_id' => mb_substr((string) $p['did'], 0, 100),
        ];
    }

    public function media(?SocialAccount $account, string $username, ?string $externalId, int $limit): array
    {
        $json = $this->get('app.bsky.feed.getAuthorFeed', ['actor' => $externalId ?: $username, 'limit' => max(1, min(100, $limit)), 'filter' => 'posts_no_replies']);

        $out = [];
        foreach ((array) ($json['feed'] ?? []) as $item) {
            $post = $item['post'] ?? null;
            // Un repost aparece en el feed del autor pero no es publicacion suya.
            if (! is_array($post) || isset($item['reason']) || empty($post['uri']) || preg_match('#^at://[^/]+/app\.bsky\.feed\.post/([A-Za-z0-9._~:-]{1,40})$#', (string) $post['uri'], $m) !== 1) {
                continue;
            }
            $handle = strtolower((string) ($post['author']['handle'] ?? $username));
            $out[] = [
                'external_id' => $m[1],
                'caption' => isset($post['record']['text']) && $post['record']['text'] !== '' ? mb_substr((string) $post['record']['text'], 0, 300) : null,
                'media_type' => $this->type((array) ($post['record']['embed'] ?? $post['embed'] ?? [])),
                'permalink' => 'https://bsky.app/profile/'.$handle.'/post/'.$m[1],
                'likes_count' => isset($post['likeCount']) ? (int) $post['likeCount'] : null,
                'comments_count' => isset($post['replyCount']) ? (int) $post['replyCount'] : null,
                'posted_at' => $this->date($post['record']['createdAt'] ?? $post['indexedAt'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws CompetitorNotFound
     */
    private function get(string $nsid, array $query): array
    {
        try {
            return $this->client->get($this->client->appview(), $nsid, $query);
        } catch (BlueskyException $e) {
            // Perfil inexistente (`InvalidRequest`, «Profile not found»), suspendido o desactivado: no se reintenta.
            if (in_array($e->errorCode, ['AccountTakedown', 'AccountSuspended', 'AccountDeactivated'], true)
                || ($e->errorCode === 'InvalidRequest' && $e->status === 400)) {
                throw new CompetitorNotFound('No encontramos esa cuenta de Bluesky o esta suspendida o desactivada.', previous: $e);
            }

            throw BlueskyErrors::translate($e);
        }
    }

    /** @param array<string, mixed> $embed */
    private function type(array $embed): string
    {
        $kind = (string) ($embed['$type'] ?? '');

        return match (true) {
            str_contains($kind, 'images') => 'IMAGE',
            str_contains($kind, 'video') => 'VIDEO',
            str_contains($kind, 'external') => 'LINK',
            str_contains($kind, 'record') => 'QUOTE',
            default => 'TEXT',
        };
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse((string) $value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
