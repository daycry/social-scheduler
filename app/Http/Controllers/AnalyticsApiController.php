<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\Brand;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AnalyticsApiController extends Controller
{
    public function __invoke(Request $request, Brand $brand, AnalyticsService $analytics): JsonResponse
    {
        abort_unless((int) $brand->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'],
            'network' => ['nullable', Rule::enum(Network::class)], 'compare' => ['nullable', Rule::in(Period::COMPARE_MODES)]]);
        try {
            $period = Period::make($data['from'], $data['to'], $brand->timezone);
            $comparison = $period->comparison($data['compare'] ?? 'none');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['from' => [$e->getMessage()]]);
        }
        $result = isset($data['network']) ? $analytics->network($request->user(), $brand, Network::from($data['network']), $period, $comparison)
            : $analytics->summary($request->user(), $brand, $period, $comparison);

        return response()->json(['brand_id' => $brand->id, 'brand_name' => $brand->name] + $result);
    }
}
