<?php

namespace App\Connectors\Bluesky;

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Metricas de Bluesky, leidas del AppView PUBLICO (`social.bluesky.appview`, sin sesion: asi una sesion caida no rompe las metricas;
 * ⚠️ el host publico se confirma en el smoke, T-30).
 *
 *  - Cuenta (`app.bsky.actor.getProfile`): `followersCount` -> FOLLOWERS y `postsCount` -> POST_COUNT. Son STOCKS: no existe el valor de
 *    un dia pasado, asi que solo se lee el ultimo dia cerrado. Bluesky no da flujos diarios (`EXPECTED_FLOWS['bluesky'] = []`).
 *  - Post (`app.bsky.feed.getPosts`, 25 URIs como maximo): `likeCount` -> LIKES, `replyCount` -> COMMENTS, `repostCount + quoteCount`
 *    -> SHARES y la suma -> INTERACTIONS. VIEWS, REACH y SAVED son `null` («no disponible», nunca 0): Bluesky no los ofrece.
 */
class BlueskyInsights implements InsightsReader
{
    /** URIs por consulta de `getPosts`. */
    public const BATCH = 25;

    public function __construct(private readonly BlueskyClient $client) {}

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();

        if (CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC')->lt(Days::closed($account->network))) {
            return $out; // stock: no existe el valor de un dia pasado
        }

        $profile = $this->call(fn () => $this->client->get($this->appview(), 'app.bsky.actor.getProfile', ['actor' => (string) ($account->meta['did'] ?? $account->external_id)]));
        $out[Metric::FOLLOWERS] = $this->num($profile['followersCount'] ?? null);
        $out[Metric::POST_COUNT] = $this->num($profile['postsCount'] ?? null);

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $target->external_id !== null && str_starts_with((string) $target->external_id, 'at://');
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        return $this->postsInsights([$externalId])[$externalId] ?? Metric::blankPost();
    }

    /**
     * Metricas de varios posts en lotes de 25. Un post que ya no existe (borrado) queda con todo `null`.
     *
     * @param  list<string>  $uris
     * @return array<string, array<string, int|float|null>> uri => metricas
     */
    public function postsInsights(array $uris): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($uris)), self::BATCH) as $chunk) {
            $data = $this->call(fn () => $this->client->getPosts($this->appview(), $chunk));
            $byUri = [];
            foreach ((array) ($data['posts'] ?? []) as $post) {
                if (is_array($post) && is_string($post['uri'] ?? null)) {
                    $byUri[$post['uri']] = $post;
                }
            }
            foreach ($chunk as $uri) {
                $out[$uri] = isset($byUri[$uri]) ? $this->fromPost($byUri[$uri]) : Metric::blankPost();
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, int|float|null>
     */
    private function fromPost(array $post): array
    {
        $out = Metric::blankPost();
        $likes = $this->num($post['likeCount'] ?? null);
        $replies = $this->num($post['replyCount'] ?? null);
        $reposts = $this->num($post['repostCount'] ?? null);
        $quotes = $this->num($post['quoteCount'] ?? null);

        $out[Metric::LIKES] = $likes;
        $out[Metric::COMMENTS] = $replies;
        $shares = array_filter([$reposts, $quotes], fn ($v) => $v !== null);
        $out[Metric::SHARES] = $shares === [] ? null : array_sum($shares);
        $parts = array_filter([$out[Metric::LIKES], $out[Metric::COMMENTS], $out[Metric::SHARES]], fn ($v) => $v !== null);
        $out[Metric::INTERACTIONS] = $parts === [] ? null : array_sum($parts);

        return $out;
    }

    private function appview(): string
    {
        return (string) config('social.bluesky.appview');
    }

    private function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private function call(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (BlueskyException $e) {
            if ($e->isTransient()) {
                throw new TransientPublishException('Bluesky no esta disponible temporalmente para leer metricas.', 'bluesky_no_disponible');
            }

            throw new PermanentPublishException('Bluesky rechazo la consulta de metricas ('.($e->errorCode ?: $e->status).').', $e->errorCode ?: null);
        }
    }
}
