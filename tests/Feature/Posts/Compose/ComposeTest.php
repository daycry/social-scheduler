<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
});

/** Cuerpo del formulario del compositor. */
function composeBody(array $over = []): array
{
    return array_merge([
        'text' => 'Lanzamiento',
        'scheduled_at' => '2026-11-02T10:00',
        'schedule' => true,
        'media' => [test()->image->id],
        'targets' => [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']],
    ], $over);
}

test('Compose: la pantalla ofrece cuentas, tipos por red y las limitaciones de las historias (CA/T-21)', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Posts/Compose')
            ->where('brand.timezone', 'Europe/Madrid')
            ->has('accounts', 2)
            ->has('contentTypes.instagram', 4)
            ->has('contentTypes.facebook', 4)
            ->where('contentTypes.instagram.3.value', 'instagram_story')
            ->where('contentTypes.instagram.3.limitations.0', fn ($v) => str_contains($v, 'no admiten texto'))
            ->where('post', null));
});

test('Compose: programar guarda la publicacion con la hora de la marca en UTC y es visible al editar', function () {
    $response = $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), composeBody());

    $post = Post::withoutUserScope()->first();
    $response->assertRedirect(route('posts.edit', $post));
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
        ->and($post->targets)->toHaveCount(1);

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Posts/Compose')
            ->where('post.text', 'Lanzamiento')
            ->where('post.scheduled_at', '2026-11-02T10:00')
            ->where('post.status', 'programada')
            ->where('post.editable', true)
            ->has('post.media', 1)
            ->where('post.targets.0.content_type', 'instagram_image'));
});

test('Compose: guardar borrador no exige fecha ni destinos', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ['text' => 'idea', 'schedule' => false])
        ->assertRedirect();

    expect(Post::withoutUserScope()->first()->status)->toBe(PostStatus::Draft);
});

test('Compose: un video fuera de los limites de un reel se rechaza indicando el limite y no se guarda (CA-05)', function () {
    $long = MediaAsset::factory()->video(duration: 1200)->for($this->user)->create();

    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.posts.store', $this->brand), composeBody(['media' => [$long->id], 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_reel']]]))
        ->assertRedirect(route('brands.posts.create', $this->brand))
        ->assertSessionHasErrors('targets.0');

    expect(session('errors')->get('targets.0')[0])->toContain('dura 20 min')->toContain('admite como maximo 15 min');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('Compose: la validacion en vivo devuelve los errores sin guardar nada', function () {
    $long = MediaAsset::factory()->video(duration: 1200)->for($this->user)->create();

    $response = $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), ['text' => 'x', 'schedule' => false, 'media' => [$long->id], 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_reel']]])
        ->assertOk();
    expect($response->json('errors')['targets.0'][0])->toContain('admite como maximo 15 min');

    $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), composeBody(['schedule' => false]))
        ->assertOk()->assertExactJson(['errors' => []]);

    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('Compose: la fecha pasada y la falta de cuentas al programar se rechazan con mensaje', function () {
    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.posts.store', $this->brand), composeBody(['scheduled_at' => '2026-10-01T10:00', 'targets' => []]))
        ->assertSessionHasErrors(['scheduled_at', 'targets']);
});

test('Compose: se edita una pendiente (texto, fecha y destino) y se elimina', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, composeBody());

    $this->actingAs($this->user)->put(route('posts.update', $post), composeBody(['text' => 'Editado', 'scheduled_at' => '2026-11-05T18:30', 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_photo']]]))
        ->assertRedirect(route('posts.edit', $post));

    $fresh = $post->fresh();
    expect($fresh->text)->toBe('Editado')->and($fresh->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-05 17:30')
        ->and($fresh->targets()->first()->network)->toBe(Network::Facebook);

    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertRedirect(route('brands.show', $this->brand));
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('Compose: una publicacion ya publicada no se puede editar ni borrar', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, composeBody());
    $target = $post->targets()->first();
    app(PostService::class)->transition($target, PostStatus::Publishing);
    app(PostService::class)->transition($target, PostStatus::Published, ['external_id' => '1', 'external_url' => 'https://x.test/1']);

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page->where('post.editable', false)->where('post.targets.0.status', 'publicada')->where('post.targets.0.external_url', 'https://x.test/1'));

    $this->actingAs($this->user)->from(route('posts.edit', $post))->put(route('posts.update', $post), composeBody(['text' => 'Hack']))
        ->assertSessionHasErrors('post');
    $this->actingAs($this->user)->from(route('posts.edit', $post))->delete(route('posts.destroy', $post))->assertSessionHasErrors('post');

    expect($post->fresh()->text)->toBe('Lanzamiento')->and(Post::withoutUserScope()->count())->toBe(1);
});

test('Compose: aislamiento, otro usuario no puede crear en la marca ni ver, editar o borrar la publicacion', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, composeBody());
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('brands.posts.create', $this->brand))->assertNotFound();
    $this->actingAs($other)->post(route('brands.posts.store', $this->brand), composeBody())->assertNotFound();
    $this->actingAs($other)->postJson(route('brands.posts.validate', $this->brand), composeBody())->assertNotFound();
    $this->actingAs($other)->get(route('posts.edit', $post))->assertNotFound();
    $this->actingAs($other)->put(route('posts.update', $post), composeBody())->assertNotFound();
    $this->actingAs($other)->delete(route('posts.destroy', $post))->assertNotFound();

    expect(Post::withoutUserScope()->count())->toBe(1)->and(PostTarget::withoutUserScope()->count())->toBe(1);
});

test('Compose: el usuario no puede programar con cuentas o media de otro usuario', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $foreignAccount = SocialAccount::factory()->for($other)->create(['brand_id' => $otherBrand->id, 'network' => Network::Instagram]);
    $foreignMedia = MediaAsset::factory()->for($other)->create();

    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.posts.store', $this->brand), composeBody(['targets' => [['account_id' => $foreignAccount->id, 'content_type' => 'instagram_image']]]))
        ->assertSessionHasErrors('targets.0');
    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.posts.store', $this->brand), composeBody(['media' => [$foreignMedia->id]]))
        ->assertSessionHasErrors('media');

    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('Compose: sin sesion se redirige al login', function () {
    $this->get(route('brands.posts.create', $this->brand))->assertRedirect(route('login'));
    $this->post(route('brands.posts.store', $this->brand), composeBody())->assertRedirect(route('login'));
});
