<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\HashtagStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// C-04a / CA-14 / D5: sugerencias de hashtags desde el historico propio. SIN IA: cualquier peticion externa falla el test.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

/** Destino publicado con su ultima lectura de interacciones. */
function tagged(Brand $brand, string $text, ?int $interactions, Network $network = Network::Instagram, ?string $targetText = null, string $publishedAt = '2026-10-20 10:00:00'): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $brand->id, 'status' => PostStatus::Published, 'text' => $text]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'network' => $network, 'status' => PostStatus::Published, 'published_at' => $publishedAt, 'text' => $targetText]);
    if ($interactions !== null) {
        PostMetricSnapshot::withoutUserScope()->create([
            'user_id' => $brand->user_id, 'post_target_id' => $target->id, 'social_account_id' => $target->social_account_id,
            'network' => $network->value, 'date' => '2026-10-31', 'metrics' => array_merge(Metric::blankPost(), [Metric::INTERACTIONS => $interactions]),
        ]);
    }

    return $target;
}

test('solo salen hashtags con al menos 2 usos, ordenados por mejora sobre la media', function () {
    tagged($this->brand, 'Cafe #cafe #pan', 100);
    tagged($this->brand, 'Oferta #cafe #oferta', 50);
    tagged($this->brand, 'Mix #pan #oferta #cafe', 30);
    tagged($this->brand, 'Sin etiquetas', 20);
    tagged($this->brand, 'Unico #solouno', 500); // 1 uso: no sale

    $r = app(HashtagStats::class)->suggest($this->user, $this->brand);

    // Media global (5 posts con datos) = 140; pan: (100+30)/2 = 65 (-54 %), cafe: 60 (-57 %), oferta: 40 (-71 %).
    expect($r['available'])->toBeTrue()->and($r['min_uses'])->toBe(2)
        ->and(array_column($r['tags'], 'tag'))->toBe(['pan', 'cafe', 'oferta'])
        ->and($r['tags'][0])->toMatchArray(['uses' => 2, 'avg_interactions' => 65.0])
        ->and($r['tags'][1])->toMatchArray(['uses' => 3, 'avg_interactions' => 60.0])
        ->and(array_column($r['tags'], 'tag'))->not->toContain('solouno');
    expect($r['tags'][0]['lift_pct'])->toBeGreaterThan($r['tags'][1]['lift_pct'])->and($r['average'])->toBe(140.0);
});

test('la mejora es sobre la media de la marca y puede ser positiva', function () {
    tagged($this->brand, '#a #b', 100);
    tagged($this->brand, '#a #b', 100);
    tagged($this->brand, 'sin', 10);
    tagged($this->brand, 'sin', 10);

    $r = app(HashtagStats::class)->suggest($this->user, $this->brand);

    // Media = 55; con #a: 100 -> +81,8 %.
    expect($r['tags'][0]['lift_pct'])->toBe(81.8)->and($r['average'])->toBe(55.0);
});

test('Unicode: mayusculas, tildes, ñ y marcas combinantes se agrupan sin distinguir mayusculas', function () {
    tagged($this->brand, 'Hola #Ñandú', 10);
    tagged($this->brand, 'Hola #ñandú', 20);
    tagged($this->brand, "Hola #\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947} x", 5);
    tagged($this->brand, "Hola #\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947} y", 7);

    $tags = array_column(app(HashtagStats::class)->suggest($this->user, $this->brand)['tags'], 'uses', 'tag');

    expect($tags)->toHaveCount(2)->and($tags['Ñandú'] ?? null)->toBe(2)->and($tags["\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}"] ?? null)->toBe(2);
});

test('el texto propio del destino manda sobre el del post', function () {
    tagged($this->brand, 'Post #generico', 10, targetText: 'Version IG #especifico');
    tagged($this->brand, 'Post #generico', 12, targetText: 'Version IG #especifico');

    $tags = array_column(app(HashtagStats::class)->suggest($this->user, $this->brand)['tags'], 'tag');

    expect($tags)->toBe(['especifico']);
});

test('una marca sin historico devuelve un estado vacio explicito', function () {
    $r = app(HashtagStats::class)->suggest($this->user, $this->brand);

    expect($r['available'])->toBeFalse()->and($r['tags'])->toBe([])->and($r['reason'])->toBe('Aún no hay datos suficientes: publica con hashtags y espera a tener métricas.');

    // Hashtags usados una sola vez tampoco bastan.
    tagged($this->brand, '#uno', 10);
    expect(app(HashtagStats::class)->suggest($this->user, $this->brand)['available'])->toBeFalse();
});

test('no cuenta publicaciones sin metricas, de otra marca, de otro usuario ni fuera de ventana', function () {
    tagged($this->brand, '#x', null);
    tagged($this->brand, '#x', null);
    tagged(Brand::factory()->for($this->user)->create(), '#x', 10);
    tagged(Brand::factory()->for(User::factory()->create())->create(), '#x', 10);
    tagged($this->brand, '#x', 10, publishedAt: '2024-01-01 10:00:00');

    expect(app(HashtagStats::class)->suggest($this->user, $this->brand)['tags'])->toBe([]);
});

test('el filtro por red solo mira esa red y la cache se separa por red', function () {
    tagged($this->brand, '#ig', 10);
    tagged($this->brand, '#ig', 14);
    tagged($this->brand, '#th', 10, Network::Facebook);
    tagged($this->brand, '#th', 14, Network::Facebook);

    $stats = app(HashtagStats::class);

    expect(array_column($stats->suggest($this->user, $this->brand, Network::Instagram)['tags'], 'tag'))->toBe(['ig'])
        ->and(array_column($stats->suggest($this->user, $this->brand, Network::Facebook)['tags'], 'tag'))->toBe(['th'])
        ->and(array_column($stats->suggest($this->user, $this->brand)['tags'], 'tag'))->toEqualCanonicalizing(['ig', 'th']);
});

test('se cachea una hora y nunca llama a la IA ni a nada externo', function () {
    tagged($this->brand, '#c', 10);
    tagged($this->brand, '#c', 12);
    $stats = app(HashtagStats::class);

    expect($stats->suggest($this->user, $this->brand)['tags'])->toHaveCount(1);
    tagged($this->brand, '#c', 14);
    expect($stats->suggest($this->user, $this->brand)['tags'][0]['uses'])->toBe(2); // cacheado
    Cache::flush();
    expect($stats->suggest($this->user, $this->brand)['tags'][0]['uses'])->toBe(3);

    Http::assertNothingSent();
});

test('el limite de sugerencias es configurable', function () {
    config(['social.hashtags.limit' => 2]);
    foreach (['a', 'b', 'c'] as $i => $tag) {
        tagged($this->brand, "#$tag", 10 + $i);
        tagged($this->brand, "#$tag", 10 + $i);
    }

    expect(app(HashtagStats::class)->suggest($this->user, $this->brand)['tags'])->toHaveCount(2);
});
