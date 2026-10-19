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
use Illuminate\Support\Collection;
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
        'instagram' => [Metric::FOLLOWERS, Metric::FOLLOWERS_GAINED, Metric::VIEWS, Metric::REACH, Metric::PROFILE_VIEWS, Metric::ACCOUNTS_ENGAGED],
        'facebook' => [Metric::FOLLOWERS, Metric::VIEWS, Metric::INTERACTIONS],
        'youtube' => [Metric::FOLLOWERS, Metric::FOLLOWERS_GAINED, Metric::FOLLOWERS_LOST, Metric::VIEWS, Metric::WATCH_MINUTES, Metric::INTERACTIONS, Metric::LIKES, Metric::COMMENTS, Metric::SHARES, Metric::VIDEO_COUNT],
        'tiktok' => [Metric::FOLLOWERS, Metric::VIDEO_COUNT],
    ];

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
                $series = $this->fill($current[$net][$metric] ?? [], $dates);
                $value = Calculator::total($series, $metric);
                $prev = $compare ? Calculator::total($previous[$net][$metric] ?? [], $metric) : null;

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
        $current = $this->networkSeries($user, $brand, $period)[$network->value] ?? [];
        $previous = $compare ? ($this->networkSeries($user, $brand, $compare)[$network->value] ?? []) : [];

        $metrics = [];

        foreach (self::SUPPORTED[$network->value] as $metric) {
            $series = $this->fill($current[$metric] ?? [], $dates);
            $total = Calculator::total($series, $metric);
            $prev = $compare ? Calculator::total($previous[$metric] ?? [], $metric) : null;

            $metrics[] = [
                'key' => $metric, 'label' => self::LABELS[$metric], 'kind' => in_array($metric, Metric::STOCKS, true) ? 'stock' : 'flow',
                'total' => $total, 'previous' => $prev, 'delta_pct' => Calculator::delta($total, $prev)['pct'],
                'available' => $total !== null,
                'series' => collect($series)->map(fn ($v, $d) => ['date' => $d, 'value' => $v])->values()->all(),
            ];
        }

        $followers = $this->fill($current[Metric::FOLLOWERS] ?? [], $dates);
        $posts = $this->posts($user, $accounts, $period);

        return [
            'network' => ['value' => $network->value, 'label' => $network->label()],
            'accounts' => $accounts->map(fn (SocialAccount $a) => ['id' => $a->id, 'name' => $a->name, 'username' => $a->username, 'status' => $a->status])->all(),
            'period' => $period->toArray(),
            'comparison' => $compare?->toArray(),
            'growth' => Calculator::growth($followers) + [
                'previous' => $compare ? Calculator::growth($previous[Metric::FOLLOWERS] ?? []) : null,
            ],
            'metrics' => $metrics,
            'kpis' => $this->kpis($current, $dates, $posts),
            'posts' => $posts,
        ];
    }

    /**
     * KPIs derivados: por dia (sobre la serie de la cuenta) y por publicacion (sobre las de la app con datos).
     *
     * @param  array<string, array<string, int|float|null>>  $current
     * @param  list<array<string, mixed>>  $posts
     * @return array<string, mixed>
     */
    private function kpis(array $current, array $dates, array $posts): array
    {
        $interactions = $this->fill($current[Metric::INTERACTIONS] ?? [], $dates);
        $views = $this->fill($current[Metric::VIEWS] ?? [], $dates);
        $totalInteractions = Calculator::total($interactions, Metric::INTERACTIONS);

        return [
            'per_day' => [
                'interactions' => Calculator::perDay($interactions),
                'views' => Calculator::perDay($views),
            ],
            'engagement_rate' => Calculator::engagementRate($totalInteractions, Calculator::total($views, Metric::VIEWS)),
            'per_post' => [
                'posts' => count($posts),
                'views' => Calculator::average(array_column(array_column($posts, 'metrics'), Metric::VIEWS)),
                'interactions' => Calculator::average(array_column(array_column($posts, 'metrics'), Metric::INTERACTIONS)),
                'engagement_rate' => Calculator::average(array_column($posts, 'engagement_rate')),
            ],
        ];
    }

    /**
     * Publicaciones de la app publicadas en el periodo, con su ultimo snapshot de metricas.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function posts(User $user, $accounts, Period $period): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $targets = PostTarget::withoutUserScope()
            ->with(['post' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('user_id', $user->id)
            ->whereIn('social_account_id', $accounts->pluck('id'))
            ->where('status', PostStatus::Published->value)
            ->whereBetween('published_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->orderByDesc('published_at')
            ->limit(self::MAX_POSTS)
            ->get();

        $latest = PostMetricSnapshot::withoutUserScope()
            ->where('user_id', $user->id)->whereIn('post_target_id', $targets->pluck('id'))
            ->orderBy('date')->get()->keyBy('post_target_id'); // el ultimo dia sobrescribe

        return $targets->map(function (PostTarget $t) use ($latest) {
            $metrics = Metric::clean($latest[$t->id]->metrics ?? [], Metric::POST);

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
        })->sortByDesc(fn ($p) => $p['metrics'][Metric::INTERACTIONS] ?? -1)->values()->all();
    }

    /**
     * Series por red y metrica del periodo, sumando las cuentas de la misma red de la marca.
     *
     * @return array<string, array<string, array<string, int|float|null>>> [red][metrica][fecha => valor]
     */
    private function networkSeries(User $user, Brand $brand, Period $period): array
    {
        $rows = AccountSnapshot::withoutUserScope()
            ->where('user_id', $user->id)->where('brand_id', $brand->id)
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
                $out[$network][$metric] = Calculator::sumSeries(array_values($byAccount));
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
