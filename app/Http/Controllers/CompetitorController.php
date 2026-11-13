<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Competitor;
use App\Services\CompetitorService;
use App\Support\CsvWriter;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Analytics · Competidores (PA-08, T-25: Instagram, Bluesky y YouTube): anadir @usuario, ver la comparativa de la red elegida y dejar de seguir. */
class CompetitorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CompetitorService $competitors) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $brand = CurrentBrand::resolve($request);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', 'in:'.implode(',', CompetitorService::SORTS)], 'competitor' => ['nullable', 'integer'], 'network' => ['nullable', 'in:'.$this->networkValues()]]);
        $network = Network::tryFrom((string) ($q['network'] ?? '')) ?? Network::Instagram;

        try {
            $comparison = $brand ? $this->competitors->comparison($request->user(), $brand, $q['from'] ?? null, $q['to'] ?? null, $q['sort'] ?? 'followers', $network) : null;
        } catch (PostValidationException $e) {
            return redirect()->route('competitors.index', ['brand_id' => $brand?->id] + $this->networkQuery($network))->withErrors($e->errors());
        }

        $selected = $comparison && isset($q['competitor']) ? collect($comparison['competitors'])->firstWhere('id', (int) $q['competitor']) : null;

        return Inertia::render('Competitors/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'network' => $network->value,
            'has_account' => $brand ? $this->hasAccount($request->user()->id, $brand->id, $network) : false,
            // Redes con fuente de competidores; `has_account` = hay una cuenta propia activa con la que consultar (Bluesky no la necesita).
            'networks' => collect($this->competitors->networks())->map(fn (Network $n) => [
                'value' => $n->value,
                'label' => $n->label(),
                'needs_account' => $this->competitors->source($n)->needsAccount(),
                'copy' => $this->competitors->source($n)->copy(),
                'has_account' => $brand ? $this->hasAccount($request->user()->id, $brand->id, $n) : false,
            ])->values()->all(),
            'comparison' => $comparison,
            'sorts' => CompetitorService::SORTS,
            'selected' => $selected ? ['id' => $selected['id'], 'username' => $selected['username'], 'posts' => $this->competitors->topPosts($request->user(), Competitor::query()->findOrFail($selected['id']), $comparison['range']['from'], $comparison['range']['to'])] : null,
            'max' => (int) config('social.competitors.max_per_brand', 10),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    /** ¿Se puede consultar en esa red? Bluesky es publico; Instagram y YouTube necesitan una cuenta propia activa de la marca. */
    private function hasAccount(int $userId, int $brandId, Network $network): bool
    {
        return ! $this->competitors->source($network)->needsAccount() || $this->competitors->queryAccount($userId, $brandId, null, $network) !== null;
    }

    /** Instagram es la red por defecto: su URL no lleva `network` (se conservan los enlaces anteriores a T-25). @return array<string, string> */
    private function networkQuery(Network $network): array
    {
        return $network === Network::Instagram ? [] : ['network' => $network->value];
    }

    /** Redes admitidas por la validacion: las que tienen fuente de competidores en esta instalacion. */
    private function networkValues(): string
    {
        return implode(',', array_map(fn (Network $n) => $n->value, $this->competitors->networks()));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'network' => ['nullable', 'in:'.$this->networkValues()],
            'username' => ['required', 'string', 'max:300'],
        ]);
        $network = Network::tryFrom((string) ($data['network'] ?? '')) ?? Network::Instagram;
        $brand = Brand::query()->findOrFail((int) $data['brand_id']); // UserScope: una marca ajena es 404

        try {
            $competitor = $this->competitors->add($request->user(), $brand, $data['username'], $network);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('competitors.index', ['brand_id' => $brand->id] + $this->networkQuery($network))->with('success', "Siguiendo a @{$competitor->username}.");
    }

    public function destroy(Competitor $competitor): RedirectResponse
    {
        $this->authorize('delete', $competitor);
        $brandId = $competitor->brand_id;
        $this->competitors->remove($competitor);

        return redirect()->route('competitors.index', ['brand_id' => $brandId] + $this->networkQuery($competitor->network))->with('success', 'Competidor eliminado.');
    }

    /** «Actualizar publicaciones»: lee las ultimas publicaciones publicas del competidor (Business Discovery). */
    public function refreshPosts(Request $request, Competitor $competitor): RedirectResponse
    {
        $this->authorize('delete', $competitor);

        try {
            $n = $this->competitors->refreshPostsFor($request->user(), $competitor);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Publicaciones de @{$competitor->username} actualizadas ($n).");
    }

    /** Comparativa en CSV (rango y orden elegidos), con la neutralizacion de formulas de `CsvWriter`. */
    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', 'in:'.implode(',', CompetitorService::SORTS)], 'network' => ['nullable', 'in:'.$this->networkValues()]]);
        $brand = CurrentBrand::resolve($request);
        abort_if($brand === null, 404);

        try {
            $comparison = $this->competitors->comparison($request->user(), $brand, $q['from'] ?? null, $q['to'] ?? null, $q['sort'] ?? 'followers', Network::tryFrom((string) ($q['network'] ?? '')) ?? Network::Instagram);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }
        $rows = $this->competitors->csvRows($comparison);
        $csv = CsvWriter::forConfiguredLocale();
        $name = 'competidores-'.$comparison['range']['from'].'_'.$comparison['range']['to'].'.csv';

        return response()->streamDownload(function () use ($rows, $csv): void {
            echo CsvWriter::BOM;
            foreach ($rows as $row) {
                echo $csv->line($row);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
