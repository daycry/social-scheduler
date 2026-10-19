<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrandRequest;
use App\Models\Brand;
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
            'brands' => $this->brands->list($request->user()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Brand::class);

        return Inertia::render('Brands/Form', ['brand' => null]);
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

    public function edit(Brand $brand): Response
    {
        $this->authorize('update', $brand);

        return Inertia::render('Brands/Form', ['brand' => $brand]);
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
}
