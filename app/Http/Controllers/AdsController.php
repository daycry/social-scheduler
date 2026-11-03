<?php

namespace App\Http\Controllers;

use App\Services\AdsService;
use App\Support\CurrentBrand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Analytics · Meta Ads, solo lectura (PA-11): cuentas publicitarias, campañas y métricas básicas por periodo. */
class AdsController extends Controller
{
    public function __construct(private readonly AdsService $ads) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'ad_account' => ['nullable', 'string', 'regex:/^act_\d{1,30}$/'],
            'days' => ['nullable', 'integer', 'in:'.implode(',', AdsService::PERIODS)],
        ]);
        $brands = CurrentBrand::brands($request);
        $brand = ! empty($data['brand_id']) ? ($brands->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : CurrentBrand::resolve($request);

        return Inertia::render('Ads/Index', [
            'brand' => $brand?->only(['id', 'name']),
            'overview' => $this->ads->overview($request->user(), $brand, $data['ad_account'] ?? null, (int) ($data['days'] ?? 30)),
            'periods' => AdsService::PERIODS,
        ]);
    }
}
