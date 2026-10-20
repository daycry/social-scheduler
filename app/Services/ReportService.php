<?php

namespace App\Services;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\GenerateReport;
use App\Models\Brand;
use App\Models\PostTarget;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\TooManyActiveReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Informes por marca y rango de fechas (Fase 8). Reutiliza AnalyticsService (mismas reglas: `null` = "no
 * disponible", nunca 0) para armar un conjunto de datos unico que consumen el CSV y el PDF. Todo se resuelve
 * por el usuario indicado; los ficheros van al disco de media (`filesystems.media`) bajo `reports/{user_id}/`.
 */
class ReportService
{
    public const UNAVAILABLE = 'no disponible';

    /** Lo unico que ve el usuario cuando falla la generacion; el detalle va al log. */
    public const GENERIC_ERROR = 'No se pudo generar el informe. Inténtalo de nuevo.';

    public const STUCK_ERROR = 'El informe no se completó a tiempo. Genera uno nuevo.';

    public function __construct(private readonly AnalyticsService $analytics, private readonly PdfRenderer $pdf) {}

    /**
     * Crea los informes pendientes de los formatos pedidos respetando el limite de informes en curso de forma atomica
     * (transaccion + bloqueo de la fila del usuario) y encola su generacion en la cola `reports`.
     *
     * @param  list<string>  $formats
     * @return list<Report>
     *
     * @throws TooManyActiveReports
     */
    public function enqueue(User $user, Brand $brand, array $formats, Period $period, string $compare = 'none'): array
    {
        $formats = array_values(array_unique($formats));
        $max = (int) config('reports.max_active', 5);

        $reports = DB::transaction(function () use ($user, $brand, $formats, $period, $compare, $max) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(); // serializa las altas del mismo usuario

            $active = Report::withoutUserScope()->where('user_id', $user->id)->active()->count();

            if ($active + count($formats) > $max) {
                throw new TooManyActiveReports;
            }

            return array_map(fn (string $format) => $this->create($user, $brand, $format, $period, $compare), $formats);
        });

        foreach ($reports as $report) {
            GenerateReport::dispatch($report->id);
        }

        return $reports;
    }

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

        $period->comparison($compare); // valida el rango de comparacion ya (lanza InvalidArgumentException)

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

            if (Storage::disk(config('filesystems.media'))->put($path, $content) === false) {
                throw new RuntimeException("No se pudo escribir el informe en el disco ({$path}).");
            }

            $report->update([
                'status' => Report::STATUS_READY, 'path' => $path, 'size' => strlen($content), 'generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // El detalle (rutas, SQL, excepciones del render) va al log; el usuario solo ve un mensaje generico.
            Log::error('report.generate_failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);
            report($e);
            $report->update(['status' => Report::STATUS_FAILED, 'error' => self::GENERIC_ERROR]);
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
        $top = $this->topPosts($user, $brand, $period, max(1, (int) config('reports.top_posts', 5)));
        $networks = [];
        $notes = [];

        foreach ($summary['networks'] as $n) {
            $detail = $this->analytics->network($user, $brand, Network::from($n['value']), $period, $compare);

            foreach ($detail['metrics'] as $m) {
                if (! $m['available']) {
                    $notes[] = ['network' => $n['label'], 'metric' => $m['label']];
                }
            }

            $networks[] = [
                'value' => $n['value'], 'label' => $n['label'], 'accounts' => $detail['accounts'],
                'metrics' => $detail['metrics'],
                'engagement_rate' => $detail['kpis']['engagement_rate'],
                'posts' => $top[$n['value']] ?? [],
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

    /**
     * Mejores publicaciones del PERIODO completo por red (no solo las ultimas que muestra el dashboard): una unica
     * consulta con el ultimo snapshot de cada publicacion publicada en el periodo, para todas las redes a la vez;
     * se ordena por interacciones y se recorta a las `$limit` mejores por red. Se lee en streaming y solo se
     * retienen los candidatos del top, asi la memoria no depende del numero de publicaciones.
     *
     * @return array<string, list<array<string, mixed>>> [red => publicaciones]
     */
    public function topPosts(User $user, Brand $brand, Period $period, int $limit): array
    {
        $networkByAccount = SocialAccount::withoutUserScope()
            ->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->get(['id', 'network'])->mapWithKeys(fn (SocialAccount $a) => [$a->id => $a->network->value])->all();

        if ($networkByAccount === []) {
            return [];
        }

        $latest = DB::table('post_metric_snapshots')->selectRaw('post_target_id, MAX(date) as last_date')
            ->where('user_id', $user->id)->groupBy('post_target_id');

        // Los dias del periodo son de la zona de la marca (como en el dashboard), no UTC.
        [$from, $to] = $period->utcBounds($brand->timezone);

        $rows = DB::table('post_targets as t')
            ->join('post_metric_snapshots as s', 's.post_target_id', '=', 't.id')
            ->joinSub($latest, 'l', fn ($j) => $j->on('l.post_target_id', '=', 's.post_target_id')->on('l.last_date', '=', 's.date'))
            ->where('t.user_id', $user->id)
            ->whereIn('t.social_account_id', array_keys($networkByAccount))
            ->where('t.status', PostStatus::Published->value)
            ->where('t.published_at', '>=', $from)->where('t.published_at', '<', $to)
            ->select('t.id', 't.social_account_id', 't.published_at', 's.metrics')
            ->cursor();

        $candidates = [];

        foreach ($rows as $row) {
            $metrics = Metric::clean((array) json_decode((string) $row->metrics, true), Metric::POST);

            if (Metric::isEmpty($metrics)) {
                continue;
            }

            $network = $networkByAccount[$row->social_account_id];
            $candidates[$network][] = ['id' => (int) $row->id, 'metrics' => $metrics, 'published_at' => (string) $row->published_at];

            if (count($candidates[$network]) > $limit * 4) {
                $candidates[$network] = $this->rank($candidates[$network], $limit);
            }
        }

        $ranked = array_map(fn (array $list) => $this->rank($list, $limit), $candidates);
        $ids = [];

        foreach ($ranked as $list) {
            array_push($ids, ...array_column($list, 'id'));
        }

        $targets = $ids === [] ? collect() : PostTarget::withoutUserScope()
            ->with(['post' => fn ($q) => $q->withoutGlobalScopes()])->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(fn (array $list) => array_map(function (array $c) use ($targets) {
            /** @var PostTarget $t */
            $t = $targets[$c['id']];

            return [
                'id' => $t->id,
                'text' => $t->post?->text ? mb_strimwidth($t->post->text, 0, 140, '...') : null,
                'content_type' => $t->content_type->value,
                'published_at' => $t->published_at?->toIso8601String(),
                'url' => $t->external_url,
                'metrics' => $c['metrics'],
                'has_data' => true,
                'engagement_rate' => Calculator::engagementRate($c['metrics'][Metric::INTERACTIONS], $c['metrics'][Metric::VIEWS]),
            ];
        }, $list), $ranked);
    }

    /**
     * Ordena por interacciones (las nulas al final), luego visualizaciones y fecha, y se queda con `$limit`.
     *
     * @param  list<array<string, mixed>>  $list
     * @return list<array<string, mixed>>
     */
    private function rank(array $list, int $limit): array
    {
        $key = fn (array $c) => [$c['metrics'][Metric::INTERACTIONS] ?? -1, $c['metrics'][Metric::VIEWS] ?? -1, $c['published_at'], $c['id']];

        usort($list, fn ($a, $b) => $key($b) <=> $key($a));

        return array_slice($list, 0, $limit);
    }

    /**
     * Serie de visualizaciones para el grafico del PDF; vacia si no hay dato. Los periodos largos se agregan por
     * semana para que las barras quepan en A4 (flujo: se suman los dias con dato).
     */
    private function timeline(array $cards): array
    {
        foreach ($cards as $c) {
            if ($c['key'] !== Metric::VIEWS || ! $c['available']) {
                continue;
            }

            $points = array_map(fn ($p) => ['date' => $p['date'], 'value' => $p['total']], $c['series']);
            $granularity = 'day';

            if (count($points) > (int) config('reports.chart_max_daily_points', 60)) {
                $granularity = 'week';
                $points = array_map(function (array $chunk) {
                    $values = array_filter(array_column($chunk, 'value'), fn ($v) => $v !== null);

                    return ['date' => $chunk[0]['date'], 'value' => $values === [] ? null : array_sum($values)];
                }, array_chunk($points, 7));
            }

            return ['label' => $c['label'], 'granularity' => $granularity, 'points' => $points];
        }

        return ['label' => null, 'granularity' => 'day', 'points' => []];
    }

    /**
     * CSV (UTF-8 con BOM para Excel) en formato largo. Separador y decimal segun `reports.csv_locale` (es: `;` y coma).
     * Las celdas de texto van SIEMPRE entre comillas y neutralizadas contra inyeccion de formulas (CWE-1236).
     * `anterior`/`variacion_pct` quedan vacios si no se pidio comparacion y valen "no disponible" si se pidio y no hay dato.
     */
    public function csv(array $data): string
    {
        [$delimiter, $decimal] = $this->csvFormat();
        $cmp = $data['comparison'] !== null;
        $prev = fn (int|float|null $v) => $cmp ? $this->num($v) : '';

        $rows = [['seccion', 'red', 'elemento', 'metrica', 'fecha', 'valor', 'anterior', 'variacion_pct']];
        $rows[] = ['informe', '', $data['brand']['name'], 'periodo', $data['period']['from'].' / '.$data['period']['to'], '', '', ''];

        foreach ($data['cards'] as $c) {
            $rows[] = ['resumen', 'todas', '', $c['label'], '', $this->num($c['total']), $prev($c['previous']), $prev($c['delta_pct'])];

            foreach ($c['by_network'] as $b) {
                $rows[] = ['resumen', $b['label'], '', $c['label'], '', $this->num($b['value']), $prev($b['previous']), $prev($b['delta_pct'])];
            }
        }

        foreach ($data['networks'] as $n) {
            foreach ($n['metrics'] as $m) {
                $rows[] = ['red', $n['label'], '', $m['label'], '', $this->num($m['total']), $prev($m['previous']), $prev($m['delta_pct'])];

                foreach ($m['series'] as $p) {
                    $rows[] = ['serie', $n['label'], '', $m['label'], $p['date'], $this->num($p['value']), '', ''];
                }
            }

            $rows[] = ['red', $n['label'], '', 'Tasa de interaccion (%)', '', $this->num($n['engagement_rate']), '', ''];

            foreach ($n['posts'] as $p) {
                $date = substr((string) $p['published_at'], 0, 10);

                foreach ([Metric::VIEWS, Metric::REACH, Metric::INTERACTIONS] as $key) {
                    $rows[] = ['publicacion', $n['label'], (string) ($p['text'] ?? ''), $key, $date, $this->num($p['metrics'][$key] ?? null), '', ''];
                }

                $rows[] = ['publicacion', $n['label'], (string) ($p['text'] ?? ''), 'tasa_interaccion_pct', $date, $this->num($p['engagement_rate']), '', ''];
            }
        }

        foreach ($data['notes'] as $note) {
            $rows[] = ['nota', $note['network'], 'La API de la red no ofrece esta metrica', $note['metric'], '', self::UNAVAILABLE, '', ''];
        }

        $csv = "\xEF\xBB\xBF";

        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(fn ($v) => $this->field($v, $decimal), $row))."\n";
        }

        return $csv;
    }

    /**
     * Neutraliza celdas que Excel/Sheets interpretarian como formula: un `'` delante si el texto (o cualquier tramo
     * tras `;`, `,`, TAB o salto de linea, por si el programa abre el CSV con otro separador) empieza por = + - @ TAB CR.
     * Los numeros no se tocan.
     */
    public static function cell(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) preg_replace('/(^|[;,\t\r\n])( *)(?=[=+\-@\t\r])/', "$1$2'", (string) $value);
    }

    /** @return array{0: string, 1: string} [separador, decimal] */
    private function csvFormat(): array
    {
        return config('reports.csv_locale', 'es') === 'en' ? [',', '.'] : [';', ','];
    }

    /** Numeros sin comillas (decimal del locale); todo lo demas es texto: siempre entre comillas, con `"` duplicadas y sin saltos de linea. */
    private function field(mixed $value, string $decimal): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $text = rtrim(rtrim(number_format($value, 4, $decimal, ''), '0'), $decimal);

            return $text === '' || $text === '-' ? '0' : $text;
        }

        $text = (string) preg_replace('/\R/u', ' ', (string) self::cell($value));

        return '"'.str_replace('"', '""', $text).'"';
    }

    private function num(int|float|null $value): int|float|string
    {
        return $value ?? self::UNAVAILABLE;
    }
}
