<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\Brand;
use App\Services\AnalyticsService;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsApiController extends Controller
{
    public function __invoke(Request $request, Brand $brand, AnalyticsService $analytics): JsonResponse
    {
        Ownership::assert($brand, $request->user());
        $period = Period::fromInput($request->only(['from', 'to']), $brand->timezone, required: true);
        $data = $request->validate(['network' => ['nullable', Rule::enum(Network::class)], 'compare' => ['nullable', Rule::in(Period::COMPARE_MODES)]]);
        $comparison = $period->inputComparison($data['compare'] ?? 'none');
        $result = isset($data['network']) ? $analytics->network($request->user(), $brand, Network::from($data['network']), $period, $comparison)
            : $analytics->summary($request->user(), $brand, $period, $comparison);

        return response()->json(['brand_id' => $brand->id, 'brand_name' => $brand->name] + $result);
    }
}
