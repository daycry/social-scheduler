<?php

use App\Models\Brand;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// T-66 · C-04b: el compositor solo recibe un booleano; sin GIPHY_API_KEY el boton «GIF» no aparece y la clave nunca llega a la pagina.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

test('sin GIPHY_API_KEY la prop giphy.enabled es false', function () {
    config(['services.giphy.api_key' => null]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Posts/Compose')->where('giphy.enabled', false));

    config(['services.giphy.api_key' => '']);
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertInertia(fn (Assert $p) => $p->where('giphy.enabled', false));
});

test('con la clave configurada giphy.enabled es true y la pagina solo lleva ese booleano', function () {
    config(['services.giphy.api_key' => 'giphy-SECRET-KEY']);

    $response = $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('giphy', ['enabled' => true]));

    expect($response->getContent())->not->toContain('giphy-SECRET-KEY');
});

test('el modal declara la atribucion «Powered by GIPHY» y la busqueda va por el servidor', function () {
    $vue = file_get_contents(resource_path('js/Components/Composer/GifPicker.vue'));

    expect($vue)->toContain('Powered by GIPHY')->toContain("route('gifs.search'")->not->toContain('api.giphy.com')->not->toContain('api_key');
});
