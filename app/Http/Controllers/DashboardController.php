<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\Brand;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use App\Support\CurrentBrand;
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
    public function __construct(private readonly AnalyticsService $analytics, private readonly AggregateAnalytics $aggregate) {}

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

    /** «Todas las marcas» (C-03b): la analitica de todas las marcas del usuario sumada, con una fila por marca. */
    public function allBrands(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'in:'.implode(',', Period::COMPARE_MODES)],
        ]);

        try {
            // Sin marca no hay zona: los dias son dias UTC, como los snapshots.
            $period = Period::make($data['from'] ?? null, $data['to'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $mode = $data['compare'] ?? 'none';
        $brands = CurrentBrand::brands($request);

        return Inertia::render('Dashboard/AllBrands', [
            'brands' => $brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'filters' => ['brand_id' => null, 'from' => $period->toArray()['from'], 'to' => $period->toArray()['to'], 'compare' => $mode],
            'summary' => $brands->isEmpty() ? null : $this->aggregate->summary($request->user(), $period, $period->comparison($mode)),
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
        $all = CurrentBrand::brands($request);
        $brand = ! empty($data['brand_id']) ? ($all->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : CurrentBrand::resolve($request);

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
