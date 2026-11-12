<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CanvaTestSupport.php';

// T-27 · El PDF exportado desde Canva entra en la biblioteca como media de tipo PDF (tipo real por magic bytes, tamaño máximo, vista previa
// y descarga) y NUNCA se ofrece como media publicable en redes. Http::fake: nada sale a la red.

const CANVA_PDF_URL = 'https://export-download.canva.com/abc/def/Informe.pdf?X-Amz-Signature=s3cr3t&X-Amz-Expires=86400';

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
    $this->connection = canvaConnect($this->user, $this->brand);
});

function canvaPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function canvaPdfExport(?string $body = null, string $contentType = 'application/pdf'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/job-1' => Http::response(['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => [CANVA_PDF_URL]]]),
        'export-download.canva.com/*' => fn () => Http::response($body ?? canvaPdfBytes(), 200, ['Content-Type' => $contentType]),
    ]);
}

/** @return array<string, mixed> el trabajo de exportacion ya ejecutado (cola sincrona). */
function canvaExportPdf($test): array
{
    $res = $test->actingAs($test->user)->postJson(route('canva.exports.store', $test->brand), ['design_id' => 'DAF123abcde', 'format' => 'pdf', 'title' => 'Informe'])->assertStatus(202);

    return $test->actingAs($test->user)->getJson(route('canva.jobs.show', [$test->brand, $res->json('job.id')]))->assertOk()->json('job');
}

test('el PDF exportado entra en la biblioteca como media de tipo pdf, con origen Canva y sin el enlace temporal de 24 h', function () {
    canvaPdfExport();

    $job = canvaExportPdf($this);

    expect($job['status'])->toBe('ready')->and($job['media'])->toHaveCount(1)->and($job)->not->toHaveKey('download_urls');
    $asset = MediaAsset::withoutUserScope()->findOrFail($job['media'][0]['id']);
    expect($asset->kind)->toBe('pdf')->and($asset->mime)->toBe('application/pdf')->and($asset->status)->toBe('listo')->and($asset->original_name)->toBe('Informe.pdf')
        ->and($asset->source)->toBe('canva')->and($asset->source_ref)->toBe('https://export-download.canva.com/abc/def/Informe.pdf')
        ->and($asset->origin)->toMatchArray(['provider' => 'canva', 'design_id' => 'DAF123abcde'])
        ->and($asset->width)->toBeNull()->and($asset->size)->toBe(strlen(canvaPdfBytes()));
    Storage::disk('local')->assertExists($asset->path);
    expect(str_starts_with(Storage::disk('local')->get($asset->path), '%PDF-'))->toBeTrue()->and($asset->path)->toEndWith('.pdf');
});

test('un fichero que no empieza por %PDF queda invalido aunque Canva diga application/pdf; el trabajo falla con el motivo', function (string $body) {
    canvaPdfExport($body);

    $job = canvaExportPdf($this);

    expect($job['status'])->toBe('failed')->and(MediaAsset::withoutUserScope()->where('status', 'listo')->count())->toBe(0);
})->with([
    'html' => ['<html><body>No soy un PDF</body></html>'],
    'png' => ["\x89PNG\r\n\x1a\n0000000000000000"],
]);

test('un PDF por encima del maximo configurado se rechaza y no queda en la biblioteca', function () {
    config(['media.max_bytes.pdf' => 20]);
    canvaPdfExport();

    $job = canvaExportPdf($this);

    expect($job['status'])->toBe('failed')->and($job['error'])->toContain('máximo')->and(MediaAsset::withoutUserScope()->where('status', 'listo')->count())->toBe(0);
});

test('el PDF se puede ver y descargar solo por su dueño; la descarga fuerza el adjunto', function () {
    canvaPdfExport();
    $media = canvaExportPdf($this)['media'][0];
    $asset = MediaAsset::withoutUserScope()->findOrFail($media['id']);

    expect($media['kind'])->toBe('pdf')->and($media['preview_url'])->toBe(route('media.file', $asset, absolute: false))
        ->and($media['download_url'])->toBe(route('media.download', $asset, absolute: false));
    $view = $this->actingAs($this->user)->get($media['preview_url'])->assertOk();
    expect($view->headers->get('Content-Type'))->toBe('application/pdf');
    $download = $this->actingAs($this->user)->get($media['download_url'])->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Informe.pdf');

    $this->actingAs(User::factory()->create())->get($media['download_url'])->assertNotFound();
});

test('una imagen no lleva enlace de descarga y la descarga de una que no es PDF no existe', function () {
    $image = MediaAsset::factory()->for($this->user)->create();

    expect($image->toPublicArray())->not->toHaveKey('download_url');
    $this->actingAs($this->user)->get(route('media.download', $image))->assertNotFound();
});

test('un PDF no se ofrece como media publicable: ni en el selector del compositor, ni en la lista MCP, ni al subirlo desde el compositor', function () {
    canvaPdfExport();
    $pdfId = canvaExportPdf($this)['media'][0]['id'];
    $image = MediaAsset::factory()->for($this->user)->create();

    $ids = collect($this->actingAs($this->user)->getJson(route('media.index'))->json('media'))->pluck('id')->all();
    expect($ids)->toBe([$image->id])->not->toContain($pdfId);

    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'informe.pdf', 'mime' => 'application/pdf', 'size' => 1000])
        ->assertStatus(422)->assertJsonPath('message', 'Los PDF no se pueden publicar en redes. Los exportados desde Canva se guardan en la página de Canva de la marca, donde puedes verlos y descargarlos.');
});

test('el validador de publicaciones rechaza un PDF con un aviso propio en lugar del genérico de formato', function () {
    canvaPdfExport();
    $asset = MediaAsset::withoutUserScope()->findOrFail(canvaExportPdf($this)['media'][0]['id']);

    $errors = app(MediaValidator::class)->validate(ContentType::InstagramImage, [$asset]);

    expect($errors)->toHaveCount(1)->and($errors[0])->toContain('PDF')->toContain('no se pueden publicar en redes')->toContain('Informe.pdf');
});

test('un PDF por URL pública o por Drive no entra: solo el export de Canva trae PDF a la biblioteca', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['example.com/*' => Http::response(canvaPdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

    $this->actingAs($this->user)->postJson(route('media.import-url'), ['url' => 'https://example.com/informe.pdf'])->assertStatus(202);

    $asset = MediaAsset::withoutUserScope()->where('source', 'url')->firstOrFail();
    expect($asset->status)->toBe('invalido')->and($asset->error)->toContain('Los PDF no se pueden publicar en redes')
        ->and(MediaAsset::withoutUserScope()->where('kind', 'pdf')->count())->toBe(0);
});

test('el estado de la marca lista los PDF de la biblioteca (los suyos, solo listos) para verlos y descargarlos', function () {
    canvaPdfExport();
    $mine = canvaExportPdf($this)['media'][0]['id'];
    MediaAsset::factory()->for(User::factory()->create())->create(['kind' => 'pdf', 'mime' => 'application/pdf', 'original_name' => 'ajeno.pdf']);
    MediaAsset::factory()->for($this->user)->create(['kind' => 'pdf', 'mime' => 'application/pdf', 'status' => 'invalido', 'original_name' => 'roto.pdf']);

    $documents = $this->actingAs($this->user)->getJson(route('canva.status', $this->brand))->assertOk()->json('documents');

    expect(array_column($documents, 'id'))->toBe([$mine])->and($documents[0]['download_url'])->toContain('/download');
});
