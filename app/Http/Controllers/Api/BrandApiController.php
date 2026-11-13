<?php

namespace App\Http\Controllers\Api;

use App\Models\Brand;
use App\Services\AccountPresenter;
use App\Services\BrandService;
use App\Services\SocialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Marcas y cuentas conectadas de la API v1 (las mismas lecturas que `list_brands` y `list_accounts` del MCP). */
class BrandApiController extends ApiController
{
    public function index(Request $request, BrandService $brands): JsonResponse
    {
        return $this->respond($request, 'list_brands', 'brand', null, fn () => ['brands' => $brands->list($request->user())
            ->map(fn (Brand $b) => $this->present($b))->values()->all()]);
    }

    public function show(Request $request, int $brand): JsonResponse
    {
        return $this->respond($request, 'get_brand', 'brand', $brand, fn () => $this->present($this->ownedBrand($request, $brand)));
    }

    public function accounts(Request $request, int $brand, SocialAccountService $accounts, AccountPresenter $presenter): JsonResponse
    {
        return $this->respond($request, 'list_accounts', 'brand', $brand, function () use ($request, $brand, $accounts, $presenter) {
            $brand = $this->ownedBrand($request, $brand);

            return ['brand_id' => $brand->id, 'brand_name' => $brand->name, 'accounts' => $accounts->listForBrand($request->user(), $brand)
                ->map(fn ($a) => $presenter->present($a))->values()->all()];
        });
    }

    /** @return array<string, mixed> */
    private function present(Brand $b): array
    {
        return ['id' => $b->id, 'name' => $b->name, 'description' => $b->description, 'timezone' => $b->timezone];
    }
}
