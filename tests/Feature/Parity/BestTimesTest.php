<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\BestTimesService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.best_times.min_posts' => 3]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
});

/** Publicacion publicada con su ultima lectura de metricas. */
function bestTimesPost(Brand $brand, string $publishedUtc, array $metrics, Network $network = Network::Instagram): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $brand->id, 'status' => PostStatus::Published]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'network' => $network, 'status' => PostStatus::Published, 'published_at' => $publishedUtc]);
    PostMetricSnapshot::withoutUserScope()->create([
        'user_id' => $brand->user_id, 'post_target_id' => $target->id, 'social_account_id' => $target->social_account_id,
        'network' => $network->value, 'date' => '2026-10-31', 'metrics' => array_merge(Metric::blankPost(), $metrics),
    ]);

    return $target;
}

test('BestTimes: agrega por dia de la semana y hora en la zona de la marca, con media por celda', function () {
    // 2026-10-05 08:30 UTC = lunes 10:30 en Madrid (CEST, UTC+2).
    bestTimesPost($this->brand, '2026-10-05 08:30:00', [Metric::INTERACTIONS => 10]);
    bestTimesPost($this->brand, '2026-10-12 08:10:00', [Metric::INTERACTIONS => 30]);
    // Sin interacciones totales: suma likes + comments (5 + 5).
    bestTimesPost($this->brand, '2026-10-14 17:00:00', [Metric::LIKES => 5, Metric::COMMENTS => 5]);

    $r = app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram);

    expect($r['available'])->toBeTrue()
        ->and($r['posts'])->toBe(3)
        ->and($r['timezone'])->toBe('Europe/Madrid')
        ->and($r['matrix'][0][10])->toBe(20.0)         // lunes 10h: (10 + 30) / 2
        ->and($r['counts'][0][10])->toBe(2)
        ->and($r['matrix'][2][19])->toBe(10.0)         // miercoles 19h local
        ->and($r['matrix'][0][8])->toBeNull()          // la hora UTC NO se usa
        ->and($r['top'][0])->toMatchArray(['day' => 'Lunes', 'hour' => 10, 'posts' => 2, 'avg_engagement' => 20.0]);
});

test('BestTimes: con menos publicaciones que el minimo devuelve no disponible, no un mapa', function () {
    bestTimesPost($this->brand, '2026-10-05 08:30:00', [Metric::INTERACTIONS => 10]);
    bestTimesPost($this->brand, '2026-10-06 08:30:00', [Metric::INTERACTIONS => 10]);

    $r = app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram);

    expect($r['available'])->toBeFalse()->and($r['matrix'])->toBe([])->and($r['top'])->toBe([])
        ->and($r['reason'])->toContain('2')->toContain('3');
});

test('BestTimes: no cuenta otras redes, publicaciones sin metricas, fuera de ventana ni datos de otro usuario', function () {
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
        bestTimesPost($this->brand, "$d 08:30:00", [Metric::INTERACTIONS => 10]);
    }
    bestTimesPost($this->brand, '2026-10-08 08:30:00', [Metric::INTERACTIONS => 99], Network::Facebook);
    bestTimesPost($this->brand, '2026-10-09 08:30:00', []);              // metricas "no disponibles" (null), no 0
    bestTimesPost($this->brand, '2026-05-01 08:30:00', [Metric::INTERACTIONS => 99]); // fuera de los 90 dias

    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create(['timezone' => 'Europe/Madrid']);
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
        bestTimesPost($otherBrand, "$d 08:30:00", [Metric::INTERACTIONS => 5000]);
    }

    $r = app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram);

    expect($r['posts'])->toBe(3)->and(json_encode($r))->not->toContain('5000')->not->toContain('99');
    // Un usuario no puede pedir el mapa de la marca de otro: el servicio solo ve los datos del usuario indicado.
    expect(app(BestTimesService::class)->heatmap($this->user, $otherBrand, Network::Instagram)['posts'])->toBe(0);
});

test('BestTimes: la pagina responde con el mapa y una marca ajena es 404', function () {
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
        bestTimesPost($this->brand, "$d 08:30:00", [Metric::INTERACTIONS => 10]);
    }
    $other = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->get(route('best-times.index', ['brand_id' => $this->brand->id, 'network' => 'instagram']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('BestTimes/Index')->where('result.available', true)->where('result.posts', 3));

    $this->actingAs($this->user)->get(route('best-times.index', ['brand_id' => $other->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('best-times.index', ['network' => 'myspace']))->assertSessionHasErrors('network');
    auth()->logout();
    $this->get(route('best-times.index'))->assertRedirect(route('login'));
});

test('BestTimes: la herramienta MCP get_best_times respeta la ability y el aislamiento', function () {
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
        bestTimesPost($this->brand, "$d 08:30:00", [Metric::INTERACTIONS => 10]);
    }
    $key = $this->mcpKey($this->user);
    [$err, $d] = $this->callTool($key, 'get_best_times', ['brand_id' => $this->brand->id, 'network' => 'instagram']);
    expect($err)->toBeFalse()->and($d['available'])->toBeTrue()->and($d['posts'])->toBe(3);

    $otherBrand = Brand::factory()->for(User::factory()->create())->create(['name' => 'Marca secreta']);
    [$err, $msg] = $this->callTool($key, 'get_best_times', ['brand_id' => $otherBrand->id, 'network' => 'instagram']);
    expect($err)->toBeTrue()->and(json_encode($msg))->not->toContain('secreta');

    [$err] = $this->callTool($this->mcpKey($this->user, ['posts:read']), 'get_best_times', ['brand_id' => $this->brand->id, 'network' => 'instagram']);
    expect($err)->toBeTrue();

    [$err] = $this->callTool($key, 'get_best_times', ['brand_id' => $this->brand->id, 'network' => 'myspace']);
    expect($err)->toBeTrue();
});

test('BestTimes: B11 usa la ULTIMA lectura de cada destino (MAX(date) en SQL) y la cache corta evita recalcular', function () {
    foreach ([5, 12, 14] as $d) {
        $t = bestTimesPost($this->brand, sprintf('2026-10-%02d 08:30:00', $d), [Metric::INTERACTIONS => 1]); // lectura antigua (31-oct)
        PostMetricSnapshot::withoutUserScope()->create([
            'user_id' => $this->brand->user_id, 'post_target_id' => $t->id, 'social_account_id' => $t->social_account_id,
            'network' => 'instagram', 'date' => '2026-11-01', 'metrics' => array_merge(Metric::blankPost(), [Metric::INTERACTIONS => 40]),
        ]);
    }

    $r = app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram);
    expect($r['top'][0]['avg_engagement'])->toBe(40.0);

    config(['social.best_times.cache_minutes' => 5]);
    app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram);
    PostMetricSnapshot::withoutUserScope()->update(['metrics' => json_encode(array_merge(Metric::blankPost(), [Metric::INTERACTIONS => 99]))]);
    expect(app(BestTimesService::class)->heatmap($this->user, $this->brand, Network::Instagram)['top'][0]['avg_engagement'])->toBe(40.0); // desde cache
});
