<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\User;
use App\Support\CursorPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ] + CursorPage::RULES);
        if (! empty($data['from']) && ! empty($data['to'])) {
            validator($data, ['to' => ['after_or_equal:from']])->validate();
        }

        $user = $request->user();
        $brands = Brand::ownedBy($user->id)->orderBy('name')->get(['id', 'name', 'timezone']);
        $brand = ! empty($data['brand_id']) ? ($brands->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : null;
        $timezone = $brand?->timezone ?: 'UTC';
        // Rangos disjuntos para contar y obtener opciones sin recorrer otras cuentas.
        $personal = AuditLog::query()->where('user_id', $user->id);
        $branded = AuditLog::query()->whereIn('brand_id', $brands->modelKeys())
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '<>', $user->id));
        $visible = AuditLog::query()->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)->orWhereIn('brand_id', $brands->modelKeys()));
        $actorIds = (clone $personal)->select('user_id')->distinct()
            ->union((clone $branded)->select('user_id')->whereNotNull('user_id')->distinct());
        $actors = User::query()->joinSub(
            $actorIds,
            'visible_actors', 'visible_actors.user_id', '=', 'users.id',
        )->orderBy('users.name')->get(['users.id', 'users.name']);
        if (! empty($data['user_id']) && ! $actors->contains('id', (int) $data['user_id'])) {
            abort(404);
        }

        $filters = [
            'brand_id' => $brand?->id,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'action' => $data['action'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ];
        $applyFilters = fn (Builder $query) => $query
            ->when($brand, fn (Builder $q) => $q->where('audit_logs.brand_id', $brand->id))
            ->when($filters['user_id'], fn (Builder $q) => $q->where('audit_logs.user_id', $filters['user_id']))
            ->when($filters['action'], fn (Builder $q) => $q->where('action', $filters['action']))
            ->when($filters['from'], fn (Builder $q) => $q->where('created_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $q) => $q->where('created_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->startOfDay()->addDay()->utc()));
        $query = $applyFilters($brand ? AuditLog::query() : $visible)
            ->select(['id', 'user_id', 'brand_id', 'action', 'outcome', 'channel', 'created_at'])
            ->latest('created_at')->latest('id');
        [$entries, $pagination] = CursorPage::fetch(
            $query, $data['limit'] ?? null, $data['cursor'] ?? null,
            'activity:u'.$user->id.':'.hash('sha256', json_encode($filters)),
        );
        $pagination['total'] = $applyFilters(clone $personal)->count() + $applyFilters(clone $branded)->count();
        $actionRows = (clone $personal)->select('action')->distinct()
            ->union((clone $branded)->select('action')->distinct());
        $actorNames = $actors->pluck('name', 'id');
        $brandNames = $brands->pluck('name', 'id');

        return Inertia::render('Activity/Index', [
            'entries' => $entries->map(fn (AuditLog $entry) => [
                'id' => $entry->id, 'action' => $entry->action, 'outcome' => $entry->outcome,
                'channel' => $entry->channel,
                'user' => $actorNames[$entry->user_id] ?? 'Cuenta eliminada',
                'brand' => $brandNames[$entry->brand_id] ?? null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->all(),
            'pagination' => $pagination + array_intersect_key($data, CursorPage::RULES),
            'filters' => $filters,
            'brands' => $brands->map(fn (Brand $item) => $item->only(['id', 'name']))->all(),
            'users' => $actors->all(),
            'actions' => DB::query()->fromSub($actionRows, 'visible_actions')->orderBy('action')->pluck('action')->all(),
            'timezone' => $timezone,
            'retention_days' => max(0, (int) config('retention.audit_logs_days')),
        ]);
    }
}
