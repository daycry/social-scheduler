<?php

namespace App\Http\Controllers\Api;

use App\Models\Site;
use App\Services\Sites\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sitios publicados de la API v1 (el mismo servicio que `manage_site` del MCP). El contenido se edita en la app: aqui solo se lee y se
 * publica o despublica. Una URL con token de acceso nunca se devuelve.
 */
class SiteApiController extends ApiController
{
    public function __construct(private readonly SiteService $sites) {}

    public function index(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);

        return $this->respond($request, 'list_sites', 'brand', $brand->id, fn () => ['sites' => Site::withoutUserScope()->where('user_id', $request->user()->id)
            ->where('brand_id', $brand->id)->orderBy('id')->get()->map(fn (Site $s) => $this->sites->present($s))->all()]);
    }

    public function show(Request $request, int $site): JsonResponse
    {
        $found = $this->owned($request, $site);

        return $this->respond($request, 'get_site', 'site', $found->id, fn () => $this->sites->present($found));
    }

    public function publish(Request $request, int $site): JsonResponse
    {
        $found = $this->owned($request, $site);

        return $this->respond($request, 'publish_site', 'site', $found->id, fn () => $this->sites->present($this->sites->publish($request->user(), $found)));
    }

    public function unpublish(Request $request, int $site): JsonResponse
    {
        $found = $this->owned($request, $site);

        return $this->respond($request, 'unpublish_site', 'site', $found->id, fn () => $this->sites->present($this->sites->unpublish($request->user(), $found)));
    }

    private function owned(Request $request, int $site): Site
    {
        return Site::withoutUserScope()->where('user_id', $request->user()->id)->find($site) ?? abort(404);
    }
}
