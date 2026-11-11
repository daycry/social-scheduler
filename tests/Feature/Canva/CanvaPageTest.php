<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/CanvaTestSupport.php';

// T-16 · La pagina de Canva de la marca: no configurada, desconectada, conectada con funciones del plan y el origen explicito en la biblioteca.

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
});

test('la pagina muestra «no configurada» sin credenciales y no ofrece conectar', function () {
    config(['services.canva.client_id' => null]);

    $this->actingAs($this->user)->get(route('canva.index', $this->brand))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Canva/Index')->where('canva.configured', false)->where('canva.status', 'not_configured')->where('canva.connected', false));
});

test('desconectada ofrece conectar; conectada lleva funciones con motivo y el diseno de retorno resaltado', function () {
    $this->actingAs($this->user)->get(route('canva.index', $this->brand))->assertInertia(fn (Assert $p) => $p
        ->where('canva.configured', true)->where('canva.status', 'disconnected')->where('focus', null));

    canvaConnect($this->user, $this->brand, capabilities: []);
    $this->actingAs($this->user)->get(route('canva.index', ['brand' => $this->brand, 'design' => 'DAF123abcde']))->assertInertia(fn (Assert $p) => $p
        ->where('canva.connected', true)->where('focus', 'DAF123abcde')
        ->where('canva.features.4.key', 'transparent_png')->where('canva.features.4.available', false)->has('canva.features.4.reason'));
    $this->actingAs($this->user)->get(route('canva.index', ['brand' => $this->brand, 'design' => '<script>']))->assertInertia(fn (Assert $p) => $p->where('focus', null));
});

test('la pagina de una marca ajena es 404 y exige sesion', function () {
    $other = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->get(route('canva.index', $other))->assertNotFound();
    auth()->logout();
    $this->get(route('canva.index', $this->brand))->assertRedirect(route('login'));
});

test('un recurso importado de Canva muestra su origen explicito en la lista de la biblioteca; uno subido no', function () {
    $canva = MediaAsset::factory()->for($this->user)->create(['status' => 'listo', 'kind' => 'image', 'source' => 'canva']);
    $canva->origin = ['provider' => 'canva', 'design_id' => 'DAF123abcde', 'url' => 'https://www.canva.com/design/DAF123abcde/edit', 'title' => 'Rebajas'];
    $canva->save();
    $plain = MediaAsset::factory()->for($this->user)->create(['status' => 'listo', 'kind' => 'image']);

    $media = collect($this->actingAs($this->user)->getJson(route('media.index'))->json('media'))->keyBy('id');

    expect($media[$canva->id]['origin'])->toMatchArray(['label' => 'Canva', 'url' => 'https://www.canva.com/design/DAF123abcde/edit', 'design_id' => 'DAF123abcde'])
        ->and($media[$plain->id]['origin'])->toBeNull()->and(array_keys($media[$canva->id]))->not->toContain('source_ref');
});
