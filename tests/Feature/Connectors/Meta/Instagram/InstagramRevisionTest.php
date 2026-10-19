<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;

// Revision Fase 3 intento 1: #2 (padre del carrusel), #5 (stories), #8 (carrusel por pasos), #17 (token en cabecera).

const RV_IG = '17841400000000009';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0', 'filesystems.media' => 'local']);
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => RV_IG, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->ig = new InstagramPublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->ig));
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 25]]]])
        ->on('GET', '#^1\d+$#', ['permalink' => 'https://www.instagram.com/p/ABC/']);
});

/** Destino de $type con $n medias listo para el despachador. */
function rvTarget(ContentType $type, int $n, ?string $text = 'Carrusel'): PostTarget
{
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'text' => $text, 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    for ($i = 0; $i < $n; $i++) {
        $asset = MediaAsset::factory()->for(test()->user)->create();
        Storage::disk('local')->put($asset->path, 'x');
        $post->media()->attach($asset->id, ['position' => $i]);
    }

    return PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id, 'content_type' => $type]);
}

function rvParents(FakeGraph $g): int
{
    return count(array_filter($g->callsTo('POST', '#^'.RV_IG.'/media$#'), fn ($c) => ($c['params']['media_type'] ?? '') === 'CAROUSEL'));
}

test('InstagramRevision #2: un padre de carrusel aun en proceso se guarda y NO se recrea en cada sondeo', function () {
    $target = rvTarget(ContentType::InstagramCarousel, 2);
    $this->graph->on('POST', '#^'.RV_IG.'/media$#', FakeGraph::seq(['id' => 'c1'], ['id' => 'c2'], ['id' => 'P1'], ['id' => 'P2'], ['id' => 'P3']))
        ->on('GET', '#^c\d$#', ['status_code' => 'FINISHED'])
        ->on('GET', '#^P1$#', FakeGraph::seq(['status_code' => 'IN_PROGRESS'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED']))
        ->on('POST', '#/media_publish$#', ['id' => '18900']);

    app(DispatchDuePosts::class)();
    expect($target->fresh()->status)->toBe(PostStatus::Processing)->and($target->fresh()->external_reference)->toBe('P1')
        ->and($target->fresh()->progress['parent'])->toBe('P1');

    for ($i = 0; $i < 4 && $target->fresh()->status === PostStatus::Processing; $i++) {
        Carbon::setTestNow(now()->addSeconds(61));
        app(DispatchDuePosts::class)();
    }

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('18900');
    expect(rvParents($this->graph))->toBe(1) // un unico contenedor padre
        ->and($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1)
        ->and($this->graph->callsTo('POST', '#^'.RV_IG.'/media$#'))->toHaveCount(3);
});

test('InstagramRevision #8: un carrusel de 10 se divide en pasos reanudables acotados por el presupuesto y no recrea hijos', function () {
    config(['publishing.step_budget_seconds' => 0]); // cada paso hace UNA llamada de red como maximo
    $target = rvTarget(ContentType::InstagramCarousel, 10);
    $this->graph->on('POST', '#^'.RV_IG.'/media$#', FakeGraph::seq(...array_map(fn ($i) => ['id' => "c$i"], range(1, 10)), ...[['id' => 'PARENT']]))
        ->on('GET', '#^(c\d+|PARENT)$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '19000']);

    $guard = 0;
    $maxCallsPerStep = 0;
    do {
        $before = count($this->graph->calls);
        app(DispatchDuePosts::class)();
        $maxCallsPerStep = max($maxCallsPerStep, count($this->graph->calls) - $before);
        Carbon::setTestNow(now()->addSeconds(31)); // intervalo de sondeo real (30 s)
    } while ($target->fresh()->status !== PostStatus::Published && ++$guard < 40);

    expect($target->fresh()->status)->toBe(PostStatus::Published)->and($target->fresh()->external_id)->toBe('19000');
    // Cada hijo se crea una sola vez (10) + un padre; nada de recrear al reanudar.
    expect($this->graph->callsTo('POST', '#^'.RV_IG.'/media$#'))->toHaveCount(11)
        ->and(rvParents($this->graph))->toBe(1)
        ->and($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1);
    // Con presupuesto 0 un paso (publish + el sondeo inmediato de la cola sync) hace unas pocas llamadas, no ~25.
    expect($maxCallsPerStep)->toBeLessThanOrEqual(4);
});

test('InstagramRevision #8: si un hijo falla a mitad, los ya creados se conservan y se reanuda sin recrearlos', function () {
    $target = rvTarget(ContentType::InstagramCarousel, 4);
    $this->graph->on('POST', '#^'.RV_IG.'/media$#', FakeGraph::seq(['id' => 'c1'], ['id' => 'c2'], new ConnectionException('timeout'), ['id' => 'c3'], ['id' => 'c4'], ['id' => 'PARENT']))
        ->on('GET', '#^(c\d+|PARENT)$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '19100']);

    app(DispatchDuePosts::class)();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->progress['children'])->toBe(['c1', 'c2']); // progreso guardado pese al fallo

    Carbon::setTestNow(now()->addSeconds(61));
    app(DispatchDuePosts::class)();
    Carbon::setTestNow(now()->addSeconds(61));
    app(DispatchDuePosts::class)();

    expect($target->fresh()->status)->toBe(PostStatus::Published);
    expect($this->graph->callsTo('POST', '#^'.RV_IG.'/media$#'))->toHaveCount(6); // 4 hijos (+1 intento fallido) + padre: c1 y c2 NO se recrean
});

test('InstagramRevision #5: la story guarda el contenedor antes de media_publish y tras un timeout se reconoce publicada sin duplicar', function () {
    $target = rvTarget(ContentType::InstagramStory, 1, null);
    $this->graph->on('POST', '#^'.RV_IG.'/media$#', FakeGraph::seq(['id' => '9800'], ['id' => '9801']))
        ->on('GET', '#^9800$#', FakeGraph::seq(['status_code' => 'FINISHED'], ['status_code' => 'PUBLISHED']))
        ->on('POST', '#/media_publish$#', new ConnectionException('cURL error 28: timeout'))
        ->on('GET', '#^'.RV_IG.'/stories$#', ['data' => [
            ['id' => '17900', 'permalink' => 'https://www.instagram.com/stories/x/17900', 'timestamp' => '2026-11-01T11:59:58+0000'],
            ['id' => '17001', 'permalink' => 'https://www.instagram.com/stories/x/17001', 'timestamp' => '2026-10-31T09:00:00+0000'],
        ]]);

    app(DispatchDuePosts::class)();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->external_reference)->toBe('9800'); // contenedor guardado ANTES del fallo

    Carbon::setTestNow(now()->addSeconds(61));
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('17900');
    expect($this->graph->callsTo('POST', '#^'.RV_IG.'/media$#'))->toHaveCount(1)->and($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1);
});

test('InstagramRevision #5: si la story ya no se lista, el contenedor PUBLISHED se da por publicado (nunca se republica)', function () {
    $this->graph->on('GET', '#^9810$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.RV_IG.'/stories$#', ['data' => []]);
    $request = new PublishRequest(77, ContentType::InstagramStory, null, [new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/a.jpg', 1080, 1920)], [], '9810', now()->subMinutes(5));

    $found = $this->ig->findPublished($this->account, $request);

    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('9810')->and($found->note)->toContain('enlace');
    expect($this->graph->callsTo('POST', '#.#'))->toBeEmpty();
});

test('InstagramRevision #17: el access_token viaja en la cabecera Authorization y nunca en la URL ni el cuerpo', function () {
    $this->graph->on('POST', '#^'.RV_IG.'/media$#', ['id' => '9900'])->on('GET', '#^9900$#', ['status_code' => 'FINISHED'])->on('POST', '#/media_publish$#', ['id' => '19900']);
    $request = new PublishRequest(77, ContentType::InstagramImage, 'Hola', [new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/a.jpg', 1080, 1080)], [], null, now()->subMinutes(5));

    $this->ig->publish($this->account, $request);

    expect($this->graph->calls)->not->toBeEmpty();
    foreach ($this->graph->calls as $call) {
        expect($call['authorization'])->toBe('Bearer page-token-xyz')->and($call['url'])->not->toContain('access_token')->and($call['url'])->not->toContain('page-token-xyz')
            ->and($call['params'])->not->toHaveKey('access_token');
    }
});
