<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;

// «Guardar en biblioteca» desde el compositor (CA-26): copia el contenido actual y no crea ningun post.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
});

function composerLibraryBody(array $over = []): array
{
    return array_merge([
        'name' => 'Mi plantilla',
        'text' => 'Texto sin guardar',
        'media' => [test()->image->id],
        'targets' => [
            ['account_id' => test()->ig->id, 'content_type' => 'instagram_image'],
            ['account_id' => test()->fb->id, 'content_type' => 'facebook_text'],
        ],
    ], $over);
}

test('LibrarySaveFromComposer: crea la plantilla con texto, media y destinos sin crear ningun post', function () {
    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.library.store', $this->brand), composerLibraryBody())
        ->assertRedirect(route('brands.posts.create', $this->brand))->assertSessionHas('success');

    $t = PostTemplate::query()->firstOrFail();
    expect($t->name)->toBe('Mi plantilla')->and($t->text)->toBe('Texto sin guardar')->and($t->brand_id)->toBe($this->brand->id)
        ->and($t->media()->pluck('media_assets.id')->all())->toBe([$this->image->id])
        ->and($t->targets)->toBe([
            ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
            ['account_id' => $this->fb->id, 'content_type' => 'facebook_text'],
        ]);
    expect(Post::withoutUserScope()->withTrashed()->count())->toBe(0);
});

test('LibrarySaveFromComposer: el nombre es obligatorio', function () {
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), composerLibraryBody(['name' => '']))->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), composerLibraryBody(['name' => '   ']))->assertSessionHasErrors();

    expect(PostTemplate::query()->count())->toBe(0)->and(Post::withoutUserScope()->count())->toBe(0);
});

test('LibrarySaveFromComposer: una marca ajena se rechaza (404) y no guarda nada', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('brands.library.store', $this->brand), composerLibraryBody())->assertNotFound();

    expect(PostTemplate::withoutUserScope()->count())->toBe(0);
});

test('LibrarySaveFromComposer: descarta cuentas y media de otro usuario, y tipos que no son de la red', function () {
    $stranger = User::factory()->create();
    $strangerBrand = Brand::factory()->for($stranger)->create();
    $foreignAccount = SocialAccount::factory()->for($stranger)->create(['brand_id' => $strangerBrand->id, 'network' => Network::Instagram]);
    $foreignMedia = MediaAsset::factory()->for($stranger)->create();

    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), composerLibraryBody([
        'media' => [$this->image->id, $foreignMedia->id],
        'targets' => [
            ['account_id' => $foreignAccount->id, 'content_type' => 'instagram_image'],
            ['account_id' => $this->fb->id, 'content_type' => 'instagram_image'], // tipo de otra red
            ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ],
    ]))->assertSessionHasNoErrors();

    $t = PostTemplate::query()->firstOrFail();
    expect($t->media()->pluck('media_assets.id')->all())->toBe([$this->image->id])
        ->and($t->targets)->toBe([['account_id' => $this->ig->id, 'content_type' => 'instagram_image']]);
});

test('LibrarySaveFromComposer: sin destinos ni media guarda solo el texto', function () {
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), ['name' => 'Solo texto', 'text' => 'Hola'])->assertSessionHasNoErrors();

    $t = PostTemplate::query()->firstOrFail();
    expect($t->text)->toBe('Hola')->and($t->targets)->toBeNull();
});

test('LibrarySaveFromComposer: exige sesion', function () {
    $this->post(route('brands.library.store', $this->brand), composerLibraryBody())->assertRedirect(route('login'));
});
