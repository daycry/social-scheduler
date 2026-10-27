<?php

namespace App\Services;

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Datos del calendario (semana, mes, lista) filtrables por marca y red. Solo ve publicaciones del usuario
 * indicado. Los instantes se calculan en la zona horaria de la marca filtrada (o la de la primera marca si
 * se ven todas) para que las columnas del calendario coincidan con la hora local que ve el usuario.
 */
class CalendarService
{
    public const VIEWS = ['week', 'month', 'list'];

    public const MAX_ITEMS = 500;

    /**
     * @param  array{view?: ?string, date?: ?string, brand_id?: int|string|null, network?: ?string, status?: ?string, q?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters): array
    {
        ['view' => $view, 'brands' => $brands, 'brand' => $brand, 'network' => $network, 'status' => $status, 'tz' => $tz, 'anchor' => $anchor, 'from' => $from, 'to' => $to] = $this->resolve($user, $filters);

        $query = Post::withoutUserScope()->where('user_id', $user->id)
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()->subSecond()])
            ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
            ->when($network, fn ($q) => $q->whereHas('targets', fn ($t) => $t->withoutGlobalScopes()->where('network', $network->value)))
            ->when($status, fn ($q) => $q->whereHas('targets', fn ($t) => $t->withoutGlobalScopes()->where('status', $status->value)))
            ->when($view === 'list' && ! empty($filters['q']), fn ($q) => $q->where('text', 'like', '%'.addcslashes((string) $filters['q'], '%_\\').'%'))
            ->with(['targets' => fn ($t) => $t->withoutGlobalScopes()->with(['account' => fn ($a) => $a->withoutGlobalScopes()]), 'brand' => fn ($b) => $b->withoutGlobalScopes()])
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(self::MAX_ITEMS);

        $items = $query->get()->map(fn (Post $p) => $this->item($p, $tz, $network))->values();

        // Borradores sin fecha: solo en la lista (no tienen hueco en el calendario).
        $undated = $view === 'list'
            ? Post::withoutUserScope()->where('user_id', $user->id)->whereNull('scheduled_at')
                ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
                ->with(['targets' => fn ($t) => $t->withoutGlobalScopes()->with(['account' => fn ($a) => $a->withoutGlobalScopes()]), 'brand' => fn ($b) => $b->withoutGlobalScopes()])
                ->latest('id')->limit(100)->get()->map(fn (Post $p) => $this->item($p, $tz, $network))->values()
            : collect();

        return [
            'view' => $view,
            'date' => $anchor->format('Y-m-d'),
            'from' => $from->format('Y-m-d'),
            'to' => $to->subDay()->format('Y-m-d'),
            'timezone' => $tz,
            'prev' => $this->shift($view, $anchor, -1)->format('Y-m-d'),
            'next' => $this->shift($view, $anchor, 1)->format('Y-m-d'),
            'today' => CarbonImmutable::now($tz)->format('Y-m-d'),
            'brands' => $brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'timezone' => $b->timezone])->values()->all(),
            'networks' => collect(Network::offered())->map(fn (Network $n) => $n->toArray())->values()->all(),
            'items' => $items->all(),
            'undated' => $undated->all(),
            'truncated' => $items->count() >= self::MAX_ITEMS,
        ];
    }

    /**
     * Filtros ya resueltos (marca, red, estado, zona y rango de la vista): los comparten el calendario y la exportacion CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function resolve(User $user, array $filters): array
    {
        $view = in_array($filters['view'] ?? null, self::VIEWS, true) ? $filters['view'] : 'week';
        $brands = Brand::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->get();
        $brand = null;

        if (! empty($filters['brand_id'])) {
            $brand = $brands->firstWhere('id', (int) $filters['brand_id'])
                ?? throw new InvalidArgumentException('La marca no existe.');
        }

        $network = ! empty($filters['network']) ? (Network::tryFrom((string) $filters['network']) ?? throw new InvalidArgumentException('Red desconocida.')) : null;
        $status = ! empty($filters['status']) ? (PostStatus::tryFrom((string) $filters['status']) ?? throw new InvalidArgumentException('Estado desconocido.')) : null;
        $tz = $brand?->timezone ?? $brands->first()?->timezone ?? 'UTC';

        try {
            $anchor = CarbonImmutable::parse($filters['date'] ?? 'today', $tz)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Fecha no valida.');
        }

        [$from, $to] = $this->range($view, $anchor);

        return compact('view', 'brands', 'brand', 'network', 'status', 'tz', 'anchor', 'from', 'to');
    }

    /**
     * Destinos de la vista Lista para exportar a CSV (C-03c): una fila por destino de las MISMAS publicaciones que lista el
     * calendario con esos filtros (mismo rango, marca, red, estado y busqueda; mas los borradores sin fecha), con el ultimo
     * snapshot de metricas de cada destino. Es una consulta, no una coleccion: quien la recorre lo hace en streaming.
     *
     * @param  array<string, mixed>  $filters
     * @return array{query: Builder<PostTarget>, tz: string}
     */
    public function exportQuery(User $user, array $filters): array
    {
        ['brand' => $brand, 'network' => $network, 'status' => $status, 'tz' => $tz, 'from' => $from, 'to' => $to] = $this->resolve($user, ['view' => 'list'] + $filters);

        $dated = Post::withoutUserScope()->where('user_id', $user->id)
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()->subSecond()])
            ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
            ->when($network, fn ($q) => $q->whereHas('targets', fn ($t) => $t->withoutGlobalScopes()->where('network', $network->value)))
            ->when($status, fn ($q) => $q->whereHas('targets', fn ($t) => $t->withoutGlobalScopes()->where('status', $status->value)))
            ->when(! empty($filters['q']), fn ($q) => $q->where('text', 'like', '%'.addcslashes((string) $filters['q'], '%_\\').'%'))
            ->select('id');
        $undated = Post::withoutUserScope()->where('user_id', $user->id)->whereNull('scheduled_at')
            ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
            ->select('id');

        $query = AnalyticsService::withLatestSnapshot(
            PostTarget::withoutUserScope()
                ->where('post_targets.user_id', $user->id)
                ->where(fn ($w) => $w->whereIn('post_targets.post_id', $dated)->orWhereIn('post_targets.post_id', $undated))
                ->when($network, fn ($q) => $q->where('post_targets.network', $network->value)),
            $user->id,
        )
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('brands', 'brands.id', '=', 'posts.brand_id')
            ->leftJoin('social_accounts as sa', 'sa.id', '=', 'post_targets.social_account_id')
            ->select('post_targets.*', 'pm.metrics as latest_metrics', 'posts.text as post_text', 'posts.scheduled_at as post_scheduled_at', 'brands.name as brand_name', 'brands.timezone as brand_timezone', 'sa.name as account_name')
            ->orderByRaw('(posts.scheduled_at IS NULL) ASC')->orderBy('posts.scheduled_at')->orderBy('post_targets.post_id')->orderBy('post_targets.id');

        return ['query' => $query, 'tz' => $tz];
    }

    /**
     * Aviso previo de la exportacion: cuantas filas saldrian y el tope configurado (`social.export.max_rows`).
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, max: int, truncated: bool}
     */
    public function exportSummary(User $user, array $filters): array
    {
        $max = self::exportMaxRows();
        $total = (clone $this->exportQuery($user, $filters)['query'])->reorder()->toBase()->getCountForPagination(['post_targets.id']);

        return ['total' => $total, 'max' => $max, 'truncated' => $total > $max];
    }

    public static function exportMaxRows(): int
    {
        return max(1, (int) config('social.export.max_rows', 5000));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} Intervalo [from, to) en la zona del ancla. */
    private function range(string $view, CarbonImmutable $anchor): array
    {
        return match ($view) {
            'month' => [
                $anchor->startOfMonth()->startOfWeek(CarbonInterface::MONDAY),
                $anchor->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->addDay()->startOfDay(),
            ],
            'list' => [$anchor->startOfMonth(), $anchor->startOfMonth()->addMonths(2)], // mes actual y siguiente
            default => [$anchor->startOfWeek(CarbonInterface::MONDAY), $anchor->startOfWeek(CarbonInterface::MONDAY)->addDays(7)],
        };
    }

    private function shift(string $view, CarbonImmutable $anchor, int $dir): CarbonImmutable
    {
        return match ($view) {
            'week' => $anchor->addWeeks($dir),
            default => $anchor->addMonthsNoOverflow($dir),
        };
    }

    /**
     * Texto que se muestra del post: el de la plantilla, salvo que no se publique en ningun destino (plantilla vacia o TODOS los
     * destinos con texto propio), y entonces el primer texto efectivo no vacio de sus destinos. El efectivo se resuelve con la
     * plantilla ya cargada (`text ?? plantilla`) y no con `effectiveText()`, que consultaria el post de cada destino (N+1).
     */
    private function displayText(Post $post): string
    {
        $template = (string) $post->text;
        $targets = $post->targets;
        $allOwn = $targets->isNotEmpty() && $targets->every(fn ($t) => trim((string) $t->text) !== '');

        if (trim($template) !== '' && ! $allOwn) {
            return $template;
        }

        foreach ($targets as $target) {
            $effective = (string) ($target->text ?? $template);
            if (trim($effective) !== '') {
                return $effective;
            }
        }

        return $template;
    }

    /** @return array<string, mixed> */
    private function item(Post $post, string $tz, ?Network $networkFilter): array
    {
        /** @var Collection<int, PostTarget> $targets */
        $targets = $post->targets->when($networkFilter, fn ($c) => $c->filter(fn (PostTarget $t) => $t->network === $networkFilter)->values());
        $editable = ! $post->targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal());
        $local = $post->scheduled_at?->copy()->setTimezone($tz);

        return [
            'id' => $post->id,
            'brand_id' => $post->brand_id,
            'brand_name' => $post->brand?->name,
            'text' => mb_substr($this->displayText($post), 0, 140),
            'has_notes' => trim((string) $post->notes) !== '',
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'local_date' => $local?->format('Y-m-d'),
            'local_time' => $local?->format('H:i'),
            'local_datetime' => $local?->format('Y-m-d\TH:i'),
            'editable' => $editable,
            // «Publicar ahora» (borradores y programadas con destinos que aun se pueden editar); la accion la valida PostService.
            'can_publish_now' => $editable && $post->targets->isNotEmpty() && in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true),
            'targets' => $targets->map(fn (PostTarget $t) => [
                'network' => $t->network->value,
                'network_label' => $t->network->label(),
                'account_name' => $t->account?->name,
                'content_type' => $t->content_type->value,
                'content_label' => $t->content_type->label(),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'error_message' => $t->error_message,
                'status_note' => $t->status_note,
                'external_url' => $t->external_url,
            ])->values()->all(),
        ];
    }
}
