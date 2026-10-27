<?php

namespace App\Http\Controllers;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Models\CalendarView;
use App\Models\PostTarget;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use App\Services\CalendarService;
use App\Services\CalendarViewService;
use App\Support\CsvWriter;
use App\Support\CurrentBrand;
use Carbon\Carbon;
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

    /** Cabeceras en espanol de las publicaciones. */
    private const POSTS_HEADER = ['fecha', 'marca', 'red', 'cuenta', 'tipo', 'estado', 'texto', 'enlace', 'visualizaciones', 'alcance', 'me_gusta', 'comentarios', 'compartidos', 'guardados', 'interacciones'];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AggregateAnalytics $aggregate,
        private readonly CalendarService $calendar,
        private readonly CalendarViewService $views,
    ) {}

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
     * Publicaciones de la vista Lista: las del filtro activo (o de la vista guardada) con las metricas de su ultimo snapshot.
     * Se recorren en streaming y el CSV se corta en `social.export.max_rows` filas; el recorte se anuncia en las cabeceras
     * `X-Export-Truncated` y `X-Export-Total` (y la lista avisa antes de descargar).
     */
    public function posts(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'brand_id' => ['nullable', 'integer'],
            'network' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:100'],
            'saved_view' => ['nullable', 'integer'],
        ]);
        $user = $request->user();

        if (! empty($filters['saved_view'])) {
            // Misma regla que el calendario: la vista guardada manda sobre marca, red y estado de la URL; la fecha se conserva.
            $saved = CalendarView::query()->findOrFail((int) $filters['saved_view']);
            $filters = array_merge(array_diff_key($filters, array_flip(['brand_id', 'network', 'status'])), $this->views->filtersFor($user, $saved));
        }

        try {
            $export = $this->calendar->exportQuery($user, $filters);
            $summary = $this->calendar->exportSummary($user, $filters);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $max = $summary['max'];
        $rows = (function () use ($export, $max) {
            $count = 0;

            // Una fila por destino; `lazy` lee por trozos y solo hidrata uno a la vez (sin `with`, sin N+1).
            foreach ($export['query']->lazy(500) as $t) {
                if ($count++ >= $max) {
                    return;
                }

                yield $this->postRow($t);
            }
        })();

        $response = $this->download('publicaciones-'.($filters['date'] ?? now()->format('Y-m-d')).'.csv', self::POSTS_HEADER, $rows);
        $response->headers->set('X-Export-Total', (string) $summary['total']);
        $response->headers->set('X-Export-Truncated', $summary['truncated'] ? '1' : '0');

        return $response;
    }

    /** @return list<mixed> */
    private function postRow(PostTarget $t): array
    {
        $raw = $t->getAttribute('latest_metrics');
        $metrics = Metric::clean(is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw, Metric::POST);
        $scheduled = $t->getAttribute('post_scheduled_at');
        $when = $scheduled ? Carbon::parse($scheduled, 'UTC')->setTimezone($t->getAttribute('brand_timezone') ?: 'UTC')->format('Y-m-d H:i') : '';

        return [
            $when, $t->getAttribute('brand_name'), $t->network->label(), $t->getAttribute('account_name'), $t->content_type->label(), $t->status->label(),
            (string) ($t->text ?? $t->getAttribute('post_text')), $t->external_url,
            $metrics[Metric::VIEWS], $metrics[Metric::REACH], $metrics[Metric::LIKES], $metrics[Metric::COMMENTS], $metrics[Metric::SHARES], $metrics[Metric::SAVED], $metrics[Metric::INTERACTIONS],
        ];
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
