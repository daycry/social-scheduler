<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;

/** Ajustes de marca (T-05): el avatar es una imagen de la media del propio usuario. */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
});

test('se puede elegir como avatar una imagen propia y queda persistido', function () {
    $image = MediaAsset::factory()->for($this->user)->create();

    $this->actingAs($this->user)->put(route('brands.update', $this->brand), [
        'name' => 'Marca ajustada', 'description' => null, 'timezone' => 'America/Mexico_City', 'avatar_media_id' => $image->id,
    ])->assertRedirect(route('brands.index'));

    $this->brand->refresh();
    expect($this->brand->name)->toBe('Marca ajustada')
        ->and($this->brand->timezone)->toBe('America/Mexico_City')
        ->and($this->brand->avatar_media_id)->toBe($image->id);
});

test('no se puede usar como avatar la media de otro usuario', function () {
    $foreign = MediaAsset::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->put(route('brands.update', $this->brand), [
        'name' => 'X', 'timezone' => 'Europe/Madrid', 'avatar_media_id' => $foreign->id,
    ])->assertSessionHasErrors('avatar_media_id');

    expect($this->brand->fresh()->avatar_media_id)->toBeNull();
});

test('el formulario de edición ofrece las imágenes del usuario', function () {
    MediaAsset::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get(route('brands.edit', $this->brand))
        ->assertInertia(fn ($page) => $page->component('Brands/Form')->has('images', 1));
});
