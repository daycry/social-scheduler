<?php

namespace App\Http\Controllers\Api;

use App\Models\SmartLink;
use App\Services\SmartLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SmartLink de la marca en la API v1: el mismo servicio, reglas y forma que la pagina de la web (uno por marca). */
class SmartLinkApiController extends ApiController
{
    public function __construct(private readonly SmartLinkService $links) {}

    public function show(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $link = $this->current($request, $brand->id) ?? abort(404);

        return $this->respond($request, 'get_smartlink', 'smartlink', $link->id, fn () => $this->links->present($link->load('blocks'), $brand));
    }

    /** Crea o reemplaza el microsite de la marca: los bloques con `id` conservan sus clics y los que no llegan se borran. */
    public function save(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $current = $this->current($request, $brand->id);
        $data = $request->validate(SmartLinkService::rules($current?->id), SmartLinkService::MESSAGES);

        return $this->respond($request, 'save_smartlink', 'smartlink', $current?->id, function () use ($request, $brand, $data) {
            $link = $this->links->save($request->user(), $brand, $data);

            return $this->links->present($link->load('blocks'), $brand);
        }, $current === null ? 201 : 200);
    }

    public function stats(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $link = $this->current($request, $brand->id) ?? abort(404);

        return $this->respond($request, 'get_smartlink_stats', 'smartlink', $link->id, fn () => $this->links->stats($link, $data['days'] ?? 30));
    }

    private function current(Request $request, int $brandId): ?SmartLink
    {
        return SmartLink::withoutUserScope()->where('user_id', $request->user()->id)->where('brand_id', $brandId)->first();
    }
}
