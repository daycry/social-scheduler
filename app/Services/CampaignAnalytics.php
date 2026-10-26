<?php

namespace App\Services;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Panel de una campaña (C-03, CA-11). Agrega el ÚLTIMO snapshot de cada destino publicado de los posts de la campaña
 * (la misma lectura que el dashboard: `AnalyticsService::withLatestSnapshot`). `null` = «sin datos»: una métrica que ningún
 * destino informa no se convierte en 0. El alcance sumado es «acumulado» (no de usuarios únicos) y las métricas de un post
 * se congelan pasados `social.analytics.recent_post_days` días (el job diario deja de leerlas).
 *
 * Memoria acotada (gap 3 de la revisión F3-4): los destinos se leen como filas planas (sin modelos ni textos), la serie diaria
 * se agrega en SQL por fecha, y la lista de posts trae solo los `MAX_POSTS` primeros (con su texto recortado en SQL) más el total.
 *
 * Serie diaria (gap 7): es la SUMA de los snapshots leídos cada día, sin arrastrar el último valor de los destinos que ese día no
 * se leyeron. Por eso su último punto puede no coincidir con el KPI (que suma el último snapshot de cada destino, también los
 * ya congelados): la UI lo rotula. Un día en que ningún destino informa una métrica es `null`, nunca 0.
 */
class CampaignAnalytics
{
    /** Máximo de posts que lista el panel. */
    public const MAX_POSTS = 100;

    /** Caracteres del texto que se leen de BD por post (se recortan a 140 al presentarlos). */
    private const TEXT_READ = 200;

    /**
     * @return array{kpis: array<string, int|float|null>, by_network: list<array<string, mixed>>, daily: list<array<string, mixed>>, posts: list<array<string, mixed>>, posts_total: int, posts_limit: int, posts_truncated: bool, frozen_days: int}
     */
    public function summary(User $user, Campaign $campaign): array
    {
        $postsQuery = fn () => Post::withoutUserScope()->where('user_id', $user->id)->where('campaign_id', $campaign->id);
        $total = $postsQuery()->count();
        $timezone = Brand::withoutUserScope()->whereKey($campaign->brand_id)->value('timezone') ?: 'UTC';

        $posts = $total === 0 ? [] : $postsQuery()->orderByDesc('id')->limit(self::MAX_POSTS)
            ->selectRaw('id, status, scheduled_at, SUBSTR(text, 1, '.self::TEXT_READ.') as text_head')->toBase()->get()->all();
        $targets = $total === 0 ? collect() : $this->targets($user, $postsQuery()->select('id'));
        $published = $targets->filter(fn ($t) => $t->status === PostStatus::Published->value);

        $rows = $published->map(fn ($t) => ['network' => $t->network, 'metrics' => $this->latest($t)])->values()->all();

        return [
            'kpis' => ['posts' => $total, 'targets' => $published->count()] + $this->aggregate($rows),
            'by_network' => $this->byNetwork($rows),
            'daily' => $total === 0 ? [] : $this->daily($user, $postsQuery()->select('id')),
            'posts' => $this->postList($posts, $targets, $timezone),
            'posts_total' => $total,
            'posts_limit' => self::MAX_POSTS,
            'posts_truncated' => $total > self::MAX_POSTS,
            'frozen_days' => max(1, (int) config('social.analytics.recent_post_days', 30)),
        ];
    }

    /**
     * Destinos de los posts con su último snapshot (`latest_metrics`), como filas planas: sin el UserScope, acotados al usuario
     * y sin cargar columnas pesadas (texto propio, opciones, errores).
     *
     * @return Collection<int, object>
     */
    private function targets(User $user, Builder $postIds)
    {
        return AnalyticsService::withLatestSnapshot(PostTarget::withoutUserScope()->where('post_targets.user_id', $user->id)->whereIn('post_targets.post_id', $postIds), $user->id)
            ->select('post_targets.id', 'post_targets.post_id', 'post_targets.network', 'post_targets.content_type', 'post_targets.status', 'post_targets.published_at', 'post_targets.external_url', 'pm.metrics as latest_metrics')
            ->orderBy('post_targets.id')->toBase()->get();
    }

    /** @return array<string, int|float|null> */
    private function latest(object $t): array
    {
        $raw = $t->latest_metrics;

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
     * Serie diaria agregada en SQL: por fecha, la suma de cada métrica en los snapshots de los destinos publicados de la
     * campaña. `null` si ningún destino la informa ese día. Sin hidratar modelos ni arrastrar valores.
     *
     * @return list<array<string, mixed>>
     */
    private function daily(User $user, Builder $postIds): array
    {
        $grammar = DB::connection((new PostMetricSnapshot)->getConnectionName())->getQueryGrammar();
        $sums = [];
        foreach (Metric::POST as $key) {
            // Un `null` JSON es "no disponible": NULLIF evita que MySQL lo lea como la cadena 'null' (que casteada seria 0).
            $sums[] = 'SUM(CAST(NULLIF('.$grammar->wrap('metrics->'.$key).", 'null') AS DECIMAL(20,4))) as ".$grammar->wrap($key);
        }

        $targetIds = PostTarget::withoutUserScope()->select('id')->where('user_id', $user->id)
            ->where('status', PostStatus::Published->value)->whereIn('post_id', $postIds);

        $rows = PostMetricSnapshot::withoutUserScope()->where('user_id', $user->id)->whereIn('post_target_id', $targetIds)
            ->selectRaw($grammar->wrap('date').', '.implode(', ', $sums))->groupBy('date')->orderBy('date')->toBase()->get();

        $num = fn ($v) => $v === null ? null : ((float) $v == (int) $v ? (int) $v : round((float) $v, 4));

        return $rows->map(function ($r) use ($num) {
            $metrics = [];
            foreach (Metric::POST as $key) {
                $metrics[$key] = $num($r->{$key});
            }

            return ['date' => substr((string) $r->date, 0, 10)] + $metrics
                + ['engagement_rate' => Calculator::engagementRate($metrics[Metric::INTERACTIONS], $metrics[Metric::VIEWS])];
        })->values()->all();
    }

    /**
     * @param  list<object>  $posts
     * @param  Collection<int, object>  $targets
     * @return list<array<string, mixed>>
     */
    private function postList(array $posts, $targets, string $timezone): array
    {
        $byPost = $targets->groupBy('post_id');

        return array_map(function ($p) use ($byPost, $timezone) {
            $status = PostStatus::from($p->status);
            $at = $p->scheduled_at ? CarbonImmutable::parse($p->scheduled_at, 'UTC') : null;

            return [
                'id' => (int) $p->id,
                'text' => $p->text_head !== null && $p->text_head !== '' ? mb_strimwidth($p->text_head, 0, 140, '...') : null,
                'status' => $status->value,
                'status_label' => $status->label(),
                'scheduled_at' => $at?->toIso8601String(),
                // Fecha en la zona de la marca (no en UTC), como el resto de la web.
                'scheduled_local' => $at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'timezone' => $timezone,
                'targets' => ($byPost->get($p->id) ?? collect())->map(function ($t) {
                    $metrics = $this->latest($t);
                    $status = PostStatus::from($t->status);

                    return [
                        'id' => (int) $t->id,
                        'network' => $t->network,
                        'content_type' => $t->content_type,
                        'status' => $status->value,
                        'status_label' => $status->label(),
                        'published_at' => $t->published_at ? CarbonImmutable::parse($t->published_at, 'UTC')->toIso8601String() : null,
                        'url' => $t->external_url,
                        'metrics' => $metrics,
                        'has_data' => ! Metric::isEmpty($metrics),
                        'engagement_rate' => Calculator::engagementRate($metrics[Metric::INTERACTIONS], $metrics[Metric::VIEWS]),
                    ];
                })->values()->all(),
            ];
        }, $posts);
    }
}
