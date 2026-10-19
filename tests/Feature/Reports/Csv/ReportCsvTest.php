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

function generateReport(User $user, Brand $brand, string $format = 'csv'): Report
{
    $service = app(ReportService::class);

    return $service->generate($service->create($user, $brand, $format, Period::make('2026-10-25', '2026-10-31')));
}

it('ReportCsv: genera un CSV con los datos del rango en el disco de media (CA-11)', function () {
    $report = generateReport($this->user, $this->brand);

    expect($report->status)->toBe('ready')->and($report->path)->toBe("reports/{$this->user->id}/{$report->id}.csv");
    $csv = Storage::disk('local')->get($report->path);
    expect($csv)->toStartWith("\xEF\xBB\xBFseccion,red,elemento,metrica,fecha,valor,anterior,variacion_pct\n")
        ->and($csv)->toContain('resumen,Instagram,,Visualizaciones,,50,')
        ->and($csv)->toContain('2026-10-31')
        ->and($csv)->toContain('no disponible'); // lo que la red no da nunca es 0
});

it('ReportCsv: neutraliza la inyeccion de formulas (=, +, -, @)', function () {
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTAB"] as $payload) {
        expect(ReportService::cell($payload))->toBe("'".$payload);
    }
    expect(ReportService::cell(-5))->toBe(-5)->and(ReportService::cell('normal'))->toBe('normal');

    $csv = Storage::disk('local')->get(generateReport($this->user, $this->brand)->path);
    expect($csv)->toContain('informe,,"\'=HYPERLINK(""x"")",periodo');
    expect($csv)->not->toContain(',=HYPERLINK');
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
