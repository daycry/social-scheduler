<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// T-44: endpoint JSON de sugerencias del compositor. Aislamiento por usuario, marca y red.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function seedTagged(Brand $brand, string $text, int $interactions, Network $network = Network::Instagram): void
{
    $post = Post::factory()->create(['brand_id' => $brand->id, 'status' => PostStatus::Published, 'text' => $text]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'network' => $network, 'status' => PostStatus::Published, 'published_at' => '2026-10-20 10:00:00']);
    PostMetricSnapshot::withoutUserScope()->create([
        'user_id' => $brand->user_id, 'post_target_id' => $target->id, 'social_account_id' => $target->social_account_id,
        'network' => $network->value, 'date' => '2026-10-31', 'metrics' => array_merge(Metric::blankPost(), [Metric::INTERACTIONS => $interactions]),
    ]);
}

test('devuelve las sugerencias de la marca con los usos y la mejora', function () {
    seedTagged($this->brand, 'a #pan', 100);
    seedTagged($this->brand, 'b #pan', 60);

    $this->actingAs($this->user)->getJson(route('brands.hashtags', $this->brand))
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('tags.0.tag', 'pan')
        ->assertJsonPath('tags.0.uses', 2)
        ->assertJsonPath('min_uses', 2);
});

test('la marca de otro usuario responde 404 y no filtra nada', function () {
    $other = Brand::factory()->for(User::factory()->create())->create();
    seedTagged($other, '#secreto', 10);
    seedTagged($other, '#secreto', 20);

    $this->actingAs($this->user)->getJson(route('brands.hashtags', $other))->assertNotFound();
    $this->getJson(route('brands.hashtags', $this->brand))->assertJsonPath('tags', []);
});

test('el filtro por red solo devuelve esa red y un valor invalido da 422', function () {
    seedTagged($this->brand, '#ig', 10);
    seedTagged($this->brand, '#ig', 12);
    seedTagged($this->brand, '#fb', 10, Network::Facebook);
    seedTagged($this->brand, '#fb', 12, Network::Facebook);

    $this->actingAs($this->user)->getJson(route('brands.hashtags', [$this->brand, 'network' => 'facebook']))
        ->assertOk()->assertJsonPath('tags.0.tag', 'fb')->assertJsonCount(1, 'tags');
    $this->getJson(route('brands.hashtags', [$this->brand, 'network' => 'nope']))->assertStatus(422);
});

test('una marca sin historico responde con el estado vacio explicito', function () {
    $this->actingAs($this->user)->getJson(route('brands.hashtags', $this->brand))
        ->assertOk()->assertJsonPath('available', false)->assertJsonPath('tags', [])
        ->assertJsonPath('reason', 'Aún no hay datos suficientes: publica con hashtags y espera a tener métricas.');
});

test('hace falta sesion', function () {
    $this->getJson(route('brands.hashtags', $this->brand))->assertUnauthorized();
});

test('el compositor ya trae los maximos de hashtags por tipo para avisar al insertar', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page->where('limits.instagram_image.max_hashtags', 30));
});
