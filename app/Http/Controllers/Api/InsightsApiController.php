<?php

namespace App\Http\Controllers\Api;

use App\Connectors\Network;
use App\Services\BestTimesService;
use App\Services\CompetitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mejores horas y competidores de la API v1 (los mismos servicios que `get_best_times` y `compare_competitors` del MCP). */
class InsightsApiController extends ApiController
{
    public function bestTimes(Request $request, int $brand, BestTimesService $bestTimes): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $data = $request->validate(['network' => ['required', Rule::in(Network::availableValues())], 'days' => ['nullable', 'integer', 'min:7', 'max:365']]);

        return $this->respond($request, 'get_best_times', 'brand', $brand->id, fn () => $bestTimes->heatmap($request->user(), $brand, Network::from($data['network']), $data['days'] ?? null));
    }

    public function competitors(Request $request, int $brand, CompetitorService $competitors): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(CompetitorService::SORTS)],
            'network' => ['nullable', 'string', 'max:20'],
            'top_posts' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        return $this->respond($request, 'compare_competitors', 'brand', $brand->id, fn () => $competitors->summary($request->user(), $brand, $data));
    }
}
