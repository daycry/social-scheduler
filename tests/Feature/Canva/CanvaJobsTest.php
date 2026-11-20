<?php

use App\Jobs\ImportRemoteMedia;
use App\Jobs\RunCanvaJob;
use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PublishAttempt;
use App\Models\User;
use App\Services\Canva\CanvaJobs;
use App\Services\Canva\CanvaSource;
use App\Services\Canva\CanvaStudioResources;
use App\Services\Media\UrlGuard;
use App\Services\MediaProbe;
use App\Services\Studio\StudioAssets;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

require_once __DIR__.'/CanvaTestSupport.php';

// T-16 · Exportar un diseno de Canva a la biblioteca (job asincrono con sondeo acotado, descarga con UrlGuard, tipo y tamano reales, origen)
// y enviar un recurso de la biblioteca a Canva. Sin compra ni publicacion. Http::fake: nada sale a la red.

const CANVA_FILE = 'https://export-download.canva.com/abc/def/Rebajas.png?X-Amz-Signature=s3cr3t&X-Amz-Expires=86400';

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
    $this->connection = canvaConnect($this->user, $this->brand);
});

function canvaExportFake(array $steps, ?string $body = null, $download = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $sequence = Http::sequence();
    foreach ($steps as $step) {
        $sequence->push($step);
    }
    Http::fake([
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/job-1' => $sequence,
        'export-download.canva.com/*' => $download ?? fn () => Http::response($body ?? canvaPng(), 200, ['Content-Type' => 'image/png']),
    ]);
}

function canvaRunAll(CanvaJob $job): int
{
    $steps = 0;
    while (app(CanvaJobs::class)->process($job->id)) {
        $steps++;
        if ($steps > 60) {
            throw new RuntimeException('El sondeo no esta acotado');
        }
    }

    return $steps;
}

test('exportar crea el trabajo en cola y lo encola; no llama a Canva hasta ejecutarlo', function () {
    Queue::fake();

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png', 'title' => 'Rebajas'])
        ->assertStatus(202)->assertJsonPath('job.status', 'queued')->assertJsonPath('job.format', 'png');

    Queue::assertPushed(RunCanvaJob::class, fn ($j) => $j->jobId === $res->json('job.id'));
    Http::assertNothingSent();
});

test('flujo completo: Canva exporta (con sondeo), se descarga con UrlGuard y el PNG real entra en la biblioteca con su origen', function () {
    canvaExportFake([
        ['job' => ['id' => 'job-1', 'status' => 'in_progress']],
        ['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]],
    ]);

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png', 'title' => 'Rebajas'])->assertStatus(202);

    $json = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->assertOk()->json('job');
    expect($json['status'])->toBe('ready')->and($json['media'])->toHaveCount(1);
    $asset = MediaAsset::withoutUserScope()->findOrFail($json['media'][0]['id']);
    expect($asset->status)->toBe('listo')->and($asset->kind)->toBe('image')->and($asset->mime)->toBe('image/png')->and($asset->width)->toBe(64)
        ->and($asset->source)->toBe('canva')->and($asset->original_name)->toBe('Rebajas.png')
        ->and($asset->source_ref)->toBe('https://export-download.canva.com/abc/def/Rebajas.png') // sin la firma de la query
        ->and($asset->origin)->toMatchArray(['provider' => 'canva', 'design_id' => 'DAF123abcde', 'url' => 'https://www.canva.com/design/DAF123abcde/edit']);
    expect($json['media'][0]['origin'])->toMatchArray(['label' => 'Canva', 'design_id' => 'DAF123abcde', 'url' => 'https://www.canva.com/design/DAF123abcde/edit']);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/v1/exports') && $r['design_id'] === 'DAF123abcde' && $r['format'] === ['type' => 'png'] && $r->hasHeader('Authorization', 'Bearer at-live'));
    Storage::disk('local')->assertExists($asset->path);
});

test('el JPG lleva la calidad obligatoria y el PNG transparente exige la capacidad del plan', function () {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [str_replace('.png', '.jpg', CANVA_FILE)]]]], canvaPng());
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'jpg'])->assertStatus(202);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/v1/exports') && $r['format'] === ['type' => 'jpg', 'quality' => 90]);

    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]]);
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png', 'transparent' => true])->assertStatus(202);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/v1/exports') && $r['format'] === ['type' => 'png', 'transparent_background' => true]);

    $this->connection->forceFill(['capabilities' => [], 'capabilities_at' => now()])->save();
    $before = CanvaJob::withoutUserScope()->count();
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png', 'transparent' => true])
        ->assertStatus(403)->assertJsonPath('code', 'capability_required');
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'jpg', 'transparent' => true])->assertStatus(422);
    expect(CanvaJob::withoutUserScope()->count())->toBe($before);
});

test('un diseno de varias paginas importa un recurso por pagina con nombres distintos', function () {
    $second = str_replace('def/Rebajas', 'def/Rebajas2', CANVA_FILE);
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE, $second]]]]);

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png', 'title' => 'Carrusel'])->assertStatus(202);

    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['error'])->toBeNull();
    expect(collect($job['media'])->pluck('name')->all())->toBe(['Carrusel (1).png', 'Carrusel (2).png'])->and($job['status'])->toBe('ready');
});

test('el sondeo esta acotado: si Canva no termina, el trabajo falla con el motivo y no sigue pidiendo', function () {
    config(['services.canva.max_polls' => 4]);
    Http::fake([
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/job-1' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
    ]);
    Queue::fake();
    $job = app(CanvaJobs::class)->startExport($this->user, $this->brand, $this->connection, 'DAF123abcde', 'png');

    $steps = canvaRunAll($job);

    $job->refresh();
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('timeout')->and($job->error)->toContain('tarda demasiado')->and($steps)->toBe(3);
    Http::assertSentCount(4); // crear el trabajo + 3 consultas: el cuarto sondeo (el limite) ya no se pide
});

test('los errores de Canva se traducen: premium sin comprar, aprobacion pendiente, fallo interno', function (string $code, string $fragment) {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'failed', 'error' => ['code' => $code, 'message' => 'detalle interno secreto']]]]);

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);

    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['status'])->toBe('failed')->and($job['error'])->toContain($fragment)->not->toContain('secreto')->and($job['media'])->toBe([]);
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
})->with([['license_required', 'aquí no se compra nada'], ['approval_required', 'aprobación'], ['internal_failure', 'Inténtalo de nuevo']]);

test('una URL de descarga fuera de Canva se rechaza sin descargar nada', function (string $url) {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [$url]]]]);

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);

    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['status'])->toBe('failed')->and($job['error_code'])->toBe('bad_url');
    Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), 'api.canva.com'));
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
})->with(['otro dominio' => 'https://evil.example.com/a.png', 'sufijo falso' => 'https://evilcanva.com/a.png', 'subdominio falso' => 'https://export-download.canva.com.evil.example.com/a.png',
    'http plano' => 'http://export-download.canva.com/a.png', 'IP interna' => 'https://127.0.0.1/a.png', 'metadatos' => 'https://169.254.169.254/a.png', 'puerto raro' => 'https://export-download.canva.com:8443/a.png']);

test('una redireccion de la descarga hacia fuera de Canva corta antes de pedir el destino', function () {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]], null, Http::response('', 302, ['Location' => 'https://evil.example.com/dentro.png']));

    $asset = null;
    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);
    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');

    expect($job['status'])->toBe('failed')->and($job['error'])->toContain('fuera de Canva');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com'));
});

test('un fichero que no es lo que dice ser (magic bytes) o que supera el tamano se descarta y no entra en la biblioteca', function () {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]], '<?php echo "no soy un png"; ?>'.str_repeat('x', 200));

    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);
    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['status'])->toBe('failed')->and(MediaAsset::withoutUserScope()->where('status', 'listo')->count())->toBe(0);

    config(['media.max_bytes.image' => 100]);
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]], canvaPng(200, 200));
    $res = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);
    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['status'])->toBe('failed')->and(MediaAsset::withoutUserScope()->where('status', 'listo')->count())->toBe(0);
});

test('el MP4 exportado entra en Recursos de su marca y conserva el recurso al reimportar', function () {
    $ffmpeg = (string) config('media.ffmpeg');
    $ffprobe = (string) config('media.ffprobe');
    if ($ffmpeg === '' || $ffprobe === '') {
        $this->markTestSkipped('Este recorrido requiere ffmpeg y ffprobe.');
    }
    try {
        if ((new Process([$ffmpeg, '-version']))->run() !== 0 || (new Process([$ffprobe, '-version']))->run() !== 0) {
            $this->markTestSkipped('Este recorrido requiere ffmpeg y ffprobe.');
        }
    } catch (ProcessStartFailedException) {
        $this->markTestSkipped('No se pudo iniciar ffmpeg o ffprobe.');
    }
    $fixture = tempnam(sys_get_temp_dir(), 'canva-mp4-');
    try {
        (new Process([$ffmpeg, '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=s=32x32:r=24:d=1',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $fixture]))->mustRun();
        $bytes = file_get_contents($fixture);
    } finally {
        unlink($fixture);
    }

    $import = function (string $version) use ($bytes): void {
        $url = "https://export-download.canva.com/{$version}/clip.mp4?sig=1";
        canvaExportFake(
            [['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [$url]]]],
            download: fn () => Http::response($bytes, 200, ['Content-Type' => 'video/mp4']),
        );
        $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'mp4'])->assertAccepted();
    };

    $import('original');
    $asset = MediaAsset::ownedBy($this->user->id)->where('source', 'canva')->sole();

    expect($asset->original_name)->toEndWith('.mp4')
        ->and($asset->origin['design_id'])->toBe('DAF123abcde')
        ->and($asset->isReady())->toBeTrue()
        ->and($asset->isVideo())->toBeTrue()
        ->and(app(StudioAssets::class)->list($this->user, $this->brand)->pluck('id')->all())->toBe([$asset->id]);

    $import('reimported');
    $duplicate = MediaAsset::ownedBy($this->user->id)->where('source', 'canva')->whereKeyNot($asset->id)->sole();
    expect($duplicate->isReady())->toBeTrue()
        ->and($duplicate->usedByStudio())->toBeFalse()
        ->and(app(StudioAssets::class)->list($this->user, $this->brand)->pluck('id')->all())->toBe([$asset->id]);
});

test('maximo de trabajos en curso por usuario y validacion de entrada', function () {
    Queue::fake();
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);
    }
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(429)->assertJsonPath('code', 'too_many');
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => '../x', 'format' => 'png'])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'gif'])->assertStatus(422);
});

test('exportar sin conexion o con otra marca no llama a Canva; un trabajo ajeno es 404', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $free = Brand::factory()->for($this->user)->create();

    $this->actingAs($this->user)->postJson(route('canva.exports.store', $free), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(409)->assertJsonPath('code', 'not_connected');
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $otherBrand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertNotFound();
    $job = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->json('job.id');
    $this->actingAs($other)->getJson(route('canva.jobs.show', [$otherBrand, $job]))->assertNotFound();
    $this->actingAs($other)->getJson(route('canva.jobs.show', [$this->brand, $job]))->assertNotFound();
    Http::assertNothingSent();
});

test('enviar a Canva sube los bytes del recurso como asset y sondea el trabajo hasta tener el id', function () {
    Queue::fake();
    $png = canvaPng();
    $media = MediaAsset::factory()->for($this->user)->create(['disk' => 'local', 'path' => 'media/'.$this->user->id.'/foto.png', 'original_name' => 'Foto de verano.png', 'mime' => 'image/png', 'kind' => 'image', 'status' => 'listo', 'size' => strlen($png)]);
    Storage::disk('local')->put($media->path, $png);
    Http::fake([
        'api.canva.com/rest/v1/asset-uploads' => Http::response(['job' => ['id' => 'up-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/asset-uploads/up-1' => Http::sequence()->push(['job' => ['id' => 'up-1', 'status' => 'in_progress']])->push(['job' => ['id' => 'up-1', 'status' => 'success', 'asset' => ['id' => 'MAF1', 'name' => 'x']]]),
    ]);

    $res = $this->actingAs($this->user)->postJson(route('canva.uploads.store', $this->brand), ['media_id' => $media->id])->assertStatus(202);
    canvaRunAll(CanvaJob::withoutUserScope()->findOrFail($res->json('job.id')));

    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->json('job');
    expect($job['status'])->toBe('ready')->and($job['canva_asset_id'])->toBe('MAF1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/asset-uploads') && $r->body() === $png && $r->hasHeader('Content-Type', 'application/octet-stream')
        && json_decode($r->header('Asset-Upload-Metadata')[0], true) === ['name_base64' => base64_encode('Foto de verano')]);
});

test('enviar a Canva rechaza recursos ajenos, no listos o sin permiso asset:write', function () {
    Queue::fake();
    $other = User::factory()->create();
    $foreign = MediaAsset::factory()->for($other)->create(['status' => 'listo', 'kind' => 'image']);
    $pending = MediaAsset::factory()->for($this->user)->create(['status' => 'pendiente', 'kind' => 'image']);

    $this->actingAs($this->user)->postJson(route('canva.uploads.store', $this->brand), ['media_id' => $foreign->id])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('canva.uploads.store', $this->brand), ['media_id' => $pending->id])->assertStatus(422);
    $this->connection->forceFill(['scopes' => ['profile:read', 'design:meta:read', 'design:content:read']])->save();
    $ready = MediaAsset::factory()->for($this->user)->create(['status' => 'listo', 'kind' => 'image']);
    $this->actingAs($this->user)->postJson(route('canva.uploads.store', $this->brand), ['media_id' => $ready->id])->assertStatus(403)->assertJsonPath('message', fn ($m) => str_contains($m, 'asset:write'));
    Http::assertNothingSent();
});

test('nada compra ni publica: la integracion no crea publicaciones ni toca el estado de ningun post', function () {
    canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]]);
    $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertStatus(202);

    expect(Post::withoutUserScope()->count())->toBe(0)->and(PublishAttempt::withoutUserScope()->count())->toBe(0);
    expect(app(UrlGuard::class))->toBeInstanceOf(UrlGuard::class);
    expect(CanvaSource::allowedUrl(CANVA_FILE))->toBeTrue()->and(ImportRemoteMedia::class)->toBeString();
});

test('la importación de Canva añade cada página a Recursos y reutiliza las páginas idénticas al reimportar', function () {
    $client = $this->actingAs($this->user);
    $secondBrand = Brand::factory()->for($this->user)->create();
    canvaConnect($this->user, $secondBrand);

    $resourceIds = fn (Brand $brand) => app(StudioAssets::class)->list($this->user, $brand)->pluck('id')->sort()->values()->all();

    $import = function (Brand $brand, string $version, int $width = 64) use ($client, $resourceIds): array {
        $urls = [
            "https://export-download.canva.com/{$version}/one.png?Signature=test",
            "https://export-download.canva.com/{$version}/two.png?Signature=test",
        ];
        canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => $urls]]], canvaPng($width));
        $response = $client->postJson(route('canva.exports.store', $brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertAccepted();

        $resourcesBeforePolling = $resourceIds($brand);
        $job = $client->getJson(route('canva.jobs.show', [$brand, $response->json('job.id')]))
            ->assertOk()->assertJsonPath('job.status', 'ready')->json('job');

        expect($resourceIds($brand))->toBe($resourcesBeforePolling);

        return collect($job['media'])->pluck('id')->all();
    };

    $first = $import($this->brand, 'first');
    expect($resourceIds($this->brand))->toBe($first)->and($resourceIds($secondBrand))->toBe([]);

    $import($this->brand, 'reimport');
    expect($resourceIds($this->brand))->toBe($first);

    $changed = $import($this->brand, 'changed', 96);
    expect($resourceIds($this->brand))->toBe([...$first, ...$changed]);

    $other = $import($secondBrand, 'other');
    expect($resourceIds($secondBrand))->toBe($other)->and($resourceIds($this->brand))->toBe([...$first, ...$changed]);
});

test('una vinculación fallida deja el archivo de Canva pendiente y permite reanudar la importación', function () {
    $body = canvaPng();
    $length = strlen($body);
    canvaExportFake(
        [['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]],
        download: fn (Request $request) => $request->hasHeader('Range', "bytes={$length}-")
            ? Http::response('', 416, ['Content-Range' => "bytes */{$length}"])
            : Http::response($body, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) $length]),
    );
    Queue::fake([ImportRemoteMedia::class]);

    $response = $this->actingAs($this->user)->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])
        ->assertAccepted();
    $id = CanvaJob::ownedBy($this->user->id)->findOrFail($response->json('job.id'))->output_media_ids[0];
    $asset = MediaAsset::ownedBy($this->user->id)->findOrFail($id);
    $job = new ImportRemoteMedia($id, CANVA_FILE);

    $probe = app(MediaProbe::class);
    $transactionLevel = DB::transactionLevel();
    app()->instance(MediaProbe::class, Mockery::mock(MediaProbe::class, function ($mock) use ($probe, $transactionLevel): void {
        $mock->shouldReceive('inspect')->twice()->andReturnUsing(function (MediaAsset $asset) use ($probe, $transactionLevel): array {
            expect(DB::transactionLevel())->toBe($transactionLevel);

            return $probe->inspect($asset);
        });
    }));
    app()->instance(CanvaStudioResources::class, Mockery::mock(CanvaStudioResources::class, function ($mock) use ($body): void {
        $mock->shouldReceive('attach')->once()
            ->withArgs(fn (MediaAsset $asset, string $digest) => $asset->isReady() && $digest === hash('sha256', $body))
            ->andThrow(new RuntimeException('Vinculación temporalmente no disponible'));
    }));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Vinculación temporalmente no disponible');
    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_PENDING);
    Storage::disk('local')->assertExists($asset->path);

    app()->forgetInstance(CanvaStudioResources::class);
    app()->call([$job, 'handle']);

    expect($asset->fresh()->isReady())->toBeTrue()
        ->and(app(StudioAssets::class)->list($this->user, $this->brand)->pluck('id')->all())->toBe([$id]);
});

test('un archivo compartido por Canva explica el bloqueo de borrado y una copia sin vínculo se puede eliminar', function () {
    $client = $this->actingAs($this->user);
    $import = function () use ($client): int {
        canvaExportFake([['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_FILE]]]]);
        $id = $client->postJson(route('canva.exports.store', $this->brand), ['design_id' => 'DAF123abcde', 'format' => 'png'])
            ->assertAccepted()->json('job.id');

        return (int) $client->getJson(route('canva.jobs.show', [$this->brand, $id]))->assertOk()->json('job.media.0.id');
    };
    $linked = $import();
    $duplicate = $import();

    $client->deleteJson(route('media.destroy', $linked))->assertConflict()
        ->assertJsonPath('message', 'El archivo está vinculado a Recursos del Estudio.');
    $client->deleteJson(route('media.destroy', $duplicate))->assertOk();
    $this->assertDatabaseMissing('media_assets', ['id' => $duplicate]);

    $import();
    expect(app(StudioAssets::class)->list($this->user, $this->brand)->pluck('id')->all())->toBe([$linked]);
});
