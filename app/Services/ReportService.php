<?php

namespace App\Services;

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\PdfRenderer;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Informes por marca y rango de fechas (Fase 8). Reutiliza AnalyticsService (mismas reglas: `null` = "no
 * disponible", nunca 0) para armar un conjunto de datos unico que consumen el CSV y el PDF. Todo se resuelve
 * por el usuario indicado; los ficheros van al disco de media (`filesystems.media`) bajo `reports/{user_id}/`.
 */
class ReportService
{
    public const UNAVAILABLE = 'no disponible';

    public function __construct(private readonly AnalyticsService $analytics, private readonly PdfRenderer $pdf) {}

    /** Crea el registro pendiente (la generacion la hace el job `GenerateReport` en la cola `reports`). */
    public function create(User $user, Brand $brand, string $format, Period $period, string $compare = 'none'): Report
    {
        if ($brand->user_id !== $user->id) {
            throw new InvalidArgumentException('La marca no pertenece al usuario.');
        }

        if (! in_array($format, Report::FORMATS, true)) {
            throw new InvalidArgumentException('Formato de informe no soportado.');
        }

        if (! in_array($compare, Period::COMPARE_MODES, true)) {
            throw new InvalidArgumentException('Modo de comparacion no valido.');
        }

        return Report::withoutUserScope()->create([
            'user_id' => $user->id, 'brand_id' => $brand->id, 'format' => $format,
            'from' => $period->from->format('Y-m-d'), 'to' => $period->to->format('Y-m-d'),
            'compare' => $compare, 'status' => Report::STATUS_PENDING,
        ]);
    }

    /** Genera el fichero, lo guarda en el disco de media y marca el informe `ready` (o `failed`). */
    public function generate(Report $report): Report
    {
        $report->update(['status' => Report::STATUS_PROCESSING, 'error' => null]);

        try {
            $user = User::findOrFail($report->user_id);
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($report->brand_id);
            $period = Period::make($report->from->format('Y-m-d'), $report->to->format('Y-m-d'));
            $data = $this->dataset($user, $brand, $period, $period->comparison($report->compare));

            $content = $report->format === 'pdf' ? $this->pdf->render($data) : $this->csv($data);
            $path = sprintf('reports/%d/%d.%s', $user->id, $report->id, $report->format);
            Storage::disk(config('filesystems.media'))->put($path, $content);

            $report->update([
                'status' => Report::STATUS_READY, 'path' => $path, 'size' => strlen($content), 'generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $report->update(['status' => Report::STATUS_FAILED, 'error' => mb_strimwidth($e->getMessage(), 0, 400, '...')]);
        }

        return $report;
    }

    /**
     * Conjunto de datos del informe: resumen (tarjetas con comparacion), desglose por red y publicaciones destacadas.
     *
     * @return array<string, mixed>
     */
    public function dataset(User $user, Brand $brand, Period $period, ?Period $compare = null): array
    {
        $summary = $this->analytics->summary($user, $brand, $period, $compare);
        $top = max(1, (int) config('reports.top_posts', 5));
        $networks = [];
        $notes = [];

        foreach ($summary['networks'] as $n) {
            $detail = $this->analytics->network($user, $brand, Network::from($n['value']), $period, $compare);
            $posts = array_slice(array_values(array_filter($detail['posts'], fn ($p) => $p['has_data'])), 0, $top);

            foreach ($detail['metrics'] as $m) {
                if (! $m['available']) {
                    $notes[] = ['network' => $n['label'], 'metric' => $m['label']];
                }
            }

            $networks[] = [
                'value' => $n['value'], 'label' => $n['label'], 'accounts' => $detail['accounts'],
                'metrics' => $detail['metrics'],
                'engagement_rate' => $detail['kpis']['engagement_rate'],
                'posts' => $posts,
            ];
        }

        return [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'period' => $summary['period'],
            'comparison' => $summary['comparison'],
            'generated_at' => now()->toIso8601String(),
            'cards' => $summary['cards'],
            'timeline' => $this->timeline($summary['cards']),
            'networks' => $networks,
            'notes' => $notes,
        ];
    }

    /** Serie diaria de visualizaciones (para el grafico del PDF); vacia si no hay dato. */
    private function timeline(array $cards): array
    {
        foreach ($cards as $c) {
            if ($c['key'] === Metric::VIEWS && $c['available']) {
                return ['label' => $c['label'], 'points' => array_map(fn ($p) => ['date' => $p['date'], 'value' => $p['total']], $c['series'])];
            }
        }

        return ['label' => null, 'points' => []];
    }

    /** CSV (UTF-8 con BOM para Excel) en formato largo; las celdas de texto se neutralizan contra inyeccion de formulas. */
    public function csv(array $data): string
    {
        $rows = [['seccion', 'red', 'elemento', 'metrica', 'fecha', 'valor', 'anterior', 'variacion_pct']];
        $rows[] = ['informe', '', $data['brand']['name'], 'periodo', $data['period']['from'].' / '.$data['period']['to'], '', '', ''];

        foreach ($data['cards'] as $c) {
            $rows[] = ['resumen', 'todas', '', $c['label'], '', $this->num($c['total']), $this->num($c['previous']), $this->num($c['delta_pct'])];

            foreach ($c['by_network'] as $b) {
                $rows[] = ['resumen', $b['label'], '', $c['label'], '', $this->num($b['value']), $this->num($b['previous']), $this->num($b['delta_pct'])];
            }
        }

        foreach ($data['networks'] as $n) {
            foreach ($n['metrics'] as $m) {
                $rows[] = ['red', $n['label'], '', $m['label'], '', $this->num($m['total']), $this->num($m['previous']), $this->num($m['delta_pct'])];

                foreach ($m['series'] as $p) {
                    $rows[] = ['serie', $n['label'], '', $m['label'], $p['date'], $this->num($p['value']), '', ''];
                }
            }

            foreach ($n['posts'] as $p) {
                foreach ([Metric::VIEWS, Metric::REACH, Metric::INTERACTIONS] as $key) {
                    $rows[] = ['publicacion', $n['label'], (string) ($p['text'] ?? ''), $key, substr((string) $p['published_at'], 0, 10), $this->num($p['metrics'][$key] ?? null), '', ''];
                }
            }
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return str_replace("\r\n", "\n", (string) $csv);
    }

    /** Neutraliza celdas que Excel/Sheets interpretarian como formula (=, +, -, @, tab, CR). Los numeros no se tocan. */
    public static function cell(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    private function num(int|float|null $value): int|float|string
    {
        return $value ?? self::UNAVAILABLE;
    }
}
