<?php

use App\Jobs\Studio\RenderStudioThumbnail;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioService;
use App\Services\Studio\StudioThumbnails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// T-35: miniatura por diseno. Se genera bajo demanda al listar (por la cola `media`), se cachea por revision y se invalida al editar.

beforeEach(function () {
    config(['studio.thumbnails.enabled' => true]);
    Storage::fake('local');
    Cache::flush();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 1, 'width' => 1000, 'height' => 500, 'pages' => [
        ['id' => 'page_one', 'name' => 'Uno', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
        ['id' => 'page_two', 'name' => 'Dos', 'background' => '#000000', 'duration' => 5, 'elements' => []],
    ]];
    Queue::fake();
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Con miniatura', 'document' => $this->document]);
});

function thumbRender(): void
{
    Process::fake(function ($process) {
        $input = json_decode($process->input, true);
        expect($input['format'])->toBe('jpeg')->and($input['pageIds'])->toBe(['page_one'])->and($input['scale'])->toBe(0.48)->and($input['transparent'])->toBeFalse();
        $image = imagecreatetruecolor(480, 240);
        imagejpeg($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.jpg');
        imagedestroy($image);

        return Process::result(output: '{"files":[{"name":"page-001.jpg","mime":"image/jpeg"}]}');
    });
}

test('Miniaturas: listar un diseno sin miniatura la encola una vez y muestra el hueco, no una URL', function () {
    $rows = app(StudioThumbnails::class)->decorate($this->user, [['id' => $this->design->id, 'revision' => 1, 'deleted_at' => null]]);
    expect($rows[0]['thumbnail_url'])->toBeNull();
    Queue::assertPushed(RenderStudioThumbnail::class, fn ($job) => $job->designId === $this->design->id && $job->queue === 'media');

    app(StudioThumbnails::class)->decorate($this->user, [['id' => $this->design->id, 'revision' => 1, 'deleted_at' => null]]);
    Queue::assertPushed(RenderStudioThumbnail::class, 1); // el trabajo es unico por diseno: otra visita no lo duplica

    // La papelera y las miniaturas desactivadas no encolan nada.
    config(['studio.thumbnails.enabled' => false]);
    Queue::fake();
    app(StudioThumbnails::class)->decorate($this->user, [['id' => $this->design->id, 'revision' => 1, 'deleted_at' => null]]);
    Queue::assertNothingPushed();
});

test('Miniaturas: el job renderiza solo la primera pagina reducida, la guarda por revision y la sirve firmada', function () {
    thumbRender();

    (new RenderStudioThumbnail($this->design->id))->handle(app(StudioThumbnails::class));

    $design = Design::withoutUserScope()->findOrFail($this->design->id);
    expect($design->thumbnail_revision)->toBe(1)->and($design->thumbnail_path)->toBe('studio-thumbnails/'.$this->user->id.'/'.$design->id.'-r1.jpg');
    expect(Storage::disk('local')->exists($design->thumbnail_path))->toBeTrue()->and(Storage::disk('local')->directories('studio-exports/'.$this->user->id.'/'.$design->id))->toBe([]);
    Process::assertRanTimes(fn () => true, 1);
    // Generar la miniatura no cuenta como editar: ni cambia la revision ni la fecha de modificacion.
    expect($design->revision)->toBe(1)->and($design->updated_at->equalTo($this->design->updated_at))->toBeTrue();

    $rows = app(StudioThumbnails::class)->decorate($this->user, [['id' => $design->id, 'revision' => 1, 'deleted_at' => null]]);
    expect($rows[0]['thumbnail_url'])->toContain('/studio/thumbnails/'.$design->id.'/'.$this->user->id);
    Queue::assertNothingPushed();
    $this->actingAs($this->user)->get($rows[0]['thumbnail_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    // Sin firma, o con otro usuario como propietario, no se sirve.
    $this->get('/studio/thumbnails/'.$design->id.'/'.$this->user->id)->assertForbidden();
});

test('Miniaturas: editar el diseno invalida la miniatura, se vuelve a encolar y la anterior se borra al regenerar', function () {
    thumbRender();
    (new RenderStudioThumbnail($this->design->id))->handle(app(StudioThumbnails::class));
    $first = Design::withoutUserScope()->findOrFail($this->design->id)->thumbnail_path;

    $changed = $this->document;
    $changed['pages'][0]['background'] = '#ff0000';
    $edited = app(StudioService::class)->update($this->user, $this->design, ['name' => 'Con miniatura', 'document' => $changed, 'revision' => 1, 'autosave' => true]);
    expect($edited->revision)->toBe(2);
    // La edicion (autoguardado incluido) no renderiza nada: solo deja la miniatura caducada.
    Process::assertRanTimes(fn () => true, 1); // solo la de la primera miniatura
    Queue::assertNothingPushed();

    $rows = app(StudioThumbnails::class)->decorate($this->user, [['id' => $edited->id, 'revision' => 2, 'deleted_at' => null]]);
    expect($rows[0]['thumbnail_url'])->toBeNull();
    Queue::assertPushed(RenderStudioThumbnail::class, 1);

    thumbRender();
    (new RenderStudioThumbnail($edited->id))->handle(app(StudioThumbnails::class));
    $design = Design::withoutUserScope()->findOrFail($edited->id);
    expect($design->thumbnail_revision)->toBe(2)->and($design->thumbnail_path)->toEndWith('-r2.jpg');
    expect(Storage::disk('local')->exists($first))->toBeFalse()->and(Storage::disk('local')->exists($design->thumbnail_path))->toBeTrue();
});

test('Miniaturas: un render que falla no deja miniatura, no rompe el listado y no se reintenta de inmediato', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    (new RenderStudioThumbnail($this->design->id))->handle(app(StudioThumbnails::class));
    expect(Design::withoutUserScope()->findOrFail($this->design->id)->thumbnail_path)->toBeNull();

    // Tras un fallo la revision queda aplazada unos minutos: ni el job ni el listado vuelven a intentarlo de inmediato.
    (new RenderStudioThumbnail($this->design->id))->handle(app(StudioThumbnails::class));
    Process::assertRanTimes(fn () => true, 1);
    $rows = app(StudioThumbnails::class)->decorate($this->user, [['id' => $this->design->id, 'revision' => 1, 'deleted_at' => null]]);
    expect($rows[0]['thumbnail_url'])->toBeNull();
    Queue::assertNothingPushed();
});

test('Miniaturas: el listado web trae la miniatura vigente y solo del propietario', function () {
    thumbRender();
    (new RenderStudioThumbnail($this->design->id))->handle(app(StudioThumbnails::class));

    $this->actingAs($this->user)->getJson(route('studio.index', ['brand_id' => $this->brand->id]))->assertOk()
        ->assertJsonPath('designs.designs.0.id', $this->design->id)
        ->assertJsonPath('designs.designs.0.thumbnail_url', fn ($url) => str_contains((string) $url, '/studio/thumbnails/'.$this->design->id.'/'.$this->user->id));
});
