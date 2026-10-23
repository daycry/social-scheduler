<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => '=HYPERLINK("x")']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    foreach (range(1, 5) as $i) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $this->ig->id,
            'network' => 'instagram', 'date' => Carbon::parse('2026-11-01')->subDays($i)->format('Y-m-d'),
            'metrics' => array_merge(Metric::blankAccount(), [Metric::FOLLOWERS => 100 + $i, Metric::VIEWS => 10]),
        ]);
    }
});

function csvData(float $rate, bool $comparison): array
{
    return [
        'brand' => ['id' => 1, 'name' => 'M'], 'period' => ['from' => '2026-10-01', 'to' => '2026-10-02', 'days' => 2],
        'comparison' => $comparison ? ['from' => '2026-09-29', 'to' => '2026-09-30', 'days' => 2] : null,
        'cards' => [['label' => 'Seguidores', 'total' => 10, 'previous' => null, 'delta_pct' => null, 'by_network' => []]],
        'networks' => [['label' => 'Instagram', 'metrics' => [], 'engagement_rate' => $rate, 'posts' => []]],
        'notes' => [],
    ];
}

function generateReport(User $user, Brand $brand, string $format = 'csv'): Report
{
    $service = app(ReportService::class);

    return $service->generate($service->create($user, $brand, $format, Period::make('2026-10-25', '2026-10-31')));
}

it('ReportCsv: genera un CSV con los datos del rango en el disco de media (CA-11)', function () {
    $report = generateReport($this->user, $this->brand);

    expect($report->status)->toBe('ready')->and($report->path)->toBe("reports/{$this->user->id}/{$report->id}.csv");
    $csv = Storage::disk('local')->get($report->path);
    expect($csv)->toStartWith("\xEF\xBB\xBF\"seccion\";\"red\";\"elemento\";\"metrica\";\"fecha\";\"valor\";\"anterior\";\"variacion_pct\"\n")
        ->and($csv)->toContain('"resumen";"Instagram";"";"Visualizaciones";"";50;')
        ->and($csv)->toContain('2026-10-31')
        ->and($csv)->toContain('"no disponible"'); // lo que la red no da nunca es 0
});

it('ReportCsv: neutraliza la inyeccion de formulas (=, +, -, @)', function () {
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTAB"] as $payload) {
        expect(ReportService::cell($payload))->toBe("'".$payload);
    }
    expect(ReportService::cell(-5))->toBe(-5)->and(ReportService::cell('normal'))->toBe('normal');

    $csv = Storage::disk('local')->get(generateReport($this->user, $this->brand)->path);
    expect($csv)->toContain('"informe";"";"\'=HYPERLINK(""x"")";"periodo"');
    expect($csv)->not->toContain(';=HYPERLINK')->not->toContain('"=');
});

it('ReportCsv: la inyeccion con ; no se cuela en Excel es-ES (CWE-1236)', function () {
    $payload = "x;=cmd|'/Ccalc'!A0;";
    expect(ReportService::cell($payload))->toBe("x;'=cmd|'/Ccalc'!A0;")
        ->and(ReportService::cell('a, -b'))->toBe("a, '-b")
        ->and(ReportService::cell("a\t@b"))->toBe("a\t'@b");

    $this->brand->update(['name' => $payload]);
    $csv = Storage::disk('local')->get(generateReport($this->user, $this->brand)->path);

    // Con la celda entrecomillada el `;` no la parte; y aunque otro programa la partiera, ningun tramo empieza por =.
    expect($csv)->toContain('"informe";"";"x;\'=cmd|\'/Ccalc\'!A0;";"periodo"');

    foreach (explode("\n", trim(substr($csv, 3))) as $line) {
        $cells = str_getcsv($line, ';', '"', '');
        expect($cells)->toHaveCount(8);

        foreach ($cells as $cell) {
            foreach (preg_split('/[;,\t]/', $cell) as $segment) {
                expect(ltrim($segment))->not->toStartWith('=')->not->toStartWith('@');
            }
        }
    }
});

it('ReportCsv: separador y decimal segun locale y texto siempre entre comillas', function () {
    $service = app(ReportService::class);
    expect($service->csv(csvData(0.5, comparison: true)))->toContain('"Tasa de interaccion (%)";"";0,5;');

    config(['reports.csv_locale' => 'en']);
    $csv = $service->csv(csvData(0.5, comparison: true));
    expect($csv)->toContain('"Tasa de interaccion (%)","",0.5,')->and($csv)->not->toContain(';');
});

it('ReportCsv: distingue "sin comparacion" (vacio) de "no disponible"', function () {
    $service = app(ReportService::class);
    $without = $service->csv(csvData(1.5, comparison: false));
    $with = $service->csv(csvData(1.5, comparison: true));

    expect($without)->toContain('"resumen";"todas";"";"Seguidores";"";10;"";""')
        ->and($with)->toContain('"resumen";"todas";"";"Seguidores";"";10;"no disponible";"no disponible"');
});

it('ReportCsv: incluye tasa de interaccion y notas de metricas no disponibles', function () {
    $csv = Storage::disk('local')->get(generateReport($this->user, $this->brand)->path);

    expect($csv)->toContain('"Tasa de interaccion (%)"')->toContain('"nota";"Instagram"')->toContain('"Cuentas que interactuaron"');
});

it('ReportCsv: aislamiento, no se crea informe de una marca ajena', function () {
    $other = User::factory()->create();

    expect(fn () => app(ReportService::class)->create($other, $this->brand, 'csv', Period::make(null, null)))
        ->toThrow(InvalidArgumentException::class);
    expect(Report::withoutUserScope()->count())->toBe(0);
});

it('ReportCsv: el informe de un usuario no es visible para otro', function () {
    $report = generateReport($this->user, $this->brand);
    $this->actingAs(User::factory()->create());

    expect(Report::query()->count())->toBe(0)->and(Report::find($report->id))->toBeNull();
});
