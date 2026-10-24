<?php

namespace App\Connectors\Meta;

use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
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
     * Con `$deadline` (microtime) comprueba el presupuesto TAMBIEN entre paginas de comentarios y de respuestas: si no cabe otra
     * llamada en el peor caso (`social.http_timeout`) corta y devuelve lo leido hasta ahora (que el llamador guarda; el resto llega
     * en la siguiente sincronizacion). Siempre se hace al menos la primera pagina.
     *
     * @return list<array{external_id: string, parent_external_id: ?string, author_name: ?string, text: ?string, is_own: bool, hidden: bool, commented_at: ?CarbonImmutable}>
     */
    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
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
            if ($page > 0 && ! $this->fits($deadline)) {
                break;
            }
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
                $rows = array_merge($rows, $this->moreReplies($account, $top['external_id'], $replies, $token, $deadline));
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

    /** ¿Cabe otra llamada de red (peor caso `social.http_timeout`) antes de `$deadline`? Sin deadline, siempre. */
    private function fits(?float $deadline): bool
    {
        return $deadline === null || microtime(true) + (float) config('social.http_timeout', 15) <= $deadline;
    }

    /**
     * Paginas siguientes de las respuestas de un comentario (la primera ya vino anidada).
     *
     * @param  array<string, mixed>  $replies  el bloque `replies`/`comments` anidado, con su `paging`
     * @return list<array<string, mixed>>
     */
    private function moreReplies(SocialAccount $account, string $commentId, array $replies, string $token, ?float $deadline = null): array
    {
        $isIg = $account->network === Network::Instagram;
        $after = $replies['paging']['cursors']['after'] ?? null;
        $more = [];

        for ($page = 1, $max = max(1, (int) config('social.inbox.max_reply_pages', 3)); $page < $max + 1; $page++) {
            if (empty($replies['paging']['next']) || $after === null || $after === '' || ! $this->fits($deadline)) {
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

    /**
     * Primer comentario: crea un comentario de nivel superior en una publicacion propia y devuelve su id. «Comentarios
     * cerrados» se traduce a `CommentNotAllowedException`; el resto de errores de Graph conservan su clase (transitorio,
     * reconexion, permanente).
     */
    public function create(SocialAccount $account, string $postExternalId, string $message): string
    {
        $this->assertId($postExternalId);

        if (! self::hasScopes($account, true)) {
            throw new PermanentPublishException('Falta el permiso para comentar en '.$account->network->label().'. Reconecta la cuenta y acepta todos los permisos.', 'permisos');
        }

        try {
            $json = $this->graph->post($postExternalId.'/comments', ['message' => $message], (string) $account->access_token);
        } catch (PermanentPublishException $e) {
            if (preg_match('/comment.{0,40}(disabled|turned off|closed|not allowed)|(disabled|turned off|closed).{0,40}comment/i', $e->getMessage()) === 1) {
                throw new CommentNotAllowedException('La publicacion tiene los comentarios cerrados.', 'comentarios_cerrados', $e);
            }

            throw $e;
        }

        return (string) ($json['id'] ?? '');
    }

    /**
     * Id de un comentario PROPIO de nivel superior cuyo texto (normalizado) coincide, o null. Es la comprobacion de idempotencia
     * del primer comentario y NO reutiliza `fetch()` del inbox: es una busqueda ACOTADA, solo de comentarios de nivel superior
     * (sin campos anidados ni paginas de respuestas), con pocas paginas (`social.first_comment.find_pages`) y un `$deadline`
     * (microtime) por debajo del timeout del job; por defecto, `find_budget_seconds` desde ahora.
     *
     * Orden: Facebook admite `order=chronological` (el primer comentario, publicado justo tras el post, sale en la primera
     * pagina). El borde de comentarios de Instagram no documenta ningun parametro `order` (solo `limit`/`after`/`before`): se
     * recorre en el orden de la API con el tope de paginas.
     *
     * Si la lista llega a su fin sin el comentario, devuelve null (concluyente). Si se corta por tope de paginas o de tiempo con
     * mas paginas por leer, lanza `CommentSearchInconclusiveException`: no se sabe si existe y el llamador decide el camino seguro.
     *
     * @throws CommentSearchInconclusiveException
     */
    public function findOwn(SocialAccount $account, string $postExternalId, string $text, ?float $deadline = null): ?string
    {
        $this->assertId($postExternalId);
        $wanted = self::canon($text);
        $deadline ??= microtime(true) + (float) config('social.first_comment.find_budget_seconds', 80);
        $isIg = $account->network === Network::Instagram;
        $query = $isIg
            ? ['fields' => 'id,text,username,timestamp', 'limit' => $this->findLimit()]
            : ['fields' => 'id,message,from{id,name},created_time', 'limit' => $this->findLimit(), 'filter' => 'toplevel', 'order' => 'chronological'];

        $after = null;
        $more = false;

        for ($page = 0, $max = max(1, (int) config('social.first_comment.find_pages', 3)); $page < $max; $page++) {
            if ($page > 0 && ! $this->fits($deadline)) {
                $more = true;
                break;
            }

            $json = $this->graph->get($postExternalId.'/comments', $query + ($after !== null ? ['after' => $after] : []), (string) $account->access_token, true);

            foreach ((array) ($json['data'] ?? []) as $c) {
                $comment = $this->normalize($account, $c, null);

                if ($comment && $comment['is_own'] && self::canon((string) $comment['text']) === $wanted) {
                    return $comment['external_id'];
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
            $more = ! empty($json['paging']['next']) && $after !== null && $after !== '';

            if (! $more) {
                return null; // fin de la lista: concluyente
            }
        }

        throw new CommentSearchInconclusiveException('No se pudo comprobar si el comentario ya existe: la publicacion tiene demasiados comentarios para revisarlos todos. No se crea otro a ciegas; pulsa Reintentar.', 'busqueda_inconclusa');
    }

    private function findLimit(): int
    {
        return max(1, min(100, (int) config('social.first_comment.find_per_page', 100)));
    }

    /** Texto comparable: sin espacios sobrantes ni diferencias de saltos de linea. */
    public static function canon(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
