<?php

use App\Jobs\GenerateReport;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Marca A']);
});

function readyReport(User $user, Brand $brand, string $format = 'csv'): Report
{
    $r = Report::factory()->create(['user_id' => $user->id, 'brand_id' => $brand->id, 'format' => $format, 'status' => 'ready', 'path' => "reports/{$user->id}/x.{$format}", 'size' => 10]);
    Storage::disk('local')->put($r->path, $format === 'pdf' ? '%PDF-1.4 test' : 'a,b');

    return $r;
}

it('Reports: genera en cola reports con timeout < 120 (PDF y CSV)', function () {
    Bus::fake();

    $this->actingAs($this->user)->post(route('reports.store'), [
        'brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => 'previous', 'formats' => ['pdf', 'csv'],
    ])->assertRedirect(route('reports.index'));

    Bus::assertDispatched(GenerateReport::class, 2);
    expect(Report::count())->toBe(2)->and((new GenerateReport(1))->queue)->toBe('reports')->and((new GenerateReport(1))->timeout)->toBeLessThan(120);
});

it('Reports: el job genera el fichero (sync) y la lista ofrece descarga firmada', function () {
    $this->actingAs($this->user)->post(route('reports.store'), [
        'brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['csv'],
    ]);

    $this->actingAs($this->user)->get(route('reports.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Reports/Index')
            ->where('reports.0.status', 'ready')->where('reports.0.format', 'csv')
            ->where('reports.0.download_url', fn ($u) => str_contains($u, 'signature=')));
});

it('Reports: valida rango y formatos', function () {
    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-31', 'to' => '2026-10-01', 'formats' => ['csv']])->assertSessionHasErrors('from');
    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['exe']])->assertSessionHasErrors('formats.0');
});

it('Reports: descarga solo con enlace firmado y siendo el dueno', function () {
    $report = readyReport($this->user, $this->brand, 'pdf');

    $this->actingAs($this->user)->get(route('reports.download', $report))->assertForbidden(); // sin firma

    $url = URL::temporarySignedRoute('reports.download', now()->addMinutes(5), $report);
    $this->actingAs($this->user)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf')
        ->assertDownload('informe-marca-a-2026-10-01_2026-10-30.pdf');

    $old = URL::temporarySignedRoute('reports.download', now()->subMinute(), $report);
    $this->actingAs($this->user)->get($old)->assertForbidden(); // caducado

    auth()->logout();
    $this->get($url)->assertRedirect(route('login')); // sin sesion
});

it('Reports: aislamiento, otro usuario no ve ni descarga un informe ajeno ni genera de marca ajena', function () {
    $report = readyReport($this->user, $this->brand);
    $other = User::factory()->create();
    $url = URL::temporarySignedRoute('reports.download', now()->addMinutes(5), $report);

    $this->actingAs($other)->get($url)->assertNotFound(); // el UserScope lo oculta
    $this->actingAs($other)->get(route('reports.index'))->assertInertia(fn (Assert $p) => $p->has('reports', 0)->has('brands', 0));

    Bus::fake();
    $this->actingAs($other)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['csv']])->assertNotFound();
    Bus::assertNotDispatched(GenerateReport::class);
});

it('Reports: limita los informes en curso', function () {
    Bus::fake();
    Report::factory()->count(5)->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'status' => 'pending']);

    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['csv']])
        ->assertSessionHasErrors('formats');
});
