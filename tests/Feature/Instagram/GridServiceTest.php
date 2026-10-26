<?php

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GridService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;

// C-02 (CA-08, CA-09): mezcla de lo programado con lo publicado. Graph simulado con FakeGraph.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    config(['social.meta.graph_version' => 'v23.0']);
    Storage::fake(config('filesystems.media'));
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU1', 'name' => 'Marca IG']);
    $this->svc = app(GridService::class);
});

function gridTarget($t, ContentType $type, string $when, array $o = []): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $t->brand->id, 'user_id' => $t->user->id, 'status' => PostStatus::Scheduled, 'scheduled_at' => $when]);
    $media = $o['video'] ?? false ? MediaAsset::factory()->video()->for($t->user)->create() : MediaAsset::factory()->for($t->user)->create();
    $post->media()->attach($media->id, ['position' => 0]);

    return PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $t->user->id, 'social_account_id' => $o['account'] ?? $t->ig->id, 'network' => Network::Instagram, 'content_type' => $type, 'status' => $o['status'] ?? PostStatus::Scheduled, 'options' => $o['options'] ?? null]);
}

function gridPublished(string ...$ids): FakeGraph
{
    return (new FakeGraph)->on('GET', '#^IGU1/media$#', ['data' => array_map(fn ($id, $i) => ['id' => $id, 'media_type' => 'IMAGE', 'media_url' => "https://cdn/$id.jpg", 'permalink' => "https://instagram.com/p/$id/", 'timestamp' => '2026-10-'.(20 - $i).'T10:00:00+0000'], $ids, array_keys($ids))]);
}

it('pone las programadas arriba (la mas tardia primero) y despues las publicadas (CA-08)', function () {
    gridPublished('P1', 'P2');
    $a = gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00');
    $b = gridTarget($this, ContentType::InstagramCarousel, '2026-11-09 10:00:00');

    $r = $this->svc->build($this->user, $this->ig);

    expect($r['error'])->toBeNull()
        ->and(array_column($r['items'], 'kind'))->toBe(['scheduled', 'scheduled', 'published', 'published'])
        ->and(array_column($r['items'], 'target_id'))->toBe([$b->id, $a->id, null, null])
        ->and($r['items'][0]['image_url'])->not->toBeNull()
        ->and($r['items'][0]['post_id'])->toBe($b->post_id)
        ->and(array_column($r['items'], 'id'))->toContain('P1', 'P2');
});

it('una Story programada no aparece (CA-09) y un Reel solo con compartir en el feed', function () {
    gridPublished();
    gridTarget($this, ContentType::InstagramStory, '2026-11-05 10:00:00');
    $reelFeed = gridTarget($this, ContentType::InstagramReel, '2026-11-06 10:00:00', ['video' => true, 'options' => ['share_to_feed' => true]]);
    $reelDefault = gridTarget($this, ContentType::InstagramReel, '2026-11-07 10:00:00', ['video' => true]);
    gridTarget($this, ContentType::InstagramReel, '2026-11-08 10:00:00', ['video' => true, 'options' => ['share_to_feed' => false]]);

    $r = $this->svc->build($this->user, $this->ig);

    expect(array_column($r['items'], 'target_id'))->toBe([$reelDefault->id, $reelFeed->id])
        ->and($r['items'][0]['type'])->toBe('VIDEO')->and($r['items'][0]['video_url'])->not->toBeNull();
});

it('solo incluye destinos programados de ESA cuenta', function () {
    gridPublished();
    $other = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU2']);
    gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00', ['account' => $other->id]);
    gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00', ['status' => PostStatus::Draft]);

    expect($this->svc->build($this->user, $this->ig)['items'])->toBe([]);
});

it('la segunda lectura dentro de 10 min sale de cache y pasado el plazo vuelve a leer', function () {
    $graph = gridPublished('P1');

    $this->svc->build($this->user, $this->ig);
    $this->svc->build($this->user, $this->ig);
    expect($graph->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(1);

    Carbon::setTestNow(now()->addMinutes(11));
    $this->svc->build($this->user, $this->ig);
    expect($graph->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(2);
});

it('lo programado se lee siempre en vivo aunque lo publicado venga de cache', function () {
    gridPublished('P1');
    $this->svc->build($this->user, $this->ig);
    gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00');

    expect($this->svc->build($this->user, $this->ig)['items'])->toHaveCount(2);
});

it('un error de Graph se recuerda poco rato (no se repite en cada visita), deja ver lo programado y luego se reintenta', function () {
    $graph = (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::seq(FakeGraph::error(190, 'caducado', null, 401), ['data' => []]));
    gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00');

    $r = $this->svc->build($this->user, $this->ig);
    expect($r['error'])->toContain('Reconecta')->and($r['items'])->toHaveCount(1);

    $r = $this->svc->build($this->user, $this->ig);
    expect($r['error'])->toContain('Reconecta')->and($graph->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(1); // cache del error

    Carbon::setTestNow(now()->addMinutes(3)); // > grid_error_cache_minutes (2)
    $r = $this->svc->build($this->user, $this->ig);
    expect($r['error'])->toBeNull()->and($graph->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(2);
});

it('los mensajes de error de la cuadricula hablan de leer, no de publicar', function () {
    (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::error(200, 'Permissions error', null, 403));

    $error = $this->svc->build($this->user, $this->ig)['error'];

    expect($error)->toContain('leer')->and(mb_strtolower($error))->not->toContain('publicar');
});

it('un destino publicando o procesando sigue en la cuadricula', function () {
    gridPublished();
    $a = gridTarget($this, ContentType::InstagramImage, '2026-11-05 10:00:00', ['status' => PostStatus::Publishing]);
    $b = gridTarget($this, ContentType::InstagramImage, '2026-11-06 10:00:00', ['status' => PostStatus::Processing]);
    gridTarget($this, ContentType::InstagramImage, '2026-11-07 10:00:00', ['status' => PostStatus::Published]);
    gridTarget($this, ContentType::InstagramImage, '2026-11-08 10:00:00', ['status' => PostStatus::Failed]);

    expect(array_column($this->svc->build($this->user, $this->ig)['items'], 'target_id'))->toBe([$b->id, $a->id]);
});

it('pide solo grid_published elementos y respeta la configuracion de la cache', function () {
    config(['social.instagram.grid_published' => 3, 'social.instagram.grid_cache_minutes' => 1]);
    $graph = gridPublished('P1', 'P2', 'P3', 'P4', 'P5');

    expect(array_column($this->svc->build($this->user, $this->ig)['items'], 'id'))->toBe(['P1', 'P2', 'P3']);

    Carbon::setTestNow(now()->addMinutes(2));
    $this->svc->build($this->user, $this->ig);
    expect($graph->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(2);
});

it('el valor por defecto de la config sigue la spec (cache 10, 30 publicados)', function () {
    expect(config('social.instagram.grid_cache_minutes'))->toBe(10)->and(config('social.instagram.grid_published'))->toBe(30);
});

it('lista las cuentas de Instagram ACTIVAS de la marca para el selector (las desconectadas no)', function () {
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'status' => SocialAccount::STATUS_DISCONNECTED, 'name' => 'Vieja']);

    $accounts = $this->svc->accounts($this->brand);

    expect($accounts)->toHaveCount(1)->and($accounts[0])->toMatchArray(['id' => $this->ig->id, 'name' => 'Marca IG']);
});
