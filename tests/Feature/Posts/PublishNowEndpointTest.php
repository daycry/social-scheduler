<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// POST posts/{post}/publish-now: la accion «Publicar ahora» del calendario y la lista (y la que usa el compositor via publish_now).

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->service = app(PostService::class);
});

function endpointPost(array $over = []): Post
{
    return test()->service->create(test()->user, test()->brand, array_merge([
        'text' => 'Hola', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

test('PublishNowEndpoint: un borrador se programa ahora y se encola', function () {
    $post = endpointPost();

    $this->actingAs($this->user)->from(route('calendar.index'))->post(route('posts.publish-now', $post))
        ->assertRedirect(route('calendar.index'))->assertSessionHas('success');

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled)->and($post->fresh()->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNowEndpoint: una programada a futuro se publica ahora', function () {
    $post = endpointPost(['schedule' => true, 'scheduled_at' => '2026-11-09 10:00']);
    expect($post->scheduled_at->isFuture())->toBeTrue();

    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertRedirect();

    expect($post->fresh()->scheduled_at->lte(now()))->toBeTrue();
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNowEndpoint: un post no editable (en vuelo, publicado) se rechaza con el mismo mensaje que update y no encola', function (PostStatus $status) {
    $post = endpointPost();
    $post->targets->first()->forceFill(['status' => $status])->save();

    $this->actingAs($this->user)->post(route('posts.publish-now', $post))
        ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'no se puede modificar'));

    Queue::assertNothingPushed();
})->with([PostStatus::Publishing, PostStatus::Processing, PostStatus::Published]);

test('PublishNowEndpoint: con errores de contenido se rechaza con los mismos mensajes que al programar', function () {
    $post = endpointPost();
    $post->forceFill(['text' => str_repeat('a', 63207)])->save();

    $this->actingAs($this->user)->post(route('posts.publish-now', $post))
        ->assertSessionHas('error', fn ($m) => str_contains($m, '63207'));

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
});

test('PublishNowEndpoint: un post de otro usuario es un 404 y no encola', function () {
    $post = endpointPost();

    $this->actingAs(User::factory()->create())->post(route('posts.publish-now', $post))->assertNotFound();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
});

test('PublishNowEndpoint: exige sesion', function () {
    $this->post(route('posts.publish-now', endpointPost()))->assertRedirect(route('login'));
    Queue::assertNothingPushed();
});

test('PublishNowEndpoint: el compositor envia publish_now sin fecha y se programa con la del servidor', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), [
        'text' => 'Ahora', 'schedule' => true, 'publish_now' => true, 'scheduled_at' => null, 'media' => [],
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ])->assertRedirect(route('calendar.index', ['brand_id' => $this->brand->id, 'view' => 'week', 'date' => '2026-11-01']));

    $post = Post::withoutUserScope()->firstOrFail();
    expect($post->status)->toBe(PostStatus::Scheduled);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNowEndpoint: publish_now no admite scheduled_at a la vez', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), [
        'text' => 'Ahora', 'schedule' => true, 'publish_now' => true, 'scheduled_at' => '2026-12-01T10:00', 'media' => [],
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ])->assertSessionHasErrors('scheduled_at');

    expect(Post::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('PublishNowEndpoint: la ruta tiene limite de peticiones', function () {
    $post = endpointPost();
    $route = app('router')->getRoutes()->getByName('posts.publish-now');

    expect($route->gatherMiddleware())->toContain('throttle:30,1')->and($post->id)->toBeGreaterThan(0);
});

test('PublishNowEndpoint: el calendario marca can_publish_now solo en borradores y programadas editables', function () {
    $draft = endpointPost();
    $scheduled = endpointPost(['schedule' => true, 'scheduled_at' => '2026-11-03 10:00']);
    $published = endpointPost(['schedule' => true, 'scheduled_at' => '2026-11-03 11:00']);
    $published->targets->first()->forceFill(['status' => PostStatus::Published])->save();
    $published->syncStatus();

    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'list', 'brand_id' => $this->brand->id, 'date' => '2026-11-01']))
        ->assertInertia(function ($page) use ($draft, $scheduled, $published) {
            $flags = collect($page->toArray()['props']['items'])->merge($page->toArray()['props']['undated'])->mapWithKeys(fn ($i) => [$i['id'] => $i['can_publish_now']]);
            expect($flags[$draft->id])->toBeTrue()->and($flags[$scheduled->id])->toBeTrue()->and($flags[$published->id])->toBeFalse();
        });
});
