<?php

namespace App\Services\Autolists;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\Posts\DuplicatePostResult;
use App\Services\Posts\PostDuplicator;
use App\Support\Ownership;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reciclado inteligente de mejores posts (T-92, F-20). Propone, por marca, las publicaciones ya publicadas con mejor rendimiento para
 * volver a publicarlas. Vive APARTE de la cola de las autolistas (su `recycle` solo reinicia el cursor de una cola fija): aqui el
 * contenido sale del historico medido, y reciclar solo crea un BORRADOR (nada se publica ni se programa solo).
 *
 * Rendimiento = engagement (interacciones / visualizaciones del ultimo snapshot del destino), el mismo criterio y la misma lectura del
 * ultimo snapshot que el dashboard (`Calculator::engagementRate` + `AnalyticsService::withLatestSnapshot`): al ser un cociente es comparable
 * entre redes. Un destino solo cuenta con cuenta conectada, `min_age_days` desde su publicacion y `min_views` visualizaciones.
 *
 * El contenido es una FAMILIA: el original (`recycle_family_id` nulo) y sus reciclados (siempre apuntan al original). El intervalo minimo
 * `min_interval_days` cuenta desde lo ultimo que le paso a la familia: publicacion de cualquier miembro (tambien en la papelera), creacion o fecha
 * programada de un reciclado vivo aun sin publicar (en papelera libera) o descarte de la propuesta. La clave de familia es estable: sobrevive a la purga del original. De cada familia se propone solo su miembro de mejor rendimiento.
 */
class RecycleService
{
    /** Motivos de exclusion, de mas a menos avanzado en la comprobacion; el valor es el rango y la etiqueta que ve el usuario. */
    private const REASONS = [
        'disconnected' => [1, 'La cuenta ya no está conectada'],
        'too_recent' => [2, 'Publicados hace poco: sus métricas aún no han madurado'],
        'no_metrics' => [3, 'Sin métricas de interacciones'],
        'low_views' => [4, 'Pocas visualizaciones para compararlos con justicia'],
        'interval' => [5, 'Publicados o reciclados hace menos del intervalo mínimo'],
        'dismissed' => [6, 'Descartados: se valoran de nuevo pasado el intervalo mínimo'],
    ];

    private const NOT_PROPOSED = 'Esta publicación no es una propuesta vigente: ya se reciclo, descartó o publicó hace poco, sus métricas no bastan, hay una versión mejor de este contenido o su cuenta ya no está conectada.';

    public function __construct(private readonly PostDuplicator $duplicator) {}

    /**
     * Umbrales vigentes (`social.recycle`), tambien para mostrarlos en la pagina.
     *
     * @return array{min_views: int, min_age_days: int, min_interval_days: int, window_days: int, limit: int}
     */
    public static function rules(): array
    {
        return [
            'min_views' => max(1, (int) config('social.recycle.min_views', 100)),
            'min_age_days' => max(0, (int) config('social.recycle.min_age_days', 14)),
            'min_interval_days' => max(1, (int) config('social.recycle.min_interval_days', 60)),
            'window_days' => max(1, (int) config('social.recycle.window_days', 365)),
            'limit' => max(1, (int) config('social.recycle.limit', 20)),
        ];
    }

    /**
     * Propuestas de la marca (de mejor a peor engagement) y cuantas familias quedan fuera por cada motivo. Solo se analizan destinos que
     * ya pueden proponerse (publicados entre `window_days` y `min_age_days` atras, filtrado en SQL) y, de ellos, como mucho
     * `social.recycle.max_targets`, los mas recientes primero: `not_analyzed` cuenta los mas antiguos que el tope deja sin mirar.
     *
     * @return array{proposals: list<array<string, mixed>>, total: int, not_analyzed: int, excluded: list<array{reason: string, label: string, count: int}>, rules: array<string, int>}
     */
    public function proposals(User $user, Brand $brand): array
    {
        Ownership::assertAll([$brand], $user);
        $rules = self::rules();
        $now = CarbonImmutable::instance(now());
        $connected = $this->connected($user, $brand);
        $cap = max(1, (int) config('social.recycle.max_targets', 2000));

        $targets = $this->publishedTargets($user, $brand, $rules, $now, limit: $cap)->groupBy('post_id');
        $notAnalyzed = $targets->flatten(1)->count() >= $cap ? max(0, $this->publishedQuery($user, $brand)->where('post_targets.published_at', '>=', $now->subDays($rules['window_days']))->where('post_targets.published_at', '<=', $now->subDays($rules['min_age_days']))->count() - $cap) : 0;
        $posts = Post::ownedBy($user->id)->where('brand_id', $brand->id)->whereIn('id', $targets->keys())->withCount('media')->get();
        $keys = $posts->map(fn (Post $p) => self::familyKey($p))->unique()->values();
        $members = $this->members($user, $brand, $keys->all());

        $excluded = array_fill_keys(array_keys(self::REASONS), 0);
        $proposals = [];

        foreach ($posts->groupBy(fn (Post $p) => self::familyKey($p)) as $key => $candidates) {
            $outcome = $this->familyProposal($candidates, $targets, $members->filter(fn (Post $m) => self::familyKey($m) === $key), $connected, $rules, $now);
            if ($outcome['proposal'] === null) {
                $excluded[$outcome['reason']]++;
            } else {
                $proposals[] = $outcome['proposal'];
            }
        }

        // Familias con publicaciones demasiado recientes y sin ninguna analizable: se cuentan aparte (no gastan el tope).
        $recent = $this->publishedQuery($user, $brand)->where('post_targets.published_at', '>', $now->subDays($rules['min_age_days']))->pluck('post_targets.post_id')->unique();
        if ($recent->isNotEmpty()) {
            Post::ownedBy($user->id)->where('brand_id', $brand->id)->whereIn('id', $recent)->get(['id', 'recycle_family_id'])
                ->map(fn (Post $p) => self::familyKey($p))->unique()->diff($keys)->each(function () use (&$excluded) {
                    $excluded['too_recent']++;
                });
        }

        usort($proposals, fn (array $a, array $b) => [$b['engagement_rate'], $b['published_at'], $b['post_id']] <=> [$a['engagement_rate'], $a['published_at'], $a['post_id']]);

        return [
            'proposals' => array_slice($proposals, 0, $rules['limit']),
            'total' => count($proposals),
            'not_analyzed' => $notAnalyzed,
            'excluded' => collect($excluded)->filter()->map(fn (int $count, string $reason) => ['reason' => $reason, 'label' => self::REASONS[$reason][1], 'count' => $count])->values()->all(),
            'rules' => $rules,
        ];
    }

    /**
     * Crea un BORRADOR con el mismo contenido y medios (por `PostDuplicator`, la misma validacion que el compositor), vinculado a la
     * familia del original. Solo se recicla una propuesta vigente: se vuelve a evaluar la familia del post (la misma funcion que usa
     * `proposals()`) bajo el cerrojo de la familia, asi dos peticiones simultaneas no crean dos reciclados.
     *
     * @throws PostValidationException
     */
    public function recycle(User $user, Post $post): DuplicatePostResult
    {
        $brand = $this->ownedBrand($user, $post);
        $key = self::familyKey($post);

        try {
            return DB::transaction(function () use ($user, $post, $brand, $key) {
                $this->lockFamily($user, $brand, $key);
                $this->assertProposed($user, $brand, $post);

                $result = $this->duplicator->duplicate($user, $post, $brand);
                $result->post->forceFill(['recycle_family_id' => $key])->save();

                return $result;
            });
        } catch (DeadlockException) {
            // Otra peticion reservaba la misma familia a la vez: para quien llega segundo ya no es una propuesta vigente.
            throw new PostValidationException(['post' => [self::NOT_PROPOSED]]);
        }
    }

    /**
     * Descarta la propuesta: el contenido vuelve a valorarse pasado el intervalo minimo. Solo una propuesta vigente se puede descartar.
     *
     * @throws PostValidationException
     */
    public function dismiss(User $user, Post $post): void
    {
        $brand = $this->ownedBrand($user, $post);
        $this->assertProposed($user, $brand, $post);
        $post->forceFill(['recycle_dismissed_at' => now()])->save();
    }

    /** Clave estable de la familia de un contenido: el id del original (`recycle_family_id` en los reciclados; el propio id en el original). */
    private static function familyKey(Post $post): int
    {
        return (int) ($post->recycle_family_id ?? $post->id);
    }

    private function ownedBrand(User $user, Post $post): Brand
    {
        Ownership::assertAll([$post], $user);
        $brand = Brand::ownedBy($user->id)->find($post->brand_id);
        Ownership::ensure($brand !== null);

        return $brand;
    }

    /** @return Collection<int, int> ids de las cuentas conectadas de la marca (claves) */
    private function connected(User $user, Brand $brand): Collection
    {
        return SocialAccount::ownedBy($user->id)->where('brand_id', $brand->id)->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->pluck('id')->flip();
    }

    /**
     * Miembros de las familias (original y reciclados, tambien los de la papelera: lo que se publico, publicado esta) con su ultima publicacion.
     *
     * @param  list<int>  $keys
     * @return Collection<int, Post>
     */
    private function members(User $user, Brand $brand, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return Post::withTrashed()->ownedBy($user->id)->where('brand_id', $brand->id)
            ->where(fn ($q) => $q->whereIn('id', $keys)->orWhereIn('recycle_family_id', $keys))
            ->withMax('targets', 'published_at')->get();
    }

    /**
     * Reserva la familia con UNA sola fila, localizada y bloqueada siempre por clave primaria (sin OR ni rangos de indice secundario, que en
     * MySQL toman bloqueos de hueco y pueden entrar en interbloqueo): la del original (tambien en la papelera) o, si se purgo, la del
     * miembro mas antiguo de la familia, que no cambia al crear nuevos reciclados.
     */
    protected function lockFamily(User $user, Brand $brand, int $key): void
    {
        $anchor = Post::withTrashed()->ownedBy($user->id)->where('brand_id', $brand->id)->whereKey($key)->exists()
            ? $key
            : Post::withTrashed()->ownedBy($user->id)->where('brand_id', $brand->id)->where('recycle_family_id', $key)->min('id');

        if ($anchor !== null) {
            Post::withTrashed()->ownedBy($user->id)->whereKey($anchor)->lockForUpdate()->first();
        }
    }

    /** @throws PostValidationException si el post no es hoy la propuesta de su familia (el mismo veredicto que `proposals()`). */
    private function assertProposed(User $user, Brand $brand, Post $post): void
    {
        $rules = self::rules();
        $now = CarbonImmutable::instance(now());
        $key = self::familyKey($post);
        $family = $this->members($user, $brand, [$key])->filter(fn (Post $m) => self::familyKey($m) === $key);
        $targets = $this->publishedTargets($user, $brand, $rules, $now, $family->reject->trashed()->pluck('id')->all())->groupBy('post_id');
        $candidates = Post::ownedBy($user->id)->where('brand_id', $brand->id)->whereIn('id', $targets->keys())->withCount('media')->get();

        $outcome = $candidates->isEmpty() ? ['proposal' => null] : $this->familyProposal($candidates, $targets, $family, $this->connected($user, $brand), $rules, $now);
        if (($outcome['proposal']['post_id'] ?? null) !== $post->id) {
            throw new PostValidationException(['post' => [self::NOT_PROPOSED]]);
        }
    }

    /**
     * Veredicto de una familia, el mismo para la lista y para reciclar/descartar: su mejor miembro elegible (con `recycled_times`) o el
     * motivo por el que no se propone (el de metricas mas avanzado, o el intervalo / descarte).
     *
     * @param  Collection<int, Post>  $candidates  miembros vivos con destinos analizables
     * @param  Collection<int, Collection<int, PostTarget>>  $targets  por post
     * @param  Collection<int, Post>  $family  todos los miembros (tambien en la papelera)
     * @param  Collection<int, int>  $connected
     * @param  array<string, int>  $rules
     * @return array{proposal: ?array<string, mixed>, reason: ?string}
     */
    private function familyProposal(Collection $candidates, Collection $targets, Collection $family, Collection $connected, array $rules, CarbonImmutable $now): array
    {
        $best = null;
        $worst = null;
        foreach ($candidates as $post) {
            $evaluated = $this->evaluate($post, $targets->get($post->id), $connected, $rules, $now);
            if ($evaluated['proposal'] !== null) {
                if ($best === null || $this->better($evaluated['proposal'], $best)) {
                    $best = $evaluated['proposal'];
                }
            } elseif ($worst === null || self::REASONS[$evaluated['reason']][0] > self::REASONS[$worst][0]) {
                $worst = $evaluated['reason'];
            }
        }

        if ($best === null) {
            return ['proposal' => null, 'reason' => $worst];
        }

        $reason = $this->familyReason($family, $rules, $now);
        if ($reason !== null) {
            return ['proposal' => null, 'reason' => $reason];
        }

        return ['reason' => null, 'proposal' => $best + ['recycled_times' => $family->filter(fn (Post $m) => $m->recycle_family_id !== null && ! $m->trashed())->count()]];
    }

    /** Destinos publicados de la marca (sin metricas): base comun del analisis y de los recuentos. */
    private function publishedQuery(User $user, Brand $brand, ?array $postIds = null): Builder
    {
        return PostTarget::ownedBy($user->id)->without('mediaItems')
            ->whereIn('post_targets.post_id', Post::ownedBy($user->id)->where('brand_id', $brand->id)->when($postIds !== null, fn ($q) => $q->whereIn('id', $postIds))->select('id'))
            ->where('post_targets.status', PostStatus::Published->value);
    }

    /**
     * Destinos analizables de la marca con su ultimo snapshot (`latest_metrics`), como el dashboard: publicados entre `window_days` y
     * `min_age_days` atras (filtrado en SQL, antes del tope) y los mas recientes primero.
     *
     * @param  array<string, int>  $rules
     * @param  list<int>|null  $postIds
     * @return Collection<int, PostTarget>
     */
    private function publishedTargets(User $user, Brand $brand, array $rules, CarbonImmutable $now, ?array $postIds = null, ?int $limit = null): Collection
    {
        $query = $this->publishedQuery($user, $brand, $postIds)
            ->where('post_targets.published_at', '>=', $now->subDays($rules['window_days']))
            ->where('post_targets.published_at', '<=', $now->subDays($rules['min_age_days']));

        return AnalyticsService::withLatestSnapshot($query, $user->id)->select('post_targets.*', 'pm.metrics as latest_metrics')
            ->orderByDesc('post_targets.published_at')->orderByDesc('post_targets.id')->when($limit, fn ($q) => $q->limit($limit))->get();
    }

    /**
     * Un post candidato: o su propuesta (destinos con cuenta conectada, maduros y con metricas suficientes) o el motivo mas avanzado
     * por el que ninguno de sus destinos vale.
     *
     * @param  Collection<int, PostTarget>|null  $targets
     * @param  Collection<int, int>  $connected  ids de cuenta conectada (claves)
     * @param  array<string, int>  $rules
     * @return array{proposal: ?array<string, mixed>, reason: ?string}
     */
    private function evaluate(Post $post, ?Collection $targets, Collection $connected, array $rules, CarbonImmutable $now): array
    {
        $valid = [];
        $reason = null;

        foreach ($targets ?? [] as $target) {
            $raw = $target->getAttribute('latest_metrics');
            $metrics = Metric::clean(is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw, Metric::POST);
            $views = $metrics[Metric::VIEWS];
            $rate = Calculator::engagementRate($metrics[Metric::INTERACTIONS], $views);

            $fail = match (true) {
                $target->social_account_id === null || ! $connected->has($target->social_account_id) => 'disconnected',
                Metric::isEmpty($metrics) || $metrics[Metric::INTERACTIONS] === null || $views === null => 'no_metrics',
                $views < $rules['min_views'] => 'low_views',
                default => null,
            };
            if ($fail !== null) {
                $reason = $reason === null || self::REASONS[$fail][0] > self::REASONS[$reason][0] ? $fail : $reason;

                continue;
            }

            $valid[] = [
                'id' => $target->id, 'network' => $target->network->value, 'account_id' => $target->social_account_id, 'published_at' => $target->published_at,
                'url' => $target->external_url, 'views' => $views, 'interactions' => $metrics[Metric::INTERACTIONS], 'engagement_rate' => $rate,
            ];
        }

        if ($valid === []) {
            return ['proposal' => null, 'reason' => $reason ?? 'no_metrics'];
        }

        $published = collect($valid)->max('published_at');

        return ['reason' => null, 'proposal' => [
            'post_id' => $post->id,
            'text' => $post->text !== null ? mb_strimwidth($post->text, 0, 240, '...') : null,
            'media_count' => (int) $post->media_count,
            'published_at' => $published->toIso8601String(),
            'views' => (int) array_sum(array_column($valid, 'views')),
            'interactions' => (int) array_sum(array_column($valid, 'interactions')),
            // Engagement agregado de los destinos validos: suma de interacciones / suma de visualizaciones (el mismo cociente que el dashboard),
            // coherente con las cifras mostradas; un destino con pocas visualizaciones no infla la tasa del post.
            'engagement_rate' => Calculator::engagementRate(array_sum(array_column($valid, 'interactions')), array_sum(array_column($valid, 'views'))),
            'targets' => array_map(fn (array $v) => ['id' => $v['id'], 'network' => $v['network'], 'account_id' => $v['account_id'], 'url' => $v['url'], 'views' => $v['views'], 'interactions' => $v['interactions'], 'engagement_rate' => $v['engagement_rate']], $valid),
        ]];
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function better(array $a, array $b): bool
    {
        return [$a['engagement_rate'], $a['published_at'], $a['post_id']] > [$b['engagement_rate'], $b['published_at'], $b['post_id']];
    }

    /**
     * Motivo por el que la familia aun no puede volver a proponerse (intervalo o descarte), o null si ya puede.
     *
     * @param  Collection<int, Post>  $family
     * @param  array<string, int>  $rules
     */
    private function familyReason(Collection $family, array $rules, CarbonImmutable $now): ?string
    {
        $last = null;
        $dismissed = null;
        $later = fn (?CarbonImmutable $a, $b) => $b === null ? $a : ($a === null || CarbonImmutable::parse($b)->gt($a) ? CarbonImmutable::parse($b) : $a);

        foreach ($family as $member) {
            $last = $later($last, $member->targets_max_published_at);
            if ($member->trashed()) {
                continue; // en la papelera solo cuenta lo que llego a publicarse; su borrador, descarte o programacion ya no existen
            }
            if ($member->recycle_family_id !== null && $member->targets_max_published_at === null) {
                // Reciclado aun sin publicar (borrador o programado): cuenta cuando se creo y para cuando esta programado.
                $last = $later($later($last, $member->created_at), $member->scheduled_at);
            }
            $dismissed = $later($dismissed, $member->recycle_dismissed_at);
        }

        $until = fn (?CarbonImmutable $from) => $from?->addDays($rules['min_interval_days']);

        return match (true) {
            $until($last)?->gt($now) => 'interval',
            $until($dismissed)?->gt($now) => 'dismissed',
            default => null,
        };
    }
}
