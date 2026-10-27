<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-46 · CA-14: posts publicados con hashtags y métricas -> sugerencias ordenadas (mínimo 2 usos) y máximos por red en el compositor.
// La web, el compositor y el MCP leen del mismo servicio y no hay ninguna llamada a la IA.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);

    // Histórico: #verano (3 usos, buen rendimiento), #oferta (2 usos), #nuevo (1 uso, no sale) y posts sin etiquetas que bajan la media.
    foreach ([['Sol #verano #oferta', 120], ['Playa #verano', 90], ['Rebajas #verano #oferta', 150], ['Sin etiquetas', 10], ['Otro #nuevo', 400]] as $i => [$text, $n]) {
        $post = Post::factory()->create(['brand_id' => $this->brand->id, 'status' => PostStatus::Published, 'text' => $text]);
        $target = PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram, 'status' => PostStatus::Published, 'published_at' => '2026-10-1'.$i.' 10:00:00']);
        PostMetricSnapshot::withoutUserScope()->create([
            'user_id' => $this->user->id, 'post_target_id' => $target->id, 'social_account_id' => $this->ig->id, 'network' => 'instagram',
            'date' => '2026-10-31', 'metrics' => array_merge(Metric::blankPost(), [Metric::INTERACTIONS => $n]),
        ]);
    }
});

test('CA-14: el endpoint, el MCP y el compositor ofrecen los mismos hashtags, ordenados por rendimiento y con al menos 2 usos', function () {
    $web = $this->actingAs($this->user)->getJson(route('brands.hashtags', $this->brand))->assertOk();
    [$err, $mcp] = $this->callTool($this->mcpKey($this->user), 'suggest_hashtags', ['brand_id' => $this->brand->id]);

    // Media = 154. #verano: (120+90+150)/3 = 120; #oferta: (120+150)/2 = 135 -> primero #oferta.
    expect($web->json('tags.*.tag'))->toBe(['oferta', 'verano'])
        ->and($web->json('tags.0'))->toMatchArray(['uses' => 2, 'avg_interactions' => 135.0, 'lift_pct' => -12.3])
        ->and($web->json('tags.1'))->toMatchArray(['uses' => 3, 'avg_interactions' => 120.0, 'lift_pct' => -22.1])
        ->and($web->json('tags.*.tag'))->not->toContain('nuevo');
    expect($err)->toBeFalse()->and($mcp['tags'])->toBe($web->json('tags'));
    Http::assertNothingSent();
});

test('CA-14: el compositor trae el máximo de hashtags por tipo (Instagram 30) para avisar y la ruta de sugerencias de la marca', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('limits.instagram_image.max_hashtags', 30)
            ->where('limits.facebook_photo.max_hashtags', null)
            ->where('brand.id', $this->brand->id));

    expect(route('brands.hashtags', $this->brand, false))->toBe('/brands/'.$this->brand->id.'/hashtags');
});

test('CA-14: filtrar por una red sin histórico devuelve el estado vacío y otra marca no ve este histórico', function () {
    $this->actingAs($this->user)->getJson(route('brands.hashtags', [$this->brand, 'network' => 'facebook']))
        ->assertOk()->assertJsonPath('available', false)->assertJsonPath('tags', []);

    $other = Brand::factory()->for($this->user)->create();
    $this->getJson(route('brands.hashtags', $other))->assertOk()->assertJsonPath('tags', []);
});
