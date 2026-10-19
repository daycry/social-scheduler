<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\GenerateReport;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\TooManyActiveReports;
use App\Services\ReportService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Marca A']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
});

function fixReport(array $attrs = []): Report
{
    return Report::factory()->create(array_merge(['user_id' => test()->user->id, 'brand_id' => test()->brand->id], $attrs));
}

it('ReportFix I-2: las publicaciones destacadas son las mejores del periodo completo (>50 publicaciones)', function () {
    // 60 publicaciones; la mejor (interacciones 9000) es la MAS ANTIGUA: el dashboard (50 mas recientes) no la veria.
    foreach (range(1, 60) as $i) {
        $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'text' => "Post {$i}"]);
        $t = PostTarget::factory()->create([
            'post_id' => $post->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram,
            'status' => PostStatus::Published, 'external_id' => "e{$i}", 'published_at' => Carbon::parse('2026-10-01')->addHours($i),
        ]);
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $this->ig->id,
            'network' => 'instagram', 'date' => '2026-10-20', 'metrics' => [Metric::VIEWS => 100, Metric::INTERACTIONS => $i === 1 ? 9000 : $i]]);
    }

    $top = app(ReportService::class)->topPosts($this->user, $this->brand, Period::make('2026-10-01', '2026-10-31'), 5);

    expect($top['instagram'])->toHaveCount(5)
        ->and($top['instagram'][0]['text'])->toBe('Post 1')->and($top['instagram'][0]['metrics']['interactions'])->toBe(9000)
        ->and($top['instagram'][1]['text'])->toBe('Post 60')
        ->and(array_column(array_column($top['instagram'], 'metrics'), 'interactions'))->toBe([9000, 60, 59, 58, 57]);

    $data = app(ReportService::class)->dataset($this->user, $this->brand, Period::make('2026-10-01', '2026-10-31'));
    expect($data['networks'][0]['posts'][0]['text'])->toBe('Post 1');
});

it('ReportFix M-4: rango o comparacion invalidos son error de validacion, no informe fallido', function () {
    Bus::fake();

    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2024-01-01', 'to' => '2026-10-31', 'formats' => ['csv']])
        ->assertSessionHasErrors('from');
    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => 'foo', 'formats' => ['csv']])
        ->assertSessionHasErrors('compare');

    expect(Report::withoutUserScope()->count())->toBe(0);
    Bus::assertNotDispatched(GenerateReport::class);
});

it('ReportFix M-6: el limite de 5 en curso lo aplica el servicio de forma atomica', function () {
    Bus::fake();
    $service = app(ReportService::class);
    $period = Period::make('2026-10-01', '2026-10-31');

    $service->enqueue($this->user, $this->brand, ['pdf', 'csv'], $period);
    $service->enqueue($this->user, $this->brand, ['pdf', 'csv'], $period);
    expect(fn () => $service->enqueue($this->user, $this->brand, ['pdf', 'csv'], $period))->toThrow(TooManyActiveReports::class);

    // Todo o nada: ocupa 4 de 5, uno mas cabe.
    expect(Report::withoutUserScope()->count())->toBe(4);
    $service->enqueue($this->user, $this->brand, ['csv'], $period);
    expect(Report::withoutUserScope()->count())->toBe(5);
    Bus::assertDispatchedTimes(GenerateReport::class, 5);
});

it('ReportFix M-7: un informe atascado no cuenta, se marca failed al purgar y se puede borrar con su fichero', function () {
    Bus::fake();
    $stuck = fixReport(['status' => 'pending']);
    $stuck->forceFill(['updated_at' => now()->subHours(5)])->saveQuietly();
    foreach (range(1, 4) as $_) {
        fixReport(['status' => 'pending']);
    }

    // 4 activos + 1 atascado (no cuenta): cabe uno nuevo.
    app(ReportService::class)->enqueue($this->user, $this->brand, ['csv'], Period::make('2026-10-01', '2026-10-31'));
    expect(Report::withoutUserScope()->count())->toBe(6);

    $this->artisan('reports:prune')->assertSuccessful();
    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->error)->toBe(ReportService::STUCK_ERROR);

    $ready = fixReport(['status' => 'ready', 'format' => 'csv', 'path' => 'reports/1/x.csv']);
    Storage::disk('local')->put('reports/1/x.csv', 'a');
    $this->actingAs($this->user)->delete(route('reports.destroy', $ready))->assertRedirect(route('reports.index'));
    expect(Report::withoutUserScope()->find($ready->id))->toBeNull();
    Storage::disk('local')->assertMissing('reports/1/x.csv');

    $active = fixReport(['status' => 'processing']);
    $this->actingAs($this->user)->delete(route('reports.destroy', $active))->assertStatus(409);
});

it('ReportFix M-8/M-10: put() false deja failed con mensaje generico (detalle solo en log)', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    $service = app(ReportService::class);
    $report = $service->generate($service->create($this->user, $this->brand, 'csv', Period::make('2026-10-01', '2026-10-31')));

    expect($report->status)->toBe('failed')->and($report->error)->toBe(ReportService::GENERIC_ERROR)
        ->and($report->error)->not->toContain('reports/');
});

it('ReportFix M-9: reports:prune purga por retencion (fila + fichero) y borrar marca/usuario borra los ficheros', function () {
    $old = fixReport(['status' => 'ready', 'path' => 'reports/1/old.csv']);
    $old->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
    $fresh = fixReport(['status' => 'ready', 'path' => 'reports/1/fresh.csv']);
    Storage::disk('local')->put('reports/1/old.csv', 'a');
    Storage::disk('local')->put('reports/1/fresh.csv', 'a');

    $this->artisan('reports:prune')->assertSuccessful();
    expect(Report::withoutUserScope()->find($old->id))->toBeNull()->and(Report::withoutUserScope()->find($fresh->id))->not->toBeNull();
    Storage::disk('local')->assertMissing('reports/1/old.csv');
    Storage::disk('local')->assertExists('reports/1/fresh.csv');

    $brand2 = Brand::factory()->for($this->user)->create();
    fixReport(['brand_id' => $brand2->id, 'status' => 'ready', 'path' => 'reports/1/b2.csv']);
    Storage::disk('local')->put('reports/1/b2.csv', 'a');
    $brand2->delete();
    Storage::disk('local')->assertMissing('reports/1/b2.csv');

    $this->user->delete();
    Storage::disk('local')->assertMissing('reports/1/fresh.csv');
});

it('ReportFix M-11: el grafico de un periodo largo se agrega por semana', function () {
    foreach (range(1, 366) as $i) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $this->ig->id, 'network' => 'instagram',
            'date' => Carbon::parse('2025-10-31')->addDays($i)->format('Y-m-d'), 'metrics' => array_merge(Metric::blankAccount(), [Metric::VIEWS => 1]),
        ]);
    }
    $service = app(ReportService::class);

    $long = $service->dataset($this->user, $this->brand, Period::make('2025-11-01', '2026-10-31'));
    $short = $service->dataset($this->user, $this->brand, Period::make('2026-10-01', '2026-10-31'));

    expect($long['timeline']['granularity'])->toBe('week')->and(count($long['timeline']['points']))->toBeLessThanOrEqual(53)
        ->and($long['timeline']['points'][0]['value'])->toBe(7)
        ->and($short['timeline']['granularity'])->toBe('day')->and($short['timeline']['points'])->toHaveCount(31);
});

it('ReportFix M-12: el PDF elimina los emojis que la fuente no representa', function () {
    $data = (new PdfRenderer)->withoutEmoji(['a' => ['text' => 'Lanzamiento 🚀🔥 nuevo ❤️ ✅ ok'], 'b' => 'Cañón árbol']);

    expect($data['a']['text'])->toBe('Lanzamiento  nuevo   ok')->and($data['b'])->toBe('Cañón árbol');
});
