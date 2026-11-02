<?php

use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
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

test('Compose: todos los tipos ofrecidos llegan con una descripcion no vacia (T-43)', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(function (Assert $page) {
            $tipos = collect($page->toArray()['props']['contentTypes'])->flatten(1);
            expect($tipos)->not->toBeEmpty();
            foreach ($tipos as $t) {
                expect(trim($t['description'] ?? ''))->not->toBe('', $t['value']);
            }
        });
});

test('Compose: programar guarda la publicacion con la hora de la marca en UTC y es visible al editar', function () {
    $response = $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), composeBody());

    $post = Post::withoutUserScope()->first();
    $response->assertRedirect(route('calendar.index', ['brand_id' => $this->brand->id, 'view' => 'week', 'date' => '2026-11-02']));
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
        ->assertRedirect(route('posts.edit', Post::withoutUserScope()->first()));

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
        ->assertRedirect(route('calendar.index', ['brand_id' => $this->brand->id, 'view' => 'week', 'date' => '2026-11-05']));

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

test('Compose: 31 hashtags en Instagram se rechazan al programar y en la validacion en vivo, con el mismo mensaje', function () {
    $text = 'Lanzamiento '.implode(' ', array_map(fn ($i) => "#t$i", range(1, 31)));
    $body = composeBody(['text' => $text, 'media' => [$this->image->id], 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']]]);
    $mensaje = 'El texto tiene 31 hashtags y una publicacion de imagen de Instagram admite como maximo 30.';

    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))
        ->post(route('brands.posts.store', $this->brand), $body)
        ->assertSessionHasErrors(['targets.0' => $mensaje]);
    expect(Post::withoutUserScope()->count())->toBe(0);

    $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), [...$body, 'schedule' => false])
        ->assertOk()->assertExactJson(['errors' => ['targets.0' => [$mensaje]]]);
});

test('Compose: Bluesky llega con su modo de conteo (grafemas) y los demas tipos no lo traen', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('limits.bluesky_post', ['max_chars' => 300, 'max_hashtags' => null, 'count' => 'graphemes'])
            ->where('limits.bluesky_video.count', 'graphemes')
            ->missing('limits.instagram_reel.count'));
});

test('Compose: el compositor recibe los maximos de texto y hashtags por tipo', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('limits.instagram_reel', ['max_chars' => 2200, 'max_hashtags' => 30])
            ->where('limits.facebook_text', ['max_chars' => 63206, 'max_hashtags' => null])
            ->where('limits.youtube_video.max_chars', 5000));

    $post = app(PostService::class)->create($this->user, $this->brand, composeBody());
    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page->where('limits.instagram_image.max_hashtags', 30));
});

test('Compose: el compositor recibe el maximo del primer comentario por tipo y no lo ofrece en Stories ni TikTok (C-01b)', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('firstCommentMax.instagram_image', 2200)
            ->where('firstCommentMax.facebook_photo', 8000)
            ->where('firstCommentMax.youtube_video', 10000)
            ->missing('firstCommentMax.instagram_story')
            ->missing('firstCommentMax.tiktok_video')
            ->where('accounts.0.can_comment', true));
});

test('Compose: YouTube sin el permiso de comentarios llega con can_comment a falso para pedir la reconexion (C-01b)', function () {
    $old = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'scopes' => ['https://www.googleapis.com/auth/youtube']]);
    $new = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(function (Assert $page) use ($old, $new) {
            $yt = collect($page->toArray()['props']['accounts'])->where('network', 'youtube')->keyBy('id');
            expect($yt[$old->id]['can_comment'])->toBeFalse()->and($yt[$new->id]['can_comment'])->toBeTrue();
        });
});

test('Compose: el destino editado lleva su primer comentario, su estado y el motivo del fallo (C-01b)', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, composeBody([
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'Mas info en el enlace']],
    ]));
    $target = $post->targets()->first();
    $target->forceFill(['first_comment_status' => FirstCommentStatus::Failed, 'first_comment_error' => 'Comentarios cerrados'])->save();

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.targets.0.id', $target->id)
            ->where('post.targets.0.first_comment', 'Mas info en el enlace')
            ->where('post.targets.0.first_comment_status', 'fallido')
            ->where('post.targets.0.first_comment_status_label', 'Fallido')
            ->where('post.targets.0.first_comment_error', 'Comentarios cerrados'));
});

test('Compose: guardar con un primer comentario por encima del maximo se rechaza en el destino (C-01b)', function () {
    $body = composeBody(['targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => str_repeat('a', 2201)]]]);

    $this->actingAs($this->user)->from(route('brands.posts.create', $this->brand))->post(route('brands.posts.store', $this->brand), $body)
        ->assertSessionHasErrors('targets.0');
    expect(Post::withoutUserScope()->count())->toBe(0);
});
