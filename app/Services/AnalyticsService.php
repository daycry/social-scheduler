<?php

namespace App\Services;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Capa de lectura de la analitica (T-35): convierte los snapshots en lo que muestran los dashboards sin acoplarse a
 * la version de cada API. Solo ve datos del usuario indicado (consultas explicitas por `user_id`).
 *
 * Reglas (ver Analytics\Calculator): `null` = "no disponible" y nunca se convierte en 0; flujos se suman, stocks
 * (seguidores) toman el ultimo valor conocido; no se inventan equivalencias entre redes: el total de una tarjeta
 * suma solo las redes que tienen dato y el desglose indica cuales no lo dan.
 */
class AnalyticsService
{
    /** Tarjetas del Summary: [clave de metrica => etiqueta]. `views` sustituye a las impresiones retiradas por Meta. */
    public const CARDS = [
        Metric::FOLLOWERS => 'Seguidores',
        Metric::VIEWS => 'Visualizaciones',
        Metric::REACH => 'Alcance',
        Metric::INTERACTIONS => 'Interacciones',
    ];

    public const LABELS = [
        Metric::FOLLOWERS => 'Seguidores',
        Metric::FOLLOWERS_GAINED => 'Seguidores ganados',
        Metric::FOLLOWERS_LOST => 'Seguidores perdidos',
        Metric::VIEWS => 'Visualizaciones',
        Metric::REACH => 'Alcance',
        Metric::PROFILE_VIEWS => 'Visitas al perfil',
        Metric::ACCOUNTS_ENGAGED => 'Cuentas que interactuaron',
        Metric::INTERACTIONS => 'Interacciones',
        Metric::LIKES => 'Me gusta',
        Metric::COMMENTS => 'Comentarios',
        Metric::SHARES => 'Compartidos',
        Metric::SAVED => 'Guardados',
        Metric::WATCH_MINUTES => 'Minutos reproducidos',
        Metric::VIDEO_COUNT => 'Videos publicados',
    ];

    /** Metricas que cada red PUEDE ofrecer (las demas no se muestran en su pagina). Presentes pero sin dato = "no disponible". */
    public const SUPPORTED = [
        'instagram' => [Metric::FOLLOWERS, Metric::FOLLOWERS_GAINED, Metric::VIEWS, Metric::REACH, Metric::PROFILE_VIEWS, Metric::ACCOUNTS_ENGAGED, Metric::INTERACTIONS],
        'facebook' => [Metric::FOLLOWERS, Metric::VIEWS, Metric::INTERACTIONS],
        'youtube' => [Metric::FOLLOWERS, Metric::FOLLOWERS_GAINED, Metric::FOLLOWERS_LOST, Metric::VIEWS, Metric::WATCH_MINUTES, Metric::INTERACTIONS, Metric::LIKES, Metric::COMMENTS, Metric::SHARES, Metric::VIDEO_COUNT],
        'tiktok' => [Metric::FOLLOWERS, Metric::VIDEO_COUNT],
    ];

    /** Publicaciones que lista la pagina de una red por defecto (las de mas interacciones); ver `social.analytics.dashboard_posts`. */
    public const MAX_POSTS = 50;

    /** Redes con al menos una cuenta conectada (no desconectada) en la marca. */
    public function connectedNetworks(User $user, Brand $brand): array
    {
        $this->assertOwns($user, $brand);

        return SocialAccount::withoutUserScope()
            ->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->get()
            ->groupBy(fn (SocialAccount $a) => $a->network->value)
            ->map(fn ($accounts, $network) => [
                'value' => $network,
                'label' => Network::from($network)->label(),
                'accounts' => $accounts->count(),
            ])
            ->sortBy('label')->values()->all();
    }

    /**
     * Resumen de la marca: una tarjeta por metrica con total, desglose por red y linea temporal.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, Brand $brand, Period $period, ?Period $compare = null): array
    {
        $networks = $this->connectedNetworks($user, $brand);
        $current = $this->networkSeries($user, $brand, $period);
        $previous = $compare ? $this->networkSeries($user, $brand, $compare) : [];
        $dates = $period->dates();

        $cards = [];

        foreach (self::CARDS as $metric => $label) {
            $byNetwork = [];
            $timeline = array_fill_keys($dates, ['total' => null, 'values' => []]);
            $total = null;
            $prevTotal = null;

            foreach ($networks as $n) {
                $net = $n['value'];
                $accounts = $current[$net][$metric] ?? [];
                $series = $this->fill(Calculator::combine($accounts, $metric, $dates), $dates);
                $value = Calculator::totalAcross($accounts, $metric);
                $prev = $compare ? Calculator::totalAcross($previous[$net][$metric] ?? [], $metric) : null;

                $byNetwork[] = [
                    'network' => $net, 'label' => $n['label'], 'value' => $value, 'previous' => $prev,
                    'delta_pct' => Calculator::delta($value, $prev)['pct'], 'available' => $value !== null,
                ];

                $total = $value === null ? $total : ($total ?? 0) + $value;
                $prevTotal = $prev === null ? $prevTotal : ($prevTotal ?? 0) + $prev;

                foreach ($series as $date => $v) {
                    $timeline[$date]['values'][$net] = $v;
                    $timeline[$date]['total'] = $v === null ? $timeline[$date]['total'] : ($timeline[$date]['total'] ?? 0) + $v;
                }
            }

            $delta = Calculator::delta($total, $prevTotal);
            $cards[] = [
                'key' => $metric, 'label' => $label, 'kind' => in_array($metric, Metric::STOCKS, true) ? 'stock' : 'flow',
                'total' => $total, 'previous' => $compare ? $prevTotal : null, 'delta_abs' => $delta['abs'], 'delta_pct' => $delta['pct'],
                'available' => $total !== null, 'by_network' => $byNetwork,
                'series' => collect($timeline)->map(fn ($row, $date) => ['date' => $date, 'total' => $row['total'], 'values' => $row['values']])->values()->all(),
            ];
        }

        return [
            'period' => $period->toArray(),
            'comparison' => $compare?->toArray(),
            'networks' => $networks,
            'cards' => $cards,
        ];
    }

    /**
     * Pagina de una red: crecimiento, visibilidad, interacciones, KPIs derivados y publicaciones con sus metricas.
     *
     * @return array<string, mixed>
     */
    public function network(User $user, Brand $brand, Network $network, Period $period, ?Period $compare = null): array
    {
        $this->assertOwns($user, $brand);

        $accounts = SocialAccount::withoutUserScope()
            ->where('user_id', $user->id)->where('brand_id', $brand->id)->where('network', $network->value)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('name')->get();

        $dates = $period->dates();
        // Solo se carga la red pedida (no las cuatro).
        $current = $this->networkSeries($user, $brand, $period, $network)[$network->value] ?? [];
        $previous = $compare ? ($this->networkSeries($user, $brand, $compare, $network)[$network->value] ?? []) : [];

        $metrics = [];
        $combined = [];

        foreach (self::SUPPORTED[$network->value] as $metric) {
            $combined[$metric] = $this->fill(Calculator::combine($current[$metric] ?? [], $metric, $dates), $dates);
            $total = Calculator::totalAcross($current[$metric] ?? [], $metric);
            $prev = $compare ? Calculator::totalAcross($previous[$metric] ?? [], $metric) : null;

            $metrics[] = [
                'key' => $metric, 'label' => self::LABELS[$metric], 'kind' => in_array($metric, Metric::STOCKS, true) ? 'stock' : 'flow',
                'total' => $total, 'previous' => $prev, 'delta_pct' => Calculator::delta($total, $prev)['pct'],
                'available' => $total !== null,
                'series' => collect($combined[$metric])->map(fn ($v, $d) => ['date' => $d, 'value' => $v])->values()->all(),
            ];
        }

        $stats = $this->postStats($user, $brand, $accounts, $period);

        return [
            'network' => ['value' => $network->value, 'label' => $network->label()],
            'accounts' => $accounts->map(fn (SocialAccount $a) => ['id' => $a->id, 'name' => $a->name, 'username' => $a->username, 'status' => $a->status])->all(),
            'period' => $period->toArray(),
            'comparison' => $compare?->toArray(),
            // Crecimiento = suma de la variacion de CADA cuenta entre su primer y ultimo dato del periodo (sin saltos por altas/bajas).
            'growth' => Calculator::growthAcross($current[Metric::FOLLOWERS] ?? []) + [
                'previous' => $compare ? Calculator::growthAcross($previous[Metric::FOLLOWERS] ?? []) : null,
            ],
            'metrics' => $metrics,
            'kpis' => $this->kpis($combined, $stats['kpis']),
            'posts' => $stats['posts'],
            'posts_total' => $stats['total'],
            'posts_limit' => $stats['limit'],
            'posts_truncated' => $stats['total'] > count($stats['posts']),
        ];
    }

    /**
     * KPIs derivados: por dia (sobre la serie de la cuenta) y por publicacion (agregados en SQL sobre TODAS las del periodo).
     *
     * @param  array<string, array<string, int|float|null>>  $series  serie combinada por metrica
     * @param  array<string, mixed>  $perPost
     * @return array<string, mixed>
     */
    private function kpis(array $series, array $perPost): array
    {
        $interactions = $series[Metric::INTERACTIONS] ?? [];
        $views = $series[Metric::VIEWS] ?? [];

        return [
            'per_day' => [
                'interactions' => Calculator::perDay($interactions),
                'views' => Calculator::perDay($views),
            ],
            'engagement_rate' => Calculator::engagementRate(Calculator::total($interactions, Metric::INTERACTIONS), Calculator::total($views, Metric::VIEWS)),
            'per_post' => $perPost,
        ];
    }

    /**
     * Publicaciones de la app publicadas en el periodo (dias de la zona de la marca) con su ultimo snapshot de metricas.
     * Los KPIs por publicacion se agregan en SQL sobre TODAS; la lista devuelve solo las `dashboard_posts` con mas
     * interacciones y `total` indica cuantas hay (para avisar de que se muestra un top N).
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array{kpis: array<string, mixed>, posts: list<array<string, mixed>>, total: int, limit: int}
     */
    private function postStats(User $user, Brand $brand, $accounts, Period $period): array
    {
        $limit = max(1, (int) config('social.analytics.dashboard_posts', self::MAX_POSTS));

        if ($accounts->isEmpty()) {
            return ['kpis' => ['posts' => 0, 'views' => null, 'interactions' => null, 'engagement_rate' => null], 'posts' => [], 'total' => 0, 'limit' => $limit];
        }

        [$from, $to] = $this->bounds($brand, $period);

        $query = fn (): Builder => PostTarget::withoutUserScope()
            ->where('post_targets.user_id', $user->id)
            ->whereIn('post_targets.social_account_id', $accounts->pluck('id'))
            ->where('post_targets.status', PostStatus::Published->value)
            ->where('post_targets.published_at', '>=', $from)->where('post_targets.published_at', '<', $to)
            ->leftJoinSub(
                PostMetricSnapshot::withoutUserScope()->where('user_id', $user->id)->selectRaw('post_target_id, MAX(date) as last_date')->groupBy('post_target_id'),
                'lm', 'lm.post_target_id', '=', 'post_targets.id',
            )
            ->leftJoin('post_metric_snapshots as pm', fn ($j) => $j->on('pm.post_target_id', '=', 'lm.post_target_id')->on('pm.date', '=', 'lm.last_date'));

        $grammar = DB::connection((new PostTarget)->getConnectionName())->getQueryGrammar();
        // Un `null` JSON es "no disponible": NULLIF evita que MySQL lo lea como la cadena 'null' (que casteada seria 0).
        $num = fn (string $key): string => 'CAST(NULLIF('.$grammar->wrap('pm.metrics->'.$key).", 'null') AS DECIMAL(20,4))";
        $views = $num(Metric::VIEWS);
        $inter = $num(Metric::INTERACTIONS);

        $agg = $query()->selectRaw("COUNT(*) as total, AVG($views) as avg_views, AVG($inter) as avg_interactions, AVG(CASE WHEN $views > 0 AND $inter IS NOT NULL THEN $inter * 100.0 / $views END) as avg_engagement")->first();

        $targets = $query()
            ->with(['post' => fn ($q) => $q->withoutGlobalScopes()])
            ->select('post_targets.*', 'pm.metrics as latest_metrics')
            ->orderByRaw("($inter IS NULL) ASC")->orderByRaw("$inter DESC")->orderByDesc('post_targets.published_at')->orderByDesc('post_targets.id')
            ->limit($limit)->get();

        $posts = $targets->map(function (PostTarget $t) {
            $raw = $t->getAttribute('latest_metrics');
            $metrics = Metric::clean(is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw, Metric::POST);

            return [
                'id' => $t->id,
                'text' => $t->post?->text ? mb_strimwidth($t->post->text, 0, 140, '...') : null,
                'content_type' => $t->content_type->value,
                'published_at' => $t->published_at?->toIso8601String(),
                'url' => $t->external_url,
                'metrics' => $metrics,
                'has_data' => ! Metric::isEmpty($metrics),
                'engagement_rate' => Calculator::engagementRate($metrics[Metric::INTERACTIONS], $metrics[Metric::VIEWS]),
            ];
        })->values()->all();

        $round = fn ($v) => $v === null ? null : round((float) $v, 2);
        $total = (int) ($agg->total ?? 0);

        return [
            'kpis' => [
                'posts' => $total,
                'views' => $round($agg->avg_views ?? null),
                'interactions' => $round($agg->avg_interactions ?? null),
                'engagement_rate' => $round($agg->avg_engagement ?? null),
            ],
            'posts' => $posts,
            'total' => $total,
            'limit' => $limit,
        ];
    }

    /**
     * Limites UTC [desde, hasta) del periodo: sus dias son dias de la zona horaria de la marca (una publicacion de las
     * 23:30 locales del ultimo dia pertenece al periodo aunque en UTC ya sea el dia siguiente).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(Brand $brand, Period $period): array
    {
        return $period->utcBounds($brand->timezone);
    }

    /**
     * Series por red, metrica y CUENTA del periodo (se combinan despues segun sea flujo o stock).
     *
     * @return array<string, array<string, list<array<string, int|float|null>>>> [red][metrica][] = [fecha => valor] (una por cuenta)
     */
    private function networkSeries(User $user, Brand $brand, Period $period, ?Network $only = null): array
    {
        $rows = AccountSnapshot::withoutUserScope()
            ->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->when($only, fn ($q) => $q->where('network', $only->value))
            ->whereBetween('date', [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')])
            ->get(['social_account_id', 'network', 'date', 'metrics']);

        $perAccount = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);

            foreach (Metric::ACCOUNT as $metric) {
                $perAccount[$row->network->value][$metric][$row->social_account_id][$date] = $row->metrics[$metric] ?? null;
            }
        }

        $out = [];

        foreach ($perAccount as $network => $byMetric) {
            foreach ($byMetric as $metric => $byAccount) {
                foreach ($byAccount as $series) {
                    ksort($series);
                    $out[$network][$metric][] = $series;
                }
            }
        }

        return $out;
    }

    /**
     * Completa la serie con todos los dias del periodo (`null` en los que no hay snapshot).
     *
     * @param  array<string, int|float|null>  $series
     * @param  list<string>  $dates
     * @return array<string, int|float|null>
     */
    private function fill(array $series, array $dates): array
    {
        $out = [];

        foreach ($dates as $d) {
            $out[$d] = $series[$d] ?? null;
        }

        return $out;
    }

    private function assertOwns(User $user, Brand $brand): void
    {
        if ($brand->user_id !== $user->id) {
            throw new InvalidArgumentException('La marca no pertenece al usuario.');
        }
    }
}
