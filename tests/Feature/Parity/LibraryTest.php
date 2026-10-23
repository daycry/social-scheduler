<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\LibraryService;
use App\Services\PostService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Texto base', 'schedule' => true, 'scheduled_at' => '2026-11-10 10:00', 'media' => [$this->image->id],
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']],
    ]);
});

test('Library: guardar un post copia texto, media y destinos', function () {
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'Plantilla 1', 'post_id' => $this->post->id])
        ->assertRedirect(route('library.index'));

    $t = PostTemplate::query()->firstOrFail();
    expect($t->name)->toBe('Plantilla 1')->and($t->text)->toBe('Texto base')
        ->and($t->media()->pluck('media_assets.id')->all())->toBe([$this->image->id])
        ->and($t->targets)->toBe([['account_id' => $this->ig->id, 'content_type' => 'instagram_image']]);
});

test('Library: usar la plantilla crea un BORRADOR (no programado) y abre el editor', function () {
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'P', 'post_id' => $this->post->id]);
    $t = PostTemplate::query()->firstOrFail();

    $resp = $this->actingAs($this->user)->post(route('library.use', $t));
    $new = Post::query()->where('id', '!=', $this->post->id)->firstOrFail();

    $resp->assertRedirect(route('posts.edit', $new));
    expect($new->status)->toBe(PostStatus::Draft)->and($new->text)->toBe('Texto base')
        ->and($new->targets)->toHaveCount(1)->and($new->targets->first()->status)->toBe(PostStatus::Draft)
        ->and($new->media->pluck('id')->all())->toBe([$this->image->id]);
});

test('Library: al usar se descartan destinos cuya cuenta ya no existe y se conserva el contenido', function () {
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'P', 'post_id' => $this->post->id]);
    $t = PostTemplate::query()->firstOrFail();
    $this->ig->forceFill(['status' => SocialAccount::STATUS_DISCONNECTED])->save();

    $this->actingAs($this->user)->post(route('library.use', $t))->assertRedirect();

    $new = Post::query()->where('id', '!=', $this->post->id)->firstOrFail();
    expect($new->targets)->toHaveCount(0)->and($new->text)->toBe('Texto base')->and($new->status)->toBe(PostStatus::Draft);
});

test('Library: plantilla de solo texto, validacion de nombre y limite por usuario', function () {
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'Solo texto', 'brand_id' => $this->brand->id, 'text' => 'Hola'])->assertRedirect(route('library.index'));
    expect(PostTemplate::query()->count())->toBe(1);

    $this->actingAs($this->user)->post(route('library.store'), ['brand_id' => $this->brand->id])->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post(route('library.store'), ['name' => str_repeat('x', 121), 'brand_id' => $this->brand->id])->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'Sin origen'])->assertSessionHasErrors('brand_id');
});

test('Library: media ajena se ignora al guardar (no se cuela en la plantilla)', function () {
    $foreign = MediaAsset::factory()->for(User::factory()->create())->create();
    $t = app(LibraryService::class)->store($this->user, $this->brand, 'X', 'txt', [$foreign->id, $this->image->id]);

    expect($t->media()->pluck('media_assets.id')->all())->toBe([$this->image->id]);
});

test('Library: aislamiento — plantillas, posts y marcas ajenas responden 404 y no aparecen en el listado', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $otherTemplate = app(LibraryService::class)->store($other, $otherBrand, 'Secreta', 'texto privado');
    $otherPost = Post::factory()->create(['brand_id' => $otherBrand->id]);

    $this->actingAs($this->user)->post(route('library.use', $otherTemplate))->assertNotFound();
    $this->actingAs($this->user)->delete(route('library.destroy', $otherTemplate))->assertNotFound();
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'robo', 'post_id' => $otherPost->id])->assertNotFound();
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'robo', 'brand_id' => $otherBrand->id])->assertNotFound();

    $this->actingAs($this->user)->get(route('library.index'))->assertOk()->assertDontSee('Secreta')->assertDontSee('texto privado');
    expect(PostTemplate::withoutUserScope()->where('user_id', $other->id)->count())->toBe(1);
});

test('Library: eliminar la plantilla no toca la publicacion original', function () {
    $this->actingAs($this->user)->post(route('library.store'), ['name' => 'P', 'post_id' => $this->post->id]);
    $t = PostTemplate::query()->firstOrFail();

    $this->actingAs($this->user)->delete(route('library.destroy', $t))->assertRedirect(route('library.index'));

    expect(PostTemplate::query()->count())->toBe(0)->and(Post::query()->count())->toBe(1);
});
