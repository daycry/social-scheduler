<?php

namespace App\Http\Controllers;

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

/** Analytics · Competidores de Instagram (PA-08): anadir @usuario, ver la comparativa y dejar de seguir. */
class CompetitorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CompetitorService $competitors) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $brand = CurrentBrand::resolve($request);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', 'in:'.implode(',', CompetitorService::SORTS)], 'competitor' => ['nullable', 'integer']]);

        try {
            $comparison = $brand ? $this->competitors->comparison($request->user(), $brand, $q['from'] ?? null, $q['to'] ?? null, $q['sort'] ?? 'followers') : null;
        } catch (PostValidationException $e) {
            return redirect()->route('competitors.index', ['brand_id' => $brand?->id])->withErrors($e->errors());
        }

        $selected = $comparison && isset($q['competitor']) ? collect($comparison['competitors'])->firstWhere('id', (int) $q['competitor']) : null;

        return Inertia::render('Competitors/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'has_instagram' => $brand ? $this->competitors->queryAccount($request->user()->id, $brand->id) !== null : false,
            'comparison' => $comparison,
            'sorts' => CompetitorService::SORTS,
            'selected' => $selected ? ['id' => $selected['id'], 'username' => $selected['username'], 'posts' => $this->competitors->topPosts($request->user(), Competitor::query()->findOrFail($selected['id']), $comparison['range']['from'], $comparison['range']['to'])] : null,
            'max' => (int) config('social.competitors.max_per_brand', 10),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'username' => ['required', 'string', 'max:31'],
        ]);
        $brand = Brand::query()->findOrFail((int) $data['brand_id']); // UserScope: una marca ajena es 404

        try {
            $competitor = $this->competitors->add($request->user(), $brand, $data['username']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('competitors.index', ['brand_id' => $brand->id])->with('success', "Siguiendo a @{$competitor->username}.");
    }

    public function destroy(Competitor $competitor): RedirectResponse
    {
        $this->authorize('delete', $competitor);
        $brandId = $competitor->brand_id;
        $competitor->delete();

        return redirect()->route('competitors.index', ['brand_id' => $brandId])->with('success', 'Competidor eliminado.');
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
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', 'in:'.implode(',', CompetitorService::SORTS)]]);
        $brand = CurrentBrand::resolve($request);
        abort_if($brand === null, 404);

        try {
            $comparison = $this->competitors->comparison($request->user(), $brand, $q['from'] ?? null, $q['to'] ?? null, $q['sort'] ?? 'followers');
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
