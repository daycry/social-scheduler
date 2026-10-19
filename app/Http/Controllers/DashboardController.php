<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\Brand;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Dashboards de analitica por marca (T-37): Summary (tarjetas con total y desglose por red + linea temporal) y
 * pagina por red (crecimiento, visibilidad, interacciones, KPIs y publicaciones). Marca, periodo y comparacion
 * viajan en la query. Solo ve marcas propias: una ajena responde 404 como si no existiera.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(Request $request): Response
    {
        [$brands, $brand, $period, $compare, $filters] = $this->context($request);

        return Inertia::render('Dashboard/Index', [
            'brands' => $brands,
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'filters' => $filters,
            'summary' => $brand ? $this->analytics->summary($request->user(), $brand, $period, $compare) : null,
        ]);
    }

    public function network(Request $request, string $network): Response
    {
        $net = Network::tryFrom($network) ?? abort(404);
        [$brands, $brand, $period, $compare, $filters] = $this->context($request);

        return Inertia::render('Dashboard/Network', [
            'brands' => $brands,
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'filters' => $filters,
            'networks' => $brand ? $this->analytics->connectedNetworks($request->user(), $brand) : [],
            'detail' => $brand ? $this->analytics->network($request->user(), $brand, $net, $period, $compare) : null,
        ]);
    }

    /** @return array{0: list<array{id:int,name:string}>, 1: ?Brand, 2: Period, 3: ?Period, 4: array<string, mixed>} */
    private function context(Request $request): array
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'in:'.implode(',', Period::COMPARE_MODES)],
        ]);

        // El UserScope limita a las marcas del usuario: una marca ajena no existe para el.
        $all = Brand::query()->orderBy('name')->get();
        $brand = ! empty($data['brand_id']) ? ($all->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : $all->first();

        try {
            $period = Period::make($data['from'] ?? null, $data['to'] ?? null, $brand?->timezone);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $mode = $data['compare'] ?? 'none';

        return [
            $all->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            $brand,
            $period,
            $period->comparison($mode),
            ['brand_id' => $brand?->id, 'from' => $period->toArray()['from'], 'to' => $period->toArray()['to'], 'compare' => $mode],
        ];
    }
}
