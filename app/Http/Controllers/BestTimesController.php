<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Services\BestTimesService;
use App\Support\CurrentBrand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Mejores horas (PA-05): mapa de calor por red de la marca elegida. */
class BestTimesController extends Controller
{
    public function __construct(private readonly BestTimesService $bestTimes) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'network' => ['nullable', 'in:'.implode(',', array_column(Network::cases(), 'value'))],
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        $brands = CurrentBrand::brands($request);
        // Una marca ajena responde 404 como si no existiera.
        $brand = ! empty($data['brand_id']) ? ($brands->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : CurrentBrand::resolve($request);
        $network = Network::from($data['network'] ?? Network::Instagram->value);

        return Inertia::render('BestTimes/Index', [
            'brands' => $brands->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'networks' => array_map(fn (Network $n) => ['value' => $n->value, 'label' => $n->label()], Network::cases()),
            'network' => $network->value,
            'days' => $data['days'] ?? null,
            'days_labels' => BestTimesService::DAYS,
            'result' => $brand ? $this->bestTimes->heatmap($request->user(), $brand, $network, $data['days'] ?? null) : null,
        ]);
    }
}
