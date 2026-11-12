<?php

namespace App\Connectors\Threads;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ScopeMissingException;
use App\Connectors\Meta\Comments;
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
            $json = $this->guarded($account, ['threads_read_replies'], fn () => $this->client->get($postExternalId.'/conversation', ['fields' => self::FIELDS, 'reverse' => 'true', 'limit' => (int) config('social.inbox.comments_per_post', 50)]
                + ($after !== null ? ['after' => $after] : []), (string) $account->access_token, true));

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
        $container = $this->guarded($account, ['threads_manage_replies'], fn () => $this->client->post($account->external_id.'/threads', ['media_type' => 'TEXT', 'text' => $message, 'reply_to_id' => $commentId], $token));
        $creation = (string) ($container['id'] ?? '');
        if ($creation === '') {
            throw new PermanentPublishException('Threads no devolvio el contenedor de la respuesta.', 'sin_contenedor');
        }
        $published = $this->guarded($account, ['threads_manage_replies'], fn () => $this->client->post($account->external_id.'/threads_publish', ['creation_id' => $creation], $token));

        return (string) ($published['id'] ?? '');
    }

    /**
     * Primer comentario: una respuesta a la publicacion propia (`reply_to_id` = id de la publicacion). Reutiliza `reply()`; sin el
     * permiso de respuestas pide reconectar (las cuentas conectadas antes de pedirlo no lo tienen) en vez de llamar a ciegas.
     */
    public function create(SocialAccount $account, string $postExternalId, string $message): string
    {
        if (! $this->hasScopes($account, true)) {
            throw new ScopeMissingException('Threads necesita el permiso para responder (threads_manage_replies): reconecta la cuenta aceptando todos los permisos.');
        }

        $id = $this->reply($account, $postExternalId, $message);

        return $id !== '' ? $id : throw new PermanentPublishException('Threads no devolvio el id del comentario.', 'sin_id');
    }

    /**
     * Id de una respuesta PROPIA de nivel superior con ese texto (comparacion normalizada), o null. Busqueda acotada y cronologica
     * (`GET /{id}/replies`, `reverse=false`): el primer comentario, publicado justo tras el post, sale en las primeras paginas. Sin
     * permiso de lectura no se puede comprobar: es inconclusa (nunca null). Si se agotan las paginas con mas por leer, no se sabe: inconclusa.
     *
     * @throws CommentSearchInconclusiveException
     */
    public function findOwn(SocialAccount $account, string $postExternalId, string $text): ?string
    {
        $this->assertId($postExternalId);
        // Sin permiso de lectura no se puede comprobar si el comentario ya existe: NO se da por inexistente (se duplicaria en un reintento);
        // la busqueda es inconclusa y el job aplica la proteccion de `first_comment_sent_at`.
        if (! $this->hasScopes($account)) {
            throw new CommentSearchInconclusiveException('No se puede comprobar si el comentario ya existe porque la cuenta de Threads no tiene el permiso para leer respuestas (threads_read_replies). No se crea otro a ciegas: comprueba en la red si ya esta; si lo esta, pulsa «Ya esta publicado», y si no, reconecta la cuenta o pulsa «Publicar igualmente» (puede duplicarse si en realidad ya existe).', 'busqueda_inconclusa');
        }

        $wanted = Comments::canon($text);
        $deadline = microtime(true) + (float) config('social.first_comment.find_budget_seconds', 80);
        $limit = max(1, min(100, (int) config('social.first_comment.find_per_page', 100)));
        $after = null;
        $more = false;

        for ($page = 0, $max = max(1, (int) config('social.first_comment.find_pages', 3)); $page < $max; $page++) {
            if ($page > 0 && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                $more = true;
                break;
            }
            $json = $this->guarded($account, ['threads_read_replies'], fn () => $this->client->get($postExternalId.'/replies', ['fields' => self::FIELDS, 'reverse' => 'false', 'limit' => $limit]
                + ($after !== null ? ['after' => $after] : []), (string) $account->access_token, true));

            foreach ((array) ($json['data'] ?? []) as $c) {
                $row = $this->normalize($account, $c, $postExternalId);
                if ($row && $row['is_own'] && Comments::canon((string) $row['text']) === $wanted) {
                    return $row['external_id'];
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
            $more = ! empty($json['paging']['next']) && $after !== null && $after !== '';
            if (! $more) {
                return null; // fin de la lista: concluyente
            }
        }

        throw new CommentSearchInconclusiveException('No se pudo comprobar si el comentario ya existe: la publicacion tiene demasiadas respuestas para revisarlas todas. No se crea otro a ciegas: comprueba en la red si ya esta; si lo esta, pulsa «Ya esta publicado», y si no, «Publicar igualmente» (puede duplicarse si en realidad ya existe).', 'busqueda_inconclusa');
    }

    /**
     * Si Threads rechaza la llamada por PERMISOS, el usuario los quito (o nunca los concedio): se retiran de los scopes guardados
     * (la UI deja de ofrecer leer/responder) y se pide reconectar, en vez de un error permanente sin salida.
     *
     * @template T
     *
     * @param  list<string>  $scopes  permisos que se retiran
     * @param  \Closure(): T  $call
     * @return T
     */
    private function guarded(SocialAccount $account, array $scopes, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (PermanentPublishException $e) {
            if ($e->errorCode !== 'permisos') {
                throw $e;
            }

            $account->forceFill(['scopes' => array_values(array_diff((array) $account->scopes, $scopes))])->save();

            // Son permisos OPCIONALES (la cuenta sigue publicando): falla solo esta lectura o respuesta, sin marcar la cuenta.
            throw new ScopeMissingException('Threads ya no tiene los permisos de respuestas ('.implode(', ', $scopes).'): reconecta la cuenta aceptándolos.', 0, $e);
        }
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
