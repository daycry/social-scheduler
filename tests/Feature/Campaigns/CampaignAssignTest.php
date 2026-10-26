<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\LibraryService;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Asignar una campaña al post (C-03, CA-12): solo de su marca; las copias de biblioteca la conservan.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->other = Brand::factory()->for($this->user)->create();
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->mine = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Mía']);
    $this->foreign = Campaign::factory()->create(['brand_id' => $this->other->id, 'user_id' => $this->user->id, 'name' => 'De otra marca']);
    $this->service = app(PostService::class);
});

function campaignBody(array $over = []): array
{
    return array_merge([
        'text' => 'Hola', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over);
}

test('la web asigna una campaña de la marca al crear y editar el post', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), campaignBody(['campaign_id' => $this->mine->id]))->assertSessionHasNoErrors();
    $post = Post::withoutUserScope()->firstOrFail();
    expect($post->campaign_id)->toBe($this->mine->id);

    // Omitida: no se toca. Nula: se quita.
    $this->actingAs($this->user)->put(route('posts.update', $post), campaignBody())->assertSessionHasNoErrors();
    expect($post->fresh()->campaign_id)->toBe($this->mine->id);
    $this->actingAs($this->user)->put(route('posts.update', $post), campaignBody(['campaign_id' => null]))->assertSessionHasNoErrors();
    expect($post->fresh()->campaign_id)->toBeNull();
});

test('asignar una campaña de otra marca se rechaza y no guarda nada (CA-12)', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), campaignBody(['campaign_id' => $this->foreign->id]))
        ->assertSessionHasErrors('campaign_id');
    expect(Post::withoutUserScope()->count())->toBe(0);

    $ajena = Campaign::factory()->create(['name' => 'De otro usuario']);
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), campaignBody(['campaign_id' => $ajena->id]))
        ->assertSessionHasErrors('campaign_id');
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), campaignBody(['campaign_id' => 999999]))
        ->assertSessionHasErrors('campaign_id');
});

test('la validación en vivo y el servicio comparten la regla', function () {
    $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), campaignBody(['campaign_id' => $this->foreign->id]))
        ->assertJsonPath('errors.campaign_id.0', fn ($m) => is_string($m) && $m !== '');

    expect(fn () => $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->foreign->id])))
        ->toThrow(PostValidationException::class);
});

test('programar o reprogramar no pierde la campaña', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->mine->id]));

    $this->service->reschedule($this->user, $post, now()->addDays(2));

    expect($post->fresh()->campaign_id)->toBe($this->mine->id);
});

test('guardar en biblioteca desde un post y usar la plantilla conservan la campaña', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->mine->id]));
    $library = app(LibraryService::class);

    $template = $library->saveFromPost($this->user, $post, 'Con campaña');
    expect($template->campaign_id)->toBe($this->mine->id);

    $copy = $library->use($this->user, $template);
    expect($copy->id)->not->toBe($post->id)->and($copy->campaign_id)->toBe($this->mine->id);
});

test('guardar en biblioteca desde el compositor conserva la campaña y rechaza la de otra marca', function () {
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), [
        'name' => 'Plantilla', 'text' => 'x', 'campaign_id' => $this->mine->id,
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ])->assertSessionHasNoErrors();
    expect(PostTemplate::query()->firstOrFail()->campaign_id)->toBe($this->mine->id);

    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), [
        'name' => 'Otra', 'text' => 'x', 'campaign_id' => $this->foreign->id,
    ])->assertSessionHasErrors('campaign_id');
    expect(PostTemplate::query()->count())->toBe(1);
});

test('una plantilla cuya campaña ya no existe crea el borrador sin campaña', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->mine->id]));
    $template = app(LibraryService::class)->saveFromPost($this->user, $post, 'Plantilla');
    $this->mine->delete();

    $copy = app(LibraryService::class)->use($this->user, $template->fresh());

    expect($copy->campaign_id)->toBeNull();
});

test('el compositor recibe las campañas activas de la marca y la del post aunque esté archivada', function () {
    Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Vieja', 'archived_at' => now()]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Posts/Compose')->has('campaigns', 1)->where('campaigns.0.name', 'Mía'));

    $archivedUsed = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Archivada con post']);
    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $archivedUsed->id]));
    $archivedUsed->forceFill(['archived_at' => now()])->save();
    $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('campaigns', 2)->where('post.campaign_id', $archivedUsed->id));
});

test('la campaña se asigna y se quita en un post con destinos publicados o en vuelo (PATCH posts/{post}/campaign)', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody());
    $post->targets->first()->forceFill(['status' => PostStatus::Published])->save();

    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => $this->mine->id])->assertSessionHasNoErrors();
    expect($post->fresh()->campaign_id)->toBe($this->mine->id)->and($post->targets()->first()->status)->toBe(PostStatus::Published);

    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => null])->assertSessionHasNoErrors();
    expect($post->fresh()->campaign_id)->toBeNull();

    $post->targets()->first()->forceFill(['status' => PostStatus::Publishing])->save();
    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => $this->mine->id])->assertSessionHasNoErrors();
    expect($post->fresh()->campaign_id)->toBe($this->mine->id);
    Queue::assertNothingPushed();
});

test('PATCH posts/{post}/campaign respeta la marca, el archivo, la propiedad y exige el campo', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody());
    $archived = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'archived_at' => now()]);

    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => $this->foreign->id])->assertSessionHasErrors('campaign_id');
    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => $archived->id])->assertSessionHasErrors('campaign_id');
    $this->actingAs($this->user)->patch(route('posts.campaign', $post), [])->assertSessionHasErrors('campaign_id');
    $this->actingAs(User::factory()->create())->patch(route('posts.campaign', $post), ['campaign_id' => null])->assertNotFound();
    expect($post->fresh()->campaign_id)->toBeNull();
});

test('una campaña archivada no se asigna a un post nuevo, pero el post que ya la tiene la conserva', function () {
    $archived = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'archived_at' => now()]);

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), campaignBody(['campaign_id' => $archived->id]))->assertSessionHasErrors('campaign_id');

    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->mine->id]));
    $this->service->updateCampaign($this->user, $post, $this->mine->id);
    $this->mine->forceFill(['archived_at' => now()])->save();
    expect($this->service->updateCampaign($this->user, $post, $this->mine->id)->campaign_id)->toBe($this->mine->id);
});

test('carrera: si la campaña se borra entre la validación y el guardado no hay 500 sino un error de validación', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody());
    // Simula el borrado tras `plan()`: el hook de `Post::saving` ya no encuentra la campaña.
    Post::saving(function (Post $p) {
        Campaign::withoutUserScope()->whereKey($this->mine->id)->delete();
    });

    $this->actingAs($this->user)->put(route('posts.update', $post), campaignBody(['campaign_id' => $this->mine->id]))->assertSessionHasErrors('campaign_id');
    $this->actingAs($this->user)->patch(route('posts.campaign', $post), ['campaign_id' => $this->mine->id])->assertSessionHasErrors('campaign_id');
    expect($post->fresh()->campaign_id)->toBeNull();
});

test('carrera (hook): una campaña que ya no existe al guardar se traduce a PostValidationException', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody());
    $post->campaign_id = 999999;

    $save = Closure::bind(fn () => $this->saveOrFailCampaign($post), $this->service, PostService::class);

    expect($save)->toThrow(PostValidationException::class);
});

test('el compositor de un post publicado recibe las campañas y el post no editable (el selector sigue activo)', function () {
    $post = $this->service->create($this->user, $this->brand, campaignBody(['campaign_id' => $this->mine->id]));
    $post->targets->first()->forceFill(['status' => PostStatus::Published])->save();

    $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('post.editable', false)->where('post.campaign_id', $this->mine->id)->has('campaigns', 1));
});
