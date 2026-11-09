<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\CommentSource;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Comentarios de videos PROPIOS de YouTube (inbox, T-20): lectura con `commentThreads.list` y respuesta con `comments.insert`.
 * Ambos exigen el permiso `youtube.force-ssl`; el cupo diario lo gestiona `YouTubeQuota` (lista 1 ud, respuesta 50 ud).
 */
class YouTubeComments implements CommentSource
{
    public function __construct(private readonly YouTubePublisher $youtube = new YouTubePublisher) {}

    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return YouTubePublisher::canComment($account);
    }

    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
    {
        $out = [];
        $token = null;

        for ($page = 0, $max = max(1, (int) config('social.inbox.max_pages', 5)); $page < $max; $page++) {
            if ($page > 0 && $deadline !== null && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                break;
            }
            $json = $this->youtube->commentThreads($account, $postExternalId, $token);

            foreach ((array) ($json['items'] ?? []) as $thread) {
                $top = $this->normalize($account, (array) ($thread['snippet']['topLevelComment'] ?? []), null);
                if (! $top) {
                    continue;
                }
                $out[] = $top;
                foreach ((array) ($thread['replies']['comments'] ?? []) as $reply) {
                    $row = $this->normalize($account, (array) $reply, $top['external_id']);
                    if ($row) {
                        $out[] = $row;
                    }
                }
            }

            $token = $json['nextPageToken'] ?? null;
            if (! is_string($token) || $token === '') {
                break;
            }
        }

        usort($out, fn (array $x, array $y) => ($y['commented_at']?->getTimestamp() ?? 0) <=> ($x['commented_at']?->getTimestamp() ?? 0));

        return $out;
    }

    public function reply(SocialAccount $account, string $commentId, string $message): string
    {
        return $this->youtube->replyToComment($account, $commentId, $message);
    }

    /** @param array<string, mixed> $c */
    private function normalize(SocialAccount $account, array $c, ?string $parent): ?array
    {
        $s = (array) ($c['snippet'] ?? []);
        if (empty($c['id'])) {
            return null;
        }
        try {
            $at = ! empty($s['publishedAt']) ? CarbonImmutable::parse($s['publishedAt'])->utc() : null;
        } catch (Throwable) {
            $at = null;
        }

        return [
            'external_id' => (string) $c['id'],
            'parent_external_id' => $parent,
            'author_name' => isset($s['authorDisplayName']) ? mb_substr((string) $s['authorDisplayName'], 0, 255) : null,
            'text' => isset($s['textOriginal']) || isset($s['textDisplay']) ? (string) ($s['textOriginal'] ?? $s['textDisplay']) : null,
            'is_own' => ($s['authorChannelId']['value'] ?? null) === $account->external_id,
            'hidden' => false,
            'commented_at' => $at,
        ];
    }
}
