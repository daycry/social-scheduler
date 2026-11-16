<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\ScopeMissingException;
use App\Connectors\Meta\Comments;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Comentarios de YouTube: el primer comentario de una publicacion (`commentThreads.insert`, idempotente con `findOwn`) y el inbox
 * de videos PROPIOS (T-20: lectura con `commentThreads.list` y respuesta con `comments.insert`). Todo exige el permiso
 * `youtube.force-ssl`; el cupo diario lo gestiona `YouTubeQuota` (insertar 50 ud, listar 1 ud).
 */
class YouTubeComments implements CommentSource
{
    /** Permiso que exige `commentThreads.insert`. Las cuentas conectadas antes de pedirlo no pueden comentar hasta reconectar. */
    public const SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

    /** Unidades de cuota de la API: `commentThreads.insert` y `comments.insert` cuestan 50 y `commentThreads.list` 1. */
    private const INSERT_COST = 50;

    private const LIST_COST = 1;

    public function __construct(private readonly YouTubeClient $client = new YouTubeClient, private readonly YouTubeInboxBudget $budget = new YouTubeInboxBudget) {}

    /** ¿El canal concedio el permiso de comentar? Sin el, solo se desactiva el primer comentario; publicar sigue igual. */
    public static function canComment(SocialAccount $account): bool
    {
        return in_array(self::SCOPE, (array) $account->scopes, true);
    }

    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return self::canComment($account);
    }

    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
    {
        $out = [];
        $token = null;

        for ($page = 0, $max = max(1, (int) config('social.inbox.max_pages', 5)); $page < $max; $page++) {
            if ($page > 0 && $deadline !== null && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                break;
            }
            // Presupuesto del inbox: sin unidades se devuelve lo leido (nunca se gasta el cupo de las subidas).
            if (! $this->budget->allows(self::LIST_COST)) {
                break;
            }
            $json = $this->threads($account, $postExternalId, $token);
            $this->budget->charge(self::LIST_COST);

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
        $this->budget->assertAllows(self::INSERT_COST);
        $id = $this->replyTo($account, $commentId, $message);
        $this->budget->charge(self::INSERT_COST);

        return $id;
    }

    /**
     * Primer comentario (`commentThreads.insert`, 50 ud, exige `youtube.force-ssl`). Sin el permiso no se llama a la API:
     * pide reconectar. Comentarios desactivados, video no encontrado o privado -> `CommentNotAllowedException`.
     */
    public function create(SocialAccount $account, string $videoId, string $text): string
    {
        if (! self::canComment($account)) {
            throw new ScopeMissingException('Reconecta el canal de YouTube para poder comentar (falta el permiso de comentarios).');
        }

        $this->client->quota()->assertAvailable(self::INSERT_COST);

        $response = $this->client->send($account, fn (PendingRequest $http) => $http->post(YouTubeClient::API.'/commentThreads?part=snippet', [
            'snippet' => ['videoId' => $videoId, 'topLevelComment' => ['snippet' => ['textOriginal' => $text]]],
        ]));

        if (! $response->successful()) {
            if (in_array((string) ($response->json('error.errors.0.reason') ?? ''), ['commentsDisabled', 'videoNotFound'], true)) {
                throw self::commentsClosed();
            }

            throw $this->client->exceptionFor($response);
        }

        $this->client->quota()->charge(self::INSERT_COST);

        return (string) ($response->json('snippet.topLevelComment.id') ?? $response->json('id') ?? '');
    }

    /**
     * Comentario propio (autor = el canal) con ese texto, o null. Busqueda ACOTADA (idempotencia del primer comentario).
     *
     * `commentThreads.list` solo ordena por `time` (mas recientes primero) o `relevance`: NO hay orden cronologico ascendente, asi
     * que un video con mas de 100 comentarios nuevos dejaria el propio fuera de la primera pagina. La mejor opcion que permite la
     * API es `searchTerms` (hilos que contienen el texto): se busca con el inicio del comentario hasta `social.first_comment.find_pages`
     * paginas y un presupuesto de tiempo; si no aparece y la busqueda llego a su fin, se mira ademas la primera pagina sin filtro
     * (por si el indice de busqueda va por detras). Si se agota el tope con mas paginas por leer, lanza
     * `CommentSearchInconclusiveException` (el job no crea otro a ciegas en un reintento).
     *
     * @throws CommentSearchInconclusiveException
     */
    public function findOwn(SocialAccount $account, string $videoId, string $text): ?string
    {
        if (! self::canComment($account)) {
            return null;
        }

        $wanted = Comments::canon($text);
        $deadline = microtime(true) + (float) config('social.first_comment.find_budget_seconds', 80);
        $base = ['part' => 'snippet', 'videoId' => $videoId, 'maxResults' => 100, 'order' => 'time', 'textFormat' => 'plainText'];

        // 1) Hilos que contienen el texto.
        $search = $base + ['searchTerms' => mb_substr($wanted, 0, 100)];
        $more = false;
        $token = null;

        for ($page = 0, $max = max(1, (int) config('social.first_comment.find_pages', 3)); $page < $max; $page++) {
            if ($page > 0 && microtime(true) + (float) config('social.http_timeout', 15) > $deadline) {
                $more = true;
                break;
            }

            $json = $this->threadsPage($account, $token === null ? $search : $search + ['pageToken' => $token]);

            if (($found = $this->ownIn($json, $account, $wanted)) !== null) {
                return $found;
            }

            $token = $json['nextPageToken'] ?? null;
            $more = is_string($token) && $token !== '';

            if (! $more) {
                break;
            }
        }

        if ($more) {
            throw new CommentSearchInconclusiveException('No se pudo comprobar si el comentario ya existe: el video tiene demasiados comentarios con ese texto. No se crea otro a ciegas: comprueba en la red si ya esta; si lo esta, pulsa «Ya esta publicado», y si no, «Publicar igualmente» (puede duplicarse si en realidad ya existe).', 'busqueda_inconclusa');
        }

        // 2) Red de seguridad: los 100 hilos mas recientes sin filtro.
        return $this->ownIn($this->threadsPage($account, $base), $account, $wanted);
    }

    /**
     * Hilos de comentarios de un video propio (inbox, T-20): `commentThreads.list` con las primeras respuestas (1 ud de cupo).
     *
     * @return array<string, mixed>
     */
    private function threads(SocialAccount $account, string $videoId, ?string $pageToken = null): array
    {
        $query = ['part' => 'snippet,replies', 'videoId' => $videoId, 'maxResults' => 100, 'order' => 'time', 'textFormat' => 'plainText'];

        return $this->threadsPage($account, $pageToken === null ? $query : $query + ['pageToken' => $pageToken]);
    }

    /** Responde a un comentario (`comments.insert`, 50 ud, exige `youtube.force-ssl`) y devuelve el id de la respuesta. */
    private function replyTo(SocialAccount $account, string $parentId, string $text): string
    {
        if (! self::canComment($account)) {
            throw new ScopeMissingException('Reconecta el canal de YouTube para poder responder (falta el permiso de comentarios).');
        }
        $this->client->quota()->assertAvailable(self::INSERT_COST);

        $response = $this->client->send($account, fn (PendingRequest $http) => $http->post(YouTubeClient::API.'/comments?part=snippet', ['snippet' => ['parentId' => $parentId, 'textOriginal' => $text]]));
        if (! $response->successful()) {
            throw $this->client->exceptionFor($response);
        }
        $this->client->quota()->charge(self::INSERT_COST);

        return (string) ($response->json('id') ?? '');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function threadsPage(SocialAccount $account, array $query): array
    {
        $this->client->quota()->assertAvailable(self::LIST_COST);
        $json = $this->listThreads($account, $query);
        $this->client->quota()->charge(self::LIST_COST);

        return $json;
    }

    /** @param  array<string, mixed>  $json */
    private function ownIn(array $json, SocialAccount $account, string $wanted): ?string
    {
        foreach ((array) ($json['items'] ?? []) as $thread) {
            $top = $thread['snippet']['topLevelComment'] ?? [];
            $snippet = $top['snippet'] ?? [];

            if (($snippet['authorChannelId']['value'] ?? null) === $account->external_id
                && Comments::canon((string) ($snippet['textOriginal'] ?? $snippet['textDisplay'] ?? '')) === $wanted) {
                return (string) ($top['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * `commentThreads.list`. Comentarios desactivados o video inexistente/privado (403 `commentsDisabled`, 404 `videoNotFound`)
     * son `CommentNotAllowedException` (-> `no_admitido`), igual que al insertar: no es un fallo de credenciales y reconectar el
     * canal no lo arregla.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function listThreads(SocialAccount $account, array $query): array
    {
        $response = $this->client->send($account, fn (PendingRequest $http) => $http->get(YouTubeClient::API.'/commentThreads', $query), false);

        if (! $response->successful()) {
            if (in_array((string) ($response->json('error.errors.0.reason') ?? ''), ['commentsDisabled', 'videoNotFound'], true)) {
                throw self::commentsClosed();
            }

            throw $this->client->exceptionFor($response);
        }

        return $response->json() ?? [];
    }

    private static function commentsClosed(): CommentNotAllowedException
    {
        return new CommentNotAllowedException('YouTube no admite comentarios en este video (privado, con comentarios desactivados o «hecho para ninos»).', 'comentarios_cerrados');
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
