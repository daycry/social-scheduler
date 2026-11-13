<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Meta\Comments;
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

        return $this->createReply($account, $message, $root, ['uri' => $parent['uri'], 'cid' => $parent['cid']]);
    }

    /**
     * Primer comentario: respuesta a una publicacion PROPIA. El CID del padre se lee del PDS de la cuenta (`getRecord`, consistente
     * y sin esperar al indice del AppView, que puede ir por detras justo tras publicar) y el registro lleva `reply{root,parent}` con
     * el post como raiz y padre. Reutiliza `createReply` de `reply()`.
     */
    public function create(SocialAccount $account, string $postUri, string $message): string
    {
        $this->assertUri($postUri);
        [$repo, $collection, $rkey] = explode('/', substr($postUri, 5), 3);
        $record = $this->client->getRecord(BlueskySession::pds($account), $repo, $collection, $rkey);
        if (! is_array($record) || empty($record['cid'])) {
            throw new PermanentPublishException('Bluesky no encuentra la publicacion a la que comentar.', 'no_encontrado');
        }
        $ref = ['uri' => $postUri, 'cid' => (string) $record['cid']];

        return $this->createReply($account, $message, $ref, $ref);
    }

    /**
     * Id (URI) de una respuesta PROPIA directa a la publicacion con ese texto (comparacion normalizada), o null. Lee el repositorio de
     * la cuenta (`com.atproto.repo.listRecords`, lectura publica, sin indice de por medio) y filtra por `reply.parent` = el post; con el
     * tope de `social.first_comment.find_pages` paginas. Si se agota con mas registros por leer, la busqueda es inconclusa.
     *
     * @throws CommentSearchInconclusiveException
     */
    public function findOwn(SocialAccount $account, string $postUri, string $text): ?string
    {
        $this->assertUri($postUri);
        $wanted = Comments::canon($text);
        $did = (string) ($account->meta['did'] ?? $account->external_id);
        $pds = BlueskySession::pds($account);
        $deadline = microtime(true) + (float) config('social.first_comment.find_budget_seconds', 80);
        $cursor = null;
        $more = false;

        for ($page = 0, $max = max(1, (int) config('social.first_comment.find_pages', 3)); $page < $max; $page++) {
            if ($page > 0 && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                $more = true;
                break;
            }
            $json = $this->client->get($pds, 'com.atproto.repo.listRecords', ['repo' => $did, 'collection' => self::COLLECTION, 'limit' => max(1, min(100, (int) config('social.first_comment.find_per_page', 100)))]
                + ($cursor !== null ? ['cursor' => $cursor] : []));

            foreach ((array) ($json['records'] ?? []) as $r) {
                $value = (array) ($r['value'] ?? []);
                if (($value['reply']['parent']['uri'] ?? null) === $postUri && Comments::canon((string) ($value['text'] ?? '')) === $wanted && ! empty($r['uri'])) {
                    return (string) $r['uri'];
                }
            }

            $cursor = isset($json['cursor']) && is_string($json['cursor']) && $json['cursor'] !== '' && ! empty($json['records']) ? $json['cursor'] : null;
            $more = $cursor !== null;
            if (! $more) {
                return null; // fin del repositorio: concluyente
            }
        }

        throw new CommentSearchInconclusiveException('No se pudo comprobar si el comentario ya existe: hay demasiadas publicaciones recientes para revisarlas todas. No se crea otro a ciegas: comprueba en la red si ya esta; si lo esta, pulsa «Ya esta publicado», y si no, «Publicar igualmente» (puede duplicarse si en realidad ya existe).', 'busqueda_inconclusa');
    }

    /**
     * Crea el registro de respuesta en el PDS de la cuenta (con enlaces, etiquetas y menciones como en una publicacion normal).
     *
     * @param  array{uri: string, cid: string}  $root
     * @param  array{uri: string, cid: string}  $parent
     */
    private function createReply(SocialAccount $account, string $message, array $root, array $parent): string
    {
        $facets = Facets::build($message, function (string $handle): ?string {
            try {
                return $this->client->resolveHandle($handle);
            } catch (BlueskyException) {
                return null;
            }
        });
        $record = array_filter([
            '$type' => self::COLLECTION,
            'text' => $message,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'facets' => $facets ?: null,
            'reply' => ['root' => ['uri' => $root['uri'], 'cid' => $root['cid']], 'parent' => ['uri' => $parent['uri'], 'cid' => $parent['cid']]],
        ], fn ($v) => $v !== null);
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
