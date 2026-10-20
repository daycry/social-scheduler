<?php

namespace App\Connectors\Meta;

use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Comentarios de publicaciones PROPIAS de Instagram y Facebook en Graph API (PA-10). Todo con el token de la cuenta en
 * `Authorization: Bearer` (nunca en la query). Permisos (activar en los casos de uso de la app de Meta y reconectar):
 *  - Instagram: `instagram_manage_comments` (leer, responder y ocultar).
 *  - Facebook:  `pages_read_user_content` (leer comentarios de usuarios) y `pages_manage_engagement` (responder y ocultar).
 * No toca mensajes directos.
 */
class Comments
{
    /** Permisos necesarios por red para LEER y para MODERAR (responder/ocultar). */
    public const READ_SCOPES = ['instagram' => ['instagram_manage_comments'], 'facebook' => ['pages_read_user_content']];

    public const WRITE_SCOPES = ['instagram' => ['instagram_manage_comments'], 'facebook' => ['pages_manage_engagement']];

    private const IG_FIELDS = 'id,text,username,timestamp,hidden,replies{id,text,username,timestamp,hidden}';

    private const FB_FIELDS = 'id,message,from{id,name},created_time,is_hidden,comments.limit(25){id,message,from{id,name},created_time,is_hidden}';

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    /** ¿La cuenta tiene concedidos los permisos? `$write` = responder/ocultar en vez de solo leer. */
    public static function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        $needed = ($write ? self::WRITE_SCOPES : self::READ_SCOPES)[$account->network->value] ?? null;

        return $needed !== null && array_diff($needed, (array) $account->scopes) === [];
    }

    /**
     * Comentarios (y sus respuestas) de una publicacion propia, normalizados, MAS RECIENTES PRIMERO. Sigue la paginacion de Graph
     * (`paging.cursors.after` mientras haya `paging.next`) hasta `social.inbox.max_pages` paginas de comentarios y
     * `max_reply_pages` por hilo de respuestas. No usa `since`: una respuesta nueva a un comentario antiguo no cambia la fecha
     * del padre y se perderia (y Instagram no lo admite); la sincronizacion es idempotente por `upsert`.
     *
     * @return list<array{external_id: string, parent_external_id: ?string, author_name: ?string, text: ?string, is_own: bool, hidden: bool, commented_at: ?CarbonImmutable}>
     */
    public function fetch(SocialAccount $account, string $postExternalId): array
    {
        $this->assertId($postExternalId);
        $limit = (int) config('social.inbox.comments_per_post', 50);
        $isIg = $account->network === Network::Instagram;
        $token = (string) $account->access_token;
        $query = $isIg
            ? ['fields' => self::IG_FIELDS, 'limit' => $limit]
            : ['fields' => self::FB_FIELDS, 'limit' => $limit, 'filter' => 'toplevel', 'order' => 'reverse_chronological'];

        $out = [];
        $after = null;
        for ($page = 0, $max = max(1, (int) config('social.inbox.max_pages', 5)); $page < $max; $page++) {
            $json = $this->graph->get($postExternalId.'/comments', $query + ($after !== null ? ['after' => $after] : []), $token, true);

            foreach ((array) ($json['data'] ?? []) as $c) {
                $top = $this->normalize($account, $c, null);
                if (! $top) {
                    continue;
                }
                $out[] = $top;
                $replies = (array) ($c[$isIg ? 'replies' : 'comments'] ?? []);
                $rows = (array) ($replies['data'] ?? []);
                // El hilo trae sus propias paginas: se sigue hasta el tope.
                $rows = array_merge($rows, $this->moreReplies($account, $top['external_id'], $replies, $token));
                foreach ($rows as $r) {
                    $reply = $this->normalize($account, $r, $top['external_id']);
                    if ($reply) {
                        $out[] = $reply;
                    }
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
            if (empty($json['paging']['next']) || $after === null || $after === '') {
                break;
            }
        }

        // Orden explicito: mas recientes primero (los comentarios sin fecha, al final).
        usort($out, fn (array $x, array $y) => ($y['commented_at']?->getTimestamp() ?? 0) <=> ($x['commented_at']?->getTimestamp() ?? 0));

        return $out;
    }

    /**
     * Paginas siguientes de las respuestas de un comentario (la primera ya vino anidada).
     *
     * @param  array<string, mixed>  $replies  el bloque `replies`/`comments` anidado, con su `paging`
     * @return list<array<string, mixed>>
     */
    private function moreReplies(SocialAccount $account, string $commentId, array $replies, string $token): array
    {
        $isIg = $account->network === Network::Instagram;
        $after = $replies['paging']['cursors']['after'] ?? null;
        $more = [];

        for ($page = 1, $max = max(1, (int) config('social.inbox.max_reply_pages', 3)); $page < $max + 1; $page++) {
            if (empty($replies['paging']['next']) || $after === null || $after === '') {
                break;
            }
            $this->assertId($commentId);
            $replies = $this->graph->get($commentId.($isIg ? '/replies' : '/comments'), [
                'fields' => $isIg ? 'id,text,username,timestamp,hidden' : 'id,message,from{id,name},created_time,is_hidden',
                'limit' => 25, 'after' => $after,
            ], $token, true);
            $more = array_merge($more, (array) ($replies['data'] ?? []));
            $after = $replies['paging']['cursors']['after'] ?? null;
        }

        return $more;
    }

    /** Responde a un comentario con la cuenta; devuelve el id de la respuesta creada. */
    public function reply(SocialAccount $account, string $commentId, string $message): string
    {
        $this->assertId($commentId);
        $isIg = $account->network === Network::Instagram;
        $json = $this->graph->post($commentId.($isIg ? '/replies' : '/comments'), ['message' => $message], (string) $account->access_token);

        return (string) ($json['id'] ?? '');
    }

    /** Oculta (o vuelve a mostrar) un comentario. */
    public function setHidden(SocialAccount $account, string $commentId, bool $hidden): void
    {
        $this->assertId($commentId);
        $key = $account->network === Network::Instagram ? 'hide' : 'is_hidden';
        $this->graph->post($commentId, [$key => $hidden ? 'true' : 'false'], (string) $account->access_token);
    }

    /** @param array<string, mixed> $c */
    private function normalize(SocialAccount $account, array $c, ?string $parent): ?array
    {
        if (empty($c['id'])) {
            return null;
        }
        $isIg = $account->network === Network::Instagram;
        $author = $isIg ? ($c['username'] ?? null) : ($c['from']['name'] ?? null);
        $own = $isIg
            ? ($author !== null && $account->username !== null && strtolower((string) $author) === strtolower((string) $account->username))
            : (($c['from']['id'] ?? null) !== null && (string) $c['from']['id'] === (string) $account->external_id);
        $when = $c[$isIg ? 'timestamp' : 'created_time'] ?? null;

        try {
            $at = $when ? CarbonImmutable::parse($when)->utc() : null;
        } catch (Throwable) {
            $at = null;
        }

        return [
            'external_id' => (string) $c['id'],
            'parent_external_id' => $parent,
            'author_name' => $author !== null ? mb_substr((string) $author, 0, 255) : null,
            'text' => isset($c[$isIg ? 'text' : 'message']) ? (string) $c[$isIg ? 'text' : 'message'] : null,
            'is_own' => $own,
            'hidden' => (bool) ($c[$isIg ? 'hidden' : 'is_hidden'] ?? false),
            'commented_at' => $at,
        ];
    }

    /** El id va en la RUTA de Graph: solo caracteres de id de Meta (evita inyectar segmentos). */
    private function assertId(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $id) !== 1) {
            throw new InvalidArgumentException('Identificador de comentario no valido.');
        }
    }
}
