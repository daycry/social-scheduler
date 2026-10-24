<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Campañas (C-03): CRUD por marca y archivo. La lista y el panel se añaden en T-26 y T-30. */
class CampaignController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CampaignService $campaigns) {}

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

    /** Sin UserScope a propósito: la campaña de otro usuario debe responder 403 (y auditarse), no 404. */
    private function find(int $id): Campaign
    {
        return Campaign::withoutUserScope()->findOrFail($id);
    }
}
