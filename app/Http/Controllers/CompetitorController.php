<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Competitor;
use App\Services\CompetitorService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Analytics · Competidores de Instagram (PA-08): anadir @usuario, ver la comparativa y dejar de seguir. */
class CompetitorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CompetitorService $competitors) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);

        return Inertia::render('Competitors/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'has_instagram' => $brand ? $this->competitors->queryAccount($request->user()->id, $brand->id) !== null : false,
            'comparison' => $brand ? $this->competitors->comparison($request->user(), $brand) : null,
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
}
