<?php

namespace App\Http\Controllers;

use App\Analytics\Calculator;
use App\Analytics\Period;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use App\Support\CsvWriter;
use App\Support\CurrentBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportaciones CSV sincronas (C-03c): analitica de una marca o de todas, y publicaciones de la vista Lista. Se escriben fila a
 * fila (`streamDownload`) con `CsvWriter` (UTF-8 con BOM, separador del locale y neutralizacion CWE-1236) y sin acumular el
 * fichero. Lo que una red no ofrece sale como celda vacia (nunca 0). Solo ve datos del usuario: una marca ajena responde 404.
 */
class ExportController extends Controller
{
    /** Cabeceras en espanol de la analitica. */
    private const ANALYTICS_HEADER = ['marca', 'red', 'metrica', 'tipo', 'valor', 'anterior', 'variacion_pct'];

    public function __construct(private readonly AnalyticsService $analytics, private readonly AggregateAnalytics $aggregate) {}

    public function analytics(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'in:'.implode(',', Period::COMPARE_MODES)],
        ]);

        $user = $request->user();
        $brand = ! empty($data['brand_id']) ? (CurrentBrand::brands($request)->firstWhere('id', (int) $data['brand_id']) ?? abort(404)) : null;

        try {
            $period = Period::make($data['from'] ?? null, $data['to'] ?? null, $brand?->timezone);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $compare = $period->comparison($data['compare'] ?? 'none');
        $comparing = $compare !== null;
        $summary = $brand
            ? $this->analytics->summary($user, $brand, $period, $compare)
            : $this->aggregate->summary($user, $period, $compare);
        $scope = $brand?->name ?? 'Todas las marcas';

        $rows = (function () use ($summary, $scope, $comparing, $brand) {
            $cell = fn (int|float|null $v): int|float|null => $v; // null = celda vacia, nunca 0
            $prev = fn (int|float|null $v) => $comparing ? $cell($v) : null;
            $kinds = [];

            foreach ($summary['cards'] as $c) {
                $kinds[$c['key']] = $c['kind'] === 'stock' ? 'acumulado' : 'flujo';
                yield [$scope, 'Todas', $c['label'], $kinds[$c['key']], $cell($c['total']), $prev($c['previous']), $prev($c['delta_pct'])];

                foreach ($c['by_network'] as $n) {
                    yield [$scope, $n['label'], $c['label'], $kinds[$c['key']], $cell($n['value']), $prev($n['previous']), $prev($n['delta_pct'])];
                }
            }

            if ($brand === null) {
                // Una fila por marca y metrica (valores del periodo; la variacion se calcula como en el resto de la analitica).
                $labels = collect($summary['cards'])->pluck('label', 'key');
                foreach ($summary['brands'] as $b) {
                    foreach ($b['values'] as $key => $value) {
                        yield [$b['name'], 'Todas', $labels[$key], $kinds[$key], $cell($value), $prev($b['previous'][$key] ?? null), $prev(Calculator::delta($value, $b['previous'][$key] ?? null)['pct'])];
                    }
                }
            }
        })();

        $name = 'analitica-'.($brand ? Str::slug($brand->name) : 'todas-las-marcas').'-'.$period->from->format('Y-m-d').'-'.$period->to->format('Y-m-d').'.csv';

        return $this->download($name, self::ANALYTICS_HEADER, $rows);
    }

    /**
     * Respuesta en streaming: BOM, cabecera y una `line()` por fila.
     *
     * @param  list<string>  $header
     * @param  iterable<int, list<mixed>>  $rows
     */
    protected function download(string $name, array $header, iterable $rows): StreamedResponse
    {
        $writer = CsvWriter::forConfiguredLocale();

        return response()->streamDownload(function () use ($writer, $header, $rows) {
            echo CsvWriter::BOM.$writer->line($header);

            foreach ($rows as $row) {
                echo $writer->line($row);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
