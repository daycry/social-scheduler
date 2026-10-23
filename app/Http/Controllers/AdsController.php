<?php

namespace App\Http\Controllers;

use App\Services\AdsService;
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
            'ad_account' => ['nullable', 'string', 'regex:/^act_\d{1,30}$/'],
            'days' => ['nullable', 'integer', 'in:'.implode(',', AdsService::PERIODS)],
        ]);

        return Inertia::render('Ads/Index', [
            'overview' => $this->ads->overview($request->user(), $data['ad_account'] ?? null, (int) ($data['days'] ?? 30)),
            'periods' => AdsService::PERIODS,
        ]);
    }
}
