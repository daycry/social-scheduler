<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Campañas (C-03, D3): por marca, asignadas al post entero. La propiedad la fija el UserScope del modelo
 * y la comprueba la política; la marca se valida en el request y no cambia tras el alta.
 */
class CampaignService
{
    /** Campañas de una marca, activas primero y luego archivadas; con el recuento de posts. */
    public function listForBrand(User $user, int $brandId, bool $withArchived = true): Collection
    {
        return Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brandId)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->withCount('posts')
            ->orderByRaw('archived_at is null desc')->orderBy('name')->get();
    }

    /** @param array{brand_id: int, name: string, color: string, starts_on?: ?string, ends_on?: ?string} $data */
    public function create(User $user, array $data): Campaign
    {
        $campaign = new Campaign($data);
        $campaign->user_id = $user->id;
        $campaign->save();

        return $campaign;
    }

    /** @param array{name: string, color: string, starts_on?: ?string, ends_on?: ?string} $data */
    public function update(Campaign $campaign, array $data): Campaign
    {
        $campaign->fill([
            'name' => $data['name'], 'color' => $data['color'],
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
        ])->save();

        return $campaign;
    }

    public function archive(Campaign $campaign, bool $archived): Campaign
    {
        $campaign->archived_at = $archived ? now() : null;
        $campaign->save();

        return $campaign;
    }

    /** Los posts quedan sin campaña (`nullOnDelete`). */
    public function delete(Campaign $campaign): void
    {
        $campaign->delete();
    }

    /** @return array<string, mixed> */
    public function toArray(Campaign $c): array
    {
        return [
            'id' => $c->id, 'brand_id' => $c->brand_id, 'name' => $c->name, 'color' => $c->color,
            'starts_on' => $c->starts_on?->toDateString(), 'ends_on' => $c->ends_on?->toDateString(),
            'archived' => $c->isArchived(), 'posts_count' => $c->posts_count ?? null,
            'show_url' => route('campaigns.show', $c->id, false),
        ];
    }
}
