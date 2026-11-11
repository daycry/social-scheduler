<?php

namespace App\Connectors\Threads;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Respuestas a publicaciones PROPIAS de Threads. Leer: `GET /{media-id}/conversation` (permiso `threads_read_replies`); responder:
 * contenedor con `reply_to_id` + `threads_publish` (permiso `threads_manage_replies`). Ocultar respuestas queda fuera de esta fase.
 */
class ThreadsComments implements CommentSource
{
    public const READ_SCOPES = ['threads_basic', 'threads_read_replies'];

    public const WRITE_SCOPES = ['threads_basic', 'threads_content_publish', 'threads_manage_replies'];

    private const FIELDS = 'id,text,username,timestamp,hide_status,replied_to{id}';

    public function __construct(private readonly ThreadsClient $client = new ThreadsClient) {}

    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return array_diff($write ? self::WRITE_SCOPES : self::READ_SCOPES, (array) $account->scopes) === [];
    }

    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
    {
        $this->assertId($postExternalId);
        $out = [];
        $after = null;

        for ($page = 0, $max = max(1, (int) config('social.inbox.max_pages', 5)); $page < $max; $page++) {
            if ($page > 0 && $deadline !== null && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                break;
            }
            $json = $this->client->get($postExternalId.'/conversation', ['fields' => self::FIELDS, 'reverse' => 'true', 'limit' => (int) config('social.inbox.comments_per_post', 50)]
                + ($after !== null ? ['after' => $after] : []), (string) $account->access_token, true);

            foreach ((array) ($json['data'] ?? []) as $c) {
                $row = $this->normalize($account, $c, $postExternalId);
                if ($row) {
                    $out[] = $row;
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
            if (empty($json['paging']['next']) || $after === null || $after === '') {
                break;
            }
        }

        usort($out, fn (array $x, array $y) => ($y['commented_at']?->getTimestamp() ?? 0) <=> ($x['commented_at']?->getTimestamp() ?? 0));

        return $out;
    }

    public function reply(SocialAccount $account, string $commentId, string $message): string
    {
        $this->assertId($commentId);
        $token = (string) $account->access_token;
        $container = $this->client->post($account->external_id.'/threads', ['media_type' => 'TEXT', 'text' => $message, 'reply_to_id' => $commentId], $token);
        $creation = (string) ($container['id'] ?? '');
        if ($creation === '') {
            throw new PermanentPublishException('Threads no devolvio el contenedor de la respuesta.', 'sin_contenedor');
        }
        $published = $this->client->post($account->external_id.'/threads_publish', ['creation_id' => $creation], $token);

        return (string) ($published['id'] ?? '');
    }

    /** @param array<string, mixed> $c */
    private function normalize(SocialAccount $account, array $c, string $postId): ?array
    {
        if (empty($c['id'])) {
            return null;
        }
        $parent = isset($c['replied_to']['id']) ? (string) $c['replied_to']['id'] : null;
        $author = isset($c['username']) ? (string) $c['username'] : null;

        try {
            $at = ! empty($c['timestamp']) ? CarbonImmutable::parse($c['timestamp'])->utc() : null;
        } catch (Throwable) {
            $at = null;
        }

        return [
            'external_id' => (string) $c['id'],
            'parent_external_id' => $parent === null || $parent === $postId ? null : $parent,
            'author_name' => $author !== null ? mb_substr($author, 0, 255) : null,
            'text' => isset($c['text']) ? (string) $c['text'] : null,
            'is_own' => $author !== null && $account->username !== null && strtolower($author) === strtolower((string) $account->username),
            'hidden' => in_array((string) ($c['hide_status'] ?? ''), ['HIDDEN', 'COVERED'], true),
            'commented_at' => $at,
        ];
    }

    /** El id va en la RUTA de la API: solo caracteres de id. */
    private function assertId(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $id) !== 1) {
            throw new InvalidArgumentException('Identificador de comentario no valido.');
        }
    }
}
