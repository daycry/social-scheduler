<?php

namespace App\Services;

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vistas guardadas del calendario (C-03a): nombre + filtros de un solo valor (marca, red, estado y vista). Como mucho una
 * por usuario es la vista por defecto. La propiedad la fija el UserScope; marca y red se validan en el request.
 */
class CalendarViewService
{
    /** Vistas del usuario por nombre. */
    public function list(User $user): Collection
    {
        return CalendarView::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->get();
    }

    public function default(User $user): ?CalendarView
    {
        return CalendarView::withoutUserScope()->where('user_id', $user->id)->where('is_default', true)->first();
    }

    /** @param array{name: string, filters?: array<string, mixed>|null, is_default?: bool} $data */
    public function create(User $user, array $data): CalendarView
    {
        return DB::transaction(function () use ($user, $data) {
            $view = new CalendarView(['name' => $data['name'], 'filters' => $this->clean($data['filters'] ?? []), 'is_default' => (bool) ($data['is_default'] ?? false)]);
            $view->user_id = $user->id;
            if ($view->is_default) {
                $this->clearDefault($user);
            }
            $view->save();

            return $view;
        });
    }

    /** @param array{name?: string, filters?: array<string, mixed>|null, is_default?: bool} $data Solo se tocan las claves presentes. */
    public function update(CalendarView $view, array $data): CalendarView
    {
        return DB::transaction(function () use ($view, $data) {
            if (array_key_exists('name', $data)) {
                $view->name = $data['name'];
            }
            if (array_key_exists('filters', $data)) {
                $view->filters = $this->clean($data['filters'] ?? []);
            }
            if (array_key_exists('is_default', $data)) {
                $view->is_default = (bool) $data['is_default'];
                if ($view->is_default) {
                    $this->clearDefault($view->user, except: $view->id);
                }
            }
            $view->save();

            return $view;
        });
    }

    public function delete(CalendarView $view): void
    {
        $view->delete();
    }

    /**
     * Filtros de la vista listos para `CalendarService::build`. Descarta lo que ya no vale (una marca borrada, una red o un
     * estado que ya no existen) en vez de fallar: una vista antigua nunca debe romper el calendario.
     *
     * @return array{brand_id?: int, network?: string, status?: string, view?: string}
     */
    public function filtersFor(User $user, CalendarView $view): array
    {
        $f = $this->clean($view->filters ?? []);
        if (isset($f['brand_id']) && ! Brand::withoutUserScope()->where('user_id', $user->id)->whereKey($f['brand_id'])->exists()) {
            unset($f['brand_id']);
        }
        if (isset($f['network']) && Network::tryFrom($f['network']) === null) {
            unset($f['network']);
        }
        if (isset($f['status']) && PostStatus::tryFrom($f['status']) === null) {
            unset($f['status']);
        }
        if (isset($f['view']) && ! in_array($f['view'], CalendarService::VIEWS, true)) {
            unset($f['view']);
        }

        return $f;
    }

    /** @return array{id: int, name: string, filters: array<string, mixed>, is_default: bool} */
    public function toArray(CalendarView $view): array
    {
        return ['id' => $view->id, 'name' => $view->name, 'filters' => $this->clean($view->filters ?? []), 'is_default' => $view->is_default];
    }

    /**
     * Solo las claves conocidas y con valor.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function clean(array $filters): array
    {
        $out = [];
        foreach (CalendarView::FILTER_KEYS as $key) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $key === 'brand_id' ? (int) $value : (string) $value;
        }

        return $out;
    }

    private function clearDefault(User $user, ?int $except = null): void
    {
        CalendarView::withoutUserScope()->where('user_id', $user->id)->where('is_default', true)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->update(['is_default' => false]);
    }
}
