<?php

namespace App\Services;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Panel de una campaña (C-03, CA-11). Agrega el ÚLTIMO snapshot de cada destino publicado de los posts de la campaña
 * (la misma lectura que el dashboard: `AnalyticsService::withLatestSnapshot`). `null` = «sin datos»: una métrica que ningún
 * destino informa no se convierte en 0. El alcance sumado es «acumulado» (no de usuarios únicos) y las métricas de un post
 * se congelan pasados `social.analytics.recent_post_days` días (el job diario deja de leerlas).
 */
class CampaignAnalytics
{
    /** Máximo de posts que lista el panel. */
    public const MAX_POSTS = 100;

    /**
     * @return array{kpis: array<string, int|float|null>, by_network: list<array<string, mixed>>, daily: list<array<string, mixed>>, posts: list<array<string, mixed>>, frozen_days: int}
     */
    public function summary(User $user, Campaign $campaign): array
    {
        $posts = Post::withoutUserScope()->where('user_id', $user->id)->where('campaign_id', $campaign->id)->orderByDesc('id')->get();
        $targets = $posts->isEmpty() ? collect() : $this->targets($user, $posts->pluck('id')->all());
        $published = $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Published);

        $rows = $published->map(fn (PostTarget $t) => ['network' => $t->network->value, 'metrics' => $this->latest($t)])->values()->all();

        return [
            'kpis' => ['posts' => $posts->count(), 'targets' => $published->count()] + $this->aggregate($rows),
            'by_network' => $this->byNetwork($rows),
            'daily' => $this->daily($user, $published->pluck('id')->all()),
            'posts' => $this->postList($posts, $targets),
            'frozen_days' => max(1, (int) config('social.analytics.recent_post_days', 30)),
        ];
    }

    /** Destinos de los posts con su último snapshot (`latest_metrics`), sin el UserScope y acotados al usuario. */
    private function targets(User $user, array $postIds)
    {
        return AnalyticsService::withLatestSnapshot(PostTarget::withoutUserScope()->where('post_targets.user_id', $user->id)->whereIn('post_targets.post_id', $postIds), $user->id)
            ->select('post_targets.*', 'pm.metrics as latest_metrics')
            ->orderBy('post_targets.id')->get();
    }

    /** @return array<string, int|float|null> */
    private function latest(PostTarget $t): array
    {
        $raw = $t->getAttribute('latest_metrics');

        return Metric::clean(is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw, Metric::POST);
    }

    /**
     * Suma por métrica sobre los destinos que la informan; `null` si ninguno lo hace. La tasa de engagement usa solo los
     * destinos con visualizaciones e interacciones, para no mezclar un total con otro incompleto.
     *
     * @param  list<array{network: string, metrics: array<string, int|float|null>}>  $rows
     * @return array<string, int|float|null>
     */
    private function aggregate(array $rows): array
    {
        $out = [];
        foreach (Metric::POST as $key) {
            $values = array_filter(array_column(array_column($rows, 'metrics'), $key), fn ($v) => $v !== null);
            $out[$key] = $values === [] ? null : array_sum($values);
        }

        $both = array_filter($rows, fn ($r) => $r['metrics'][Metric::VIEWS] !== null && $r['metrics'][Metric::INTERACTIONS] !== null);
        $out['engagement_rate'] = Calculator::engagementRate(
            $both === [] ? null : array_sum(array_map(fn ($r) => $r['metrics'][Metric::INTERACTIONS], $both)),
            $both === [] ? null : array_sum(array_map(fn ($r) => $r['metrics'][Metric::VIEWS], $both)),
        );

        return $out;
    }

    /**
     * @param  list<array{network: string, metrics: array<string, int|float|null>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function byNetwork(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['network']][] = $r;
        }

        $out = [];
        foreach ($groups as $network => $group) {
            $out[] = ['network' => $network, 'targets' => count($group)] + $this->aggregate($group);
        }

        return $out;
    }

    /**
     * Serie diaria acumulada: en cada día con algún snapshot, la suma del último valor conocido de cada destino (un destino
     * sin snapshot ese día arrastra el anterior). `null` hasta que algún destino informa la métrica.
     *
     * @param  list<int>  $targetIds
     * @return list<array<string, mixed>>
     */
    private function daily(User $user, array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $snaps = PostMetricSnapshot::withoutUserScope()->where('user_id', $user->id)->whereIn('post_target_id', $targetIds)
            ->orderBy('date')->get(['post_target_id', 'date', 'metrics']);
        if ($snaps->isEmpty()) {
            return [];
        }

        $dates = $snaps->map(fn ($s) => substr((string) $s->date, 0, 10))->unique()->sort()->values()->all();
        $byTarget = [];
        foreach ($snaps as $s) {
            $byTarget[$s->post_target_id][substr((string) $s->date, 0, 10)] = Metric::clean((array) $s->metrics, Metric::POST);
        }

        $carried = array_fill_keys(array_keys($byTarget), Metric::blankPost());
        $out = [];
        foreach ($dates as $date) {
            foreach ($byTarget as $id => $perDate) {
                if (isset($perDate[$date])) {
                    foreach (Metric::POST as $key) {
                        // Un día sin snapshot, o con la métrica en `null`, arrastra el último valor conocido.
                        $carried[$id][$key] = $perDate[$date][$key] ?? $carried[$id][$key];
                    }
                }
            }
            $out[] = ['date' => $date] + $this->aggregate(array_map(fn ($m) => ['network' => '', 'metrics' => $m], array_values($carried)));
        }

        return $out;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, PostTarget>  $targets
     * @return list<array<string, mixed>>
     */
    private function postList($posts, $targets): array
    {
        $byPost = $targets->groupBy('post_id');

        return $posts->take(self::MAX_POSTS)->map(fn (Post $p) => [
            'id' => $p->id,
            'text' => $p->text ? mb_strimwidth($p->text, 0, 140, '...') : null,
            'status' => $p->status->value,
            'scheduled_at' => $p->scheduled_at?->toIso8601String(),
            'targets' => ($byPost->get($p->id) ?? collect())->map(function (PostTarget $t) {
                $metrics = $this->latest($t);

                return [
                    'id' => $t->id,
                    'network' => $t->network->value,
                    'content_type' => $t->content_type->value,
                    'status' => $t->status->value,
                    'published_at' => $t->published_at?->toIso8601String(),
                    'url' => $t->external_url,
                    'metrics' => $metrics,
                    'has_data' => ! Metric::isEmpty($metrics),
                    'engagement_rate' => Calculator::engagementRate($metrics[Metric::INTERACTIONS], $metrics[Metric::VIEWS]),
                ];
            })->values()->all(),
        ])->values()->all();
    }
}
