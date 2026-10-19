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
            'networks' => collect(Network::cases())->map(fn (Network $n) => ['value' => $n->value, 'label' => $n->label()])->values()->all(),
            'items' => $items->all(),
            'undated' => $undated->all(),
            'truncated' => $items->count() >= self::MAX_ITEMS,
        ];
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
            'text' => mb_substr((string) $post->text, 0, 140),
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'local_date' => $local?->format('Y-m-d'),
            'local_time' => $local?->format('H:i'),
            'local_datetime' => $local?->format('Y-m-d\TH:i'),
            'editable' => $editable,
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
