<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Http\Requests\CampaignRequest;
use App\Models\Brand;
use App\Models\Campaign;
use App\Services\CampaignAnalytics;
use App\Services\CampaignService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Campañas (C-03): CRUD por marca y archivo. La lista y el panel se añaden en T-26 y T-30. */
class CampaignController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CampaignService $campaigns, private readonly CampaignAnalytics $analytics) {}

    public function index(Request $request): Response
    {
        $request->validate(['brand_id' => ['nullable']]);

        $brand = CurrentBrand::resolve($request);

        return Inertia::render('Campaigns/Index', [
            'brand' => $brand?->only(['id', 'name']),
            'campaigns' => $brand
                ? $this->campaigns->listForBrand($request->user(), $brand->id)->map(fn (Campaign $c) => $this->campaigns->toArray($c))->values()
                : [],
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    /** Panel de la campaña (C-03, CA-11): KPIs agregados, desglose por red, serie diaria y posts. La de otro usuario responde 404 (UserScope). */
    public function show(Request $request, int $id): Response
    {
        $campaign = $this->find($id);
        $this->authorize('view', $campaign);

        $summary = $this->analytics->summary($request->user(), $campaign);
        $summary['by_network'] = array_map(fn (array $row) => $row + ['network_label' => Network::from($row['network'])->label()], $summary['by_network']);
        foreach ($summary['posts'] as &$post) {
            $post['targets'] = array_map(fn (array $t) => $t + ['network_label' => Network::from($t['network'])->label()], $post['targets']);
        }
        unset($post);

        return Inertia::render('Campaigns/Show', [
            'campaign' => $this->campaigns->toArray($campaign),
            'brand' => Brand::withoutUserScope()->where('user_id', $request->user()->id)->find($campaign->brand_id)?->only(['id', 'name']),
            'summary' => $summary,
        ]);
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $this->campaigns->create($request->user(), $request->validated());

        return back()->with('success', 'Campaña creada.');
    }

    public function update(CampaignRequest $request, int $id): RedirectResponse
    {
        $campaign = $this->find($id);
        $this->authorize('update', $campaign);
        $this->campaigns->update($campaign, $request->validated());

        return back()->with('success', 'Campaña actualizada.');
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        $campaign = $this->find($id);
        $this->authorize('update', $campaign);
        $archived = $request->boolean('archived', true);
        $this->campaigns->archive($campaign, $archived);

        return back()->with('success', $archived ? 'Campaña archivada.' : 'Campaña restaurada.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $campaign = $this->find($id);
        $this->authorize('delete', $campaign);
        $this->campaigns->delete($campaign);

        return back()->with('success', 'Campaña eliminada.');
    }

    /** Con UserScope, como el resto del proyecto: la campaña de otro usuario responde 404 (no revela que existe, CWE-204). */
    private function find(int $id): Campaign
    {
        return Campaign::findOrFail($id);
    }
}
