<?php

use App\Models\Brand;
use App\Models\IdempotencyKey;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\User;

test('abrir una imagen crea un diseño de la marca usando el archivo existente', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create(['original_name' => 'Cartel de otoño.png', 'width' => 640, 'height' => 320]);

    $response = $this->actingAs($user)->post("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $asset->id]);

    $design = Design::ownedBy($user->id)->sole();
    $response->assertRedirect(route('studio.designs.edit', $design));
    expect($design->name)->toBe('Cartel de otoño.png')->and($design->brand_id)->toBe($brand->id)
        ->and($design->document['width'])->toBe(640)->and($design->document['height'])->toBe(320)
        ->and($design->document['pages'][0]['elements'][0])->toMatchArray([
            'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 640, 'height' => 320,
        ]);
    $this->assertDatabaseMissing('studio_design_shares', ['design_id' => $design->id]);
    $this->assertDatabaseHas('studio_assets', ['brand_id' => $brand->id, 'media_asset_id' => $asset->id]);
    expect(MediaAsset::ownedBy($user->id)->count())->toBe(1);
});

test('abrir una imagen comprueba la marca, propiedad del archivo y validación previa', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $alien = MediaAsset::factory()->for($other)->create();
    $pending = MediaAsset::factory()->for($user)->create(['status' => MediaAsset::STATUS_PENDING]);

    $this->actingAs($user)->postJson("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $alien->id])->assertNotFound();
    $this->postJson("/brands/{$otherBrand->id}/studio/designs/from-image", ['media_id' => $pending->id])->assertNotFound();
    $this->postJson("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $pending->id])->assertUnprocessable()->assertJsonValidationErrors('media_id');
    expect(Design::ownedBy($user->id)->count())->toBe(0);
});

test('abrir una imagen que ya no está disponible da un aviso en Estudio y no reabre el compositor', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $pending = MediaAsset::factory()->for($user)->create(['status' => MediaAsset::STATUS_PENDING]);
    $studio = route('studio.designs.index', $brand);

    $this->actingAs($user)->from(route('brands.posts.create', $brand))
        ->post("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $pending->id])
        ->assertRedirect($studio)->assertSessionHas('error', 'Elige una imagen validada y lista.');
    $this->from(route('brands.posts.create', $brand))
        ->post("/brands/{$brand->id}/studio/designs/from-image")
        ->assertRedirect($studio)->assertSessionHas('error');
    expect(Design::ownedBy($user->id)->count())->toBe(0);
});

test('abrir una imagen panorámica conserva el archivo y lo centra dentro de los límites del lienzo', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create(['original_name' => 'Panorama.jpg', 'width' => 16000, 'height' => 40]);

    $response = $this->actingAs($user)->post("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $asset->id]);
    $design = Design::ownedBy($user->id)->sole();
    $response->assertRedirect(route('studio.designs.edit', $design));

    expect($design->document['width'])->toBe(8000)->and($design->document['height'])->toBe(40)
        ->and($design->document['pages'][0]['elements'][0])->toMatchArray(['asset_id' => $asset->id, 'width' => 8000, 'height' => 20, 'y' => 10]);
});

test('abrir la imagen dos veces con la misma clave devuelve el diseño ya creado', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create(['width' => 640, 'height' => 320]);
    $path = "/brands/{$brand->id}/studio/designs/from-image";
    $payload = ['media_id' => $asset->id, 'request_key' => 'studio-image-draft-1'];

    $created = $this->actingAs($user)->post($path, $payload)->assertRedirect();
    $this->post($path, $payload)->assertRedirect($created->headers->get('Location'));

    expect(Design::ownedBy($user->id)->count())->toBe(1);
    $this->assertDatabaseCount('studio_assets', 1);

    $this->post($path, [...$payload, 'request_key' => 'studio-image-draft-2'])->assertRedirect();
    expect(Design::ownedBy($user->id)->count())->toBe(2);
    $this->assertDatabaseCount('studio_assets', 1);
});

test('la misma imagen se puede abrir tras archivar el diseño anterior', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create(['width' => 640, 'height' => 320]);
    $path = "/brands/{$brand->id}/studio/designs/from-image";
    $payload = ['media_id' => $asset->id, 'request_key' => 'reabrir-una-imagen'];
    $first = $this->actingAs($user)->post($path, $payload)->assertRedirect();
    Design::ownedBy($user->id)->sole()->delete();

    $second = $this->post($path, $payload)->assertRedirect();
    expect($second->headers->get('Location'))->not->toBe($first->headers->get('Location'));
    $this->post($path, $payload)->assertRedirect($second->headers->get('Location'));
    $this->assertDatabaseCount('studio_assets', 1);
});

test('una reserva activa muestra un aviso en la pestaña nueva y no crea otro diseño', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create(['width' => 640, 'height' => 320]);
    $payload = ['media_id' => $asset->id, 'request_key' => 'imagen-en-curso'];
    IdempotencyKey::query()->create([
        'user_id' => $user->id,
        'scope' => 'studio.from-image',
        'key' => $payload['request_key'],
        'request_hash' => hash('sha256', json_encode(['brand_id' => $brand->id, 'media_id' => $asset->id])),
    ]);

    $this->actingAs($user)->post("/brands/{$brand->id}/studio/designs/from-image", $payload)
        ->assertRedirect(route('studio.designs.index', $brand))
        ->assertSessionHas('error', 'El diseño se está abriendo en otra pestaña.');
    expect(Design::ownedBy($user->id)->count())->toBe(0);
});

test('el formulario nativo da el mismo aviso para archivos ajenos o ausentes', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $alien = MediaAsset::factory()->create();
    $absent = $alien->id + 1000;

    foreach ([$alien->id, $absent] as $id) {
        $this->actingAs($user)->post("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $id])
            ->assertRedirect(route('studio.designs.index', $brand))
            ->assertSessionHas('error', 'La imagen ya no está disponible.');
    }
    $this->assertDatabaseCount('studio_assets', 0);
});

test('un vídeo o una imagen sin dimensiones no se añade a Studio', function (array $overrides) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $asset = MediaAsset::factory()->for($user)->create($overrides + ['width' => 640, 'height' => 320]);

    $this->actingAs($user)->postJson("/brands/{$brand->id}/studio/designs/from-image", ['media_id' => $asset->id])
        ->assertUnprocessable()->assertJsonValidationErrors('media_id');
    $this->assertDatabaseCount('studio_assets', 0);
})->with([[['kind' => MediaAsset::KIND_VIDEO]], [['width' => 0]], [['height' => null]]]);
