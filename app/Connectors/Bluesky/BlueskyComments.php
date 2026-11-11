<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Respuestas a publicaciones PROPIAS de Bluesky. Leer: `app.bsky.feed.getPostThread` en el AppView (lectura publica, sin permisos
 * extra); responder: registro `app.bsky.feed.post` con `reply{root,parent}` en el PDS de la cuenta. El id de un comentario es su URI `at://`.
 */
class BlueskyComments implements CommentSource
{
    private const COLLECTION = 'app.bsky.feed.post';

    public function __construct(private readonly BlueskyClient $client, private readonly BlueskySession $session) {}

    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return true; // la app password concede lectura y escritura de publicaciones
    }

    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
    {
        $this->assertUri($postExternalId);
        $json = $this->client->get($this->client->appview(), 'app.bsky.feed.getPostThread', ['uri' => $postExternalId, 'depth' => (int) config('social.inbox.bluesky_depth', 6), 'parentHeight' => 0]);

        $out = [];
        $walk = function (array $replies, ?string $parent) use (&$walk, &$out, $account): void {
            foreach ($replies as $node) {
                $post = $node['post'] ?? null;
                if (! is_array($post) || empty($post['uri']) || strlen((string) $post['uri']) > 100) {
                    continue;
                }
                try {
                    $at = ! empty($post['record']['createdAt']) ? CarbonImmutable::parse($post['record']['createdAt'])->utc() : null;
                } catch (Throwable) {
                    $at = null;
                }
                $handle = isset($post['author']['handle']) ? (string) $post['author']['handle'] : null;
                $out[] = [
                    'external_id' => (string) $post['uri'],
                    'parent_external_id' => $parent,
                    'author_name' => $handle !== null ? mb_substr((string) ($post['author']['displayName'] ?? '') !== '' ? $post['author']['displayName'].' (@'.$handle.')' : '@'.$handle, 0, 255) : null,
                    'text' => isset($post['record']['text']) ? (string) $post['record']['text'] : null,
                    'is_own' => ($post['author']['did'] ?? null) === ($account->meta['did'] ?? $account->external_id),
                    'hidden' => false,
                    'commented_at' => $at,
                ];
                $walk((array) ($node['replies'] ?? []), (string) $post['uri']);
            }
        };
        $walk((array) ($json['thread']['replies'] ?? []), null);

        usort($out, fn (array $x, array $y) => ($y['commented_at']?->getTimestamp() ?? 0) <=> ($x['commented_at']?->getTimestamp() ?? 0));

        return $out;
    }

    public function reply(SocialAccount $account, string $commentId, string $message): string
    {
        $this->assertUri($commentId);
        $found = (array) ($this->client->getPosts($this->client->appview(), [$commentId])['posts'] ?? []);
        $parent = $found[0] ?? null;
        if (! is_array($parent) || empty($parent['cid'])) {
            throw new PermanentPublishException('Bluesky no encuentra el comentario al que respondes.', 'no_encontrado');
        }
        $root = $parent['record']['reply']['root'] ?? ['uri' => $parent['uri'], 'cid' => $parent['cid']];
        $record = [
            '$type' => self::COLLECTION,
            'text' => $message,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'reply' => ['root' => ['uri' => $root['uri'], 'cid' => $root['cid']], 'parent' => ['uri' => $parent['uri'], 'cid' => $parent['cid']]],
        ];
        $did = (string) ($account->meta['did'] ?? $account->external_id);
        $created = $this->session->call($account, fn (string $token, string $pds) => $this->client->createRecord($pds, $token, $did, self::COLLECTION, $record));

        return (string) ($created['uri'] ?? '');
    }

    private function assertUri(string $uri): void
    {
        if (preg_match('#^at://[A-Za-z0-9:._%-]+/[A-Za-z0-9.]+/[A-Za-z0-9._~:-]+$#', $uri) !== 1) {
            throw new \InvalidArgumentException('URI de Bluesky no valida.');
        }
    }
}
