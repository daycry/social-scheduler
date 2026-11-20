<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Goal;
use App\Models\SocialAccount;
use App\Services\GoalService;
use App\Support\CursorPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GoalController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['brand_id' => ['nullable', 'integer', 'min:1']] + CursorPage::RULES);
        $user = $request->user();
        $brands = Brand::ownedBy($user->id)->orderBy('name')->orderBy('id')->get(['id', 'name', 'timezone']);
        $brand = isset($filters['brand_id']) ? $brands->firstWhere('id', (int) $filters['brand_id']) : $brands->first();
        abort_if(isset($filters['brand_id']) && $brand === null, 404);
        $query = Goal::ownedBy($user->id)->where('brand_id', $brand?->id)
            ->with(['account' => fn ($query) => $query->where('user_id', $user->id)->where('brand_id', $brand?->id)->select('id', 'name', 'status')])
            ->orderByDesc('created_at')->orderByDesc('id');
        [$goals, $pagination] = CursorPage::fetch($query, $filters['limit'] ?? null, $filters['cursor'] ?? null,
            'goals:u'.$user->id.':b'.($brand?->id ?? 'none'), withTotal: true);

        return Inertia::render('Goals/Index', [
            'brands' => $brands, 'brand' => $brand,
            'goals' => $goals->map(fn (Goal $goal) => [
                'id' => $goal->id, 'name' => $goal->name, 'kind' => $goal->kind, 'metric' => $goal->metric,
                'threshold' => $goal->threshold, 'social_account_id' => $goal->social_account_id,
                'account_name' => $goal->account?->name, 'account_requires_reconnection' => $goal->account?->status === SocialAccount::STATUS_RECONNECT, 'enabled' => $goal->enabled, 'mail_enabled' => $goal->mail_enabled,
                'last_value' => $goal->last_value, 'last_period' => $goal->last_period,
                'last_evaluated_at' => $goal->last_evaluated_at?->toIso8601String(),
                'last_expires_at' => $goal->last_expires_at?->toIso8601String(),
            ])->values(),
            'accounts' => $brand === null ? [] : SocialAccount::ownedBy($user->id)->where('brand_id', $brand->id)
                ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('name')->get(['id', 'name', 'network'])
                ->map(fn (SocialAccount $account) => ['id' => $account->id, 'name' => $account->name, 'network_label' => $account->network->label()])->all(),
            'kinds' => Goal::KINDS, 'metrics' => Goal::METRICS, 'limits' => Goal::LIMITS, 'pagination' => $pagination + $filters,
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        $this->goals->create($request->user(), $brand, $request->all());

        return redirect()->route('goals.index', ['brand_id' => $brand->id])->with('success', 'Objetivo creado.');
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        $this->goals->update($request->user(), $goal, $request->all());

        return redirect()->route('goals.index', ['brand_id' => $goal->brand_id])->with('success', 'Objetivo actualizado.');
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        $this->goals->delete($request->user(), $goal);

        return redirect()->route('goals.index', ['brand_id' => $goal->brand_id])->with('success', 'Objetivo eliminado.');
    }
}
