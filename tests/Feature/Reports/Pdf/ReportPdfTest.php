<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Reports\PdfRenderer;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Aipocalipsis']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    foreach (range(1, 10) as $i) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $this->ig->id,
            'network' => 'instagram', 'date' => Carbon::parse('2026-11-01')->subDays($i)->format('Y-m-d'),
            'metrics' => array_merge(Metric::blankAccount(), [Metric::FOLLOWERS => 100 + $i, Metric::VIEWS => 10 * $i]),
        ]);
    }
});

it('ReportPdf: genera un PDF no vacio con portada, KPIs y notas (CA-11)', function () {
    $service = app(ReportService::class);
    $report = $service->generate($service->create($this->user, $this->brand, 'pdf', Period::make('2026-10-20', '2026-10-31'), 'previous'));

    expect($report->status)->toBe('ready');
    $pdf = Storage::disk('local')->get($report->path);
    expect(strlen($pdf))->toBeGreaterThan(2000)->and(substr($pdf, 0, 5))->toBe('%PDF-')->and($report->size)->toBe(strlen($pdf));
});

it('ReportPdf: la vista incluye marca, KPI, comparacion y nota de metrica no disponible', function () {
    $service = app(ReportService::class);
    $period = Period::make('2026-10-20', '2026-10-31');
    $data = $service->dataset($this->user, $this->brand, $period, $period->comparison('previous'));
    $html = view('reports.brand', ['data' => $data, 'branding' => ['color' => '#112233', 'logo' => null, 'footer' => ''], 'sections' => PdfRenderer::SECTIONS])->render();

    expect($html)->toContain('Aipocalipsis')->toContain('#112233')->toContain('Visualizaciones')
        ->toContain('Notas sobre las metricas')->toContain('Instagram: Cuentas que interactuaron')->toContain('No disponible');
});

it('ReportPdf: un fallo del render deja el informe en failed sin romper', function () {
    config(['reports.driver' => 'chromium']);
    $service = app(ReportService::class);
    $report = $service->generate($service->create($this->user, $this->brand, 'pdf', Period::make('2026-10-20', '2026-10-31')));

    expect($report->status)->toBe('failed')->and($report->error)->toContain('Driver de PDF no soportado');
});
