<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Services\BrandService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly BrandService $brands) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Brand::class);

        return Inertia::render('Brands/Index', [
            'brands' => $this->brands->list($request->user())->map(fn (Brand $b) => [...$b->toArray(), 'avatar_url' => $this->avatarUrl($b)])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Brand::class);

        return Inertia::render('Brands/Form', ['brand' => null, 'images' => $this->images($request)]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $this->authorize('create', Brand::class);
        $this->brands->create($request->user(), $request->validated());

        return redirect()->route('brands.index');
    }

    public function show(Brand $brand): Response
    {
        $this->authorize('view', $brand);

        return Inertia::render('Brands/Show', ['brand' => $brand]);
    }

    public function edit(Request $request, Brand $brand): Response
    {
        $this->authorize('update', $brand);

        return Inertia::render('Brands/Form', ['brand' => $brand, 'images' => $this->images($request), 'avatar_url' => $this->avatarUrl($brand)]);
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);
        $this->brands->update($brand, $request->validated());

        return redirect()->route('brands.index');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorize('delete', $brand);
        $this->brands->delete($brand);

        return redirect()->route('brands.index');
    }

    /** Imagenes listas del usuario que puede elegir como avatar. */
    private function images(Request $request): array
    {
        return MediaAsset::query()->where('kind', MediaAsset::KIND_IMAGE)->where('status', MediaAsset::STATUS_READY)
            ->latest()->limit(50)->get()->map(fn (MediaAsset $m) => $m->toPublicArray())->values()->all();
    }

    /** URL temporal del avatar; si el disco no puede firmarla, la marca simplemente no muestra imagen. */
    private function avatarUrl(Brand $brand): ?string
    {
        if (! $brand->avatar_media_id) {
            return null;
        }

        try {
            return MediaAsset::query()->find($brand->avatar_media_id)?->publicUrl(60);
        } catch (\Throwable) {
            return null;
        }
    }
}
