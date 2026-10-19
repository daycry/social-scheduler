<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
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

const IG_ID = '17841400000000001';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => IG_ID, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->ig = new InstagramPublisher;
});

function igImage(string $name = 'a'): PublishMedia
{
    return new PublishMedia(1, 'image', 'image/jpeg', "https://cdn.test/$name.jpg", 1080, 1080);
}

function igVideo(string $name = 'v'): PublishMedia
{
    return new PublishMedia(2, 'video', 'video/mp4', "https://cdn.test/$name.mp4", 1080, 1920, 30.0, 30.0);
}

function igRequest(ContentType $type, array $media, ?string $text = 'Mi texto', ?string $reference = null, array $options = []): PublishRequest
{
    return new PublishRequest(77, $type, $text, $media, $options, $reference, now()->subMinutes(5));
}

/** Rutas comunes: cupo libre y permalink. */
function igBase(FakeGraph $g, int $used = 2): void
{
    $g->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => $used, 'config' => ['quota_total' => 25, 'quota_duration' => 86400]]]])
        ->on('GET', '#^18\d+$#', ['permalink' => 'https://www.instagram.com/p/ABC/']);
}

test('InstagramPublish: una imagen crea el contenedor, espera FINISHED y publica con ID y enlace (CA-06)', function () {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9001'])
        ->on('GET', '#^9001$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#^'.IG_ID.'/media_publish$#', ['id' => '18001']);

    $result = $this->ig->publish($this->account, igRequest(ContentType::InstagramImage, [igImage()]));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('18001')->and($result->url)->toBe('https://www.instagram.com/p/ABC/');
    $create = $this->graph->callsTo('POST', '#/media$#')[0]['params'];
    expect($create)->toMatchArray(['image_url' => 'https://cdn.test/a.jpg', 'caption' => 'Mi texto'])->not->toHaveKey('access_token'); // el token va en la cabecera, nunca en el cuerpo ni la query
    foreach ($this->graph->calls as $call) {
        expect($call['authorization'])->toBe('Bearer page-token-xyz')->and($call['url'])->not->toContain('access_token');
    }
    expect($this->graph->callsTo('POST', '#/media_publish$#')[0]['params'])->toMatchArray(['creation_id' => '9001']);
});

test('InstagramPublish: si el contenedor aun se procesa queda en proceso y el sondeo lo publica', function () {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9002'])
        ->on('GET', '#^9002$#', FakeGraph::seq(['status_code' => 'IN_PROGRESS'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED']))
        ->on('POST', '#/media_publish$#', ['id' => '18002']);

    $first = $this->ig->publish($this->account, igRequest(ContentType::InstagramImage, [igImage()]));
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('9002');

    expect($this->ig->poll($this->account, igRequest(ContentType::InstagramImage, [igImage()], reference: '9002'))->isProcessing())->toBeTrue();
    $done = $this->ig->poll($this->account, igRequest(ContentType::InstagramImage, [igImage()], reference: '9002'));
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('18002');
    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1);
});

test('InstagramPublish: el reel crea un contenedor REELS con share_to_feed y se publica cuando termina el video', function () {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9100'])
        ->on('GET', '#^9100$#', FakeGraph::seq(['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED']))
        ->on('POST', '#/media_publish$#', ['id' => '18100']);

    $r = $this->ig->publish($this->account, igRequest(ContentType::InstagramReel, [igVideo()]));
    expect($r->isProcessing())->toBeTrue();
    expect($this->graph->callsTo('POST', '#/media$#')[0]['params'])->toMatchArray(['media_type' => 'REELS', 'video_url' => 'https://cdn.test/v.mp4', 'share_to_feed' => 'true', 'caption' => 'Mi texto']);

    $done = $this->ig->poll($this->account, igRequest(ContentType::InstagramReel, [igVideo()], reference: '9100'));
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('18100');

    // share_to_feed desactivado.
    $this->graph->calls = [];
    $this->ig->publish($this->account, igRequest(ContentType::InstagramReel, [igVideo()], options: ['share_to_feed' => false]));
    expect($this->graph->callsTo('POST', '#/media$#')[0]['params']['share_to_feed'])->toBe('false');
});

test('InstagramPublish: la story (imagen o video) usa STORIES y no envia texto', function () {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9200'])
        ->on('GET', '#^9200$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '18200']);

    $this->ig->publish($this->account, igRequest(ContentType::InstagramStory, [igImage()], 'texto que se ignora'));
    $img = $this->graph->callsTo('POST', '#/media$#')[0]['params'];
    expect($img)->toMatchArray(['media_type' => 'STORIES', 'image_url' => 'https://cdn.test/a.jpg'])->and($img)->not->toHaveKey('caption');

    $this->graph->calls = [];
    $this->ig->publish($this->account, igRequest(ContentType::InstagramStory, [igVideo()]));
    expect($this->graph->callsTo('POST', '#/media$#')[0]['params'])->toMatchArray(['media_type' => 'STORIES', 'video_url' => 'https://cdn.test/v.mp4']);
});

test('InstagramPublish: el carrusel crea hijos, espera a que terminen y despues crea el contenedor padre', function () {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', FakeGraph::seq(['id' => 'c1'], ['id' => 'c2'], ['id' => 'parent']))
        ->on('GET', '#^c1$#', ['status_code' => 'FINISHED'])
        ->on('GET', '#^c2$#', FakeGraph::seq(['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED']))
        ->on('GET', '#^parent$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '18300']);

    $media = [igImage('1'), igVideo('2')];
    $first = $this->ig->publish($this->account, igRequest(ContentType::InstagramCarousel, $media));
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('carousel')
        ->and($first->progress)->toBe(['children' => ['c1', 'c2'], 'ready' => ['c1']]);

    $request = igRequest(ContentType::InstagramCarousel, $media, reference: 'carousel');
    $done = $this->ig->poll($this->account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, [], 'carousel', $request->since, $first->progress));
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('18300');

    $creates = $this->graph->callsTo('POST', '#/media$#');
    expect($creates)->toHaveCount(3)
        ->and($creates[0]['params'])->toMatchArray(['image_url' => 'https://cdn.test/1.jpg', 'is_carousel_item' => 'true'])
        ->and($creates[1]['params'])->toMatchArray(['media_type' => 'VIDEO', 'video_url' => 'https://cdn.test/2.mp4', 'is_carousel_item' => 'true'])
        ->and($creates[2]['params'])->toMatchArray(['media_type' => 'CAROUSEL', 'children' => 'c1,c2', 'caption' => 'Mi texto']);
});

test('InstagramPublish: un contenedor en ERROR falla con el motivo traducido', function () {
    igBase($this->graph);
    $this->graph->on('GET', '#^9300$#', ['status_code' => 'ERROR', 'status' => 'Error: Media upload has failed with error code 2207026']);

    $r = $this->ig->poll($this->account, igRequest(ContentType::InstagramReel, [igVideo()], reference: '9300'));

    expect($r->isFailed())->toBeTrue()->and($r->message)->toContain('formato del video no es admitido');
});

test('InstagramPublish: con el limite de 24 h agotado lanza cuota y no crea contenedores', function () {
    igBase($this->graph, used: 25);

    expect(fn () => $this->ig->publish($this->account, igRequest(ContentType::InstagramImage, [igImage()])))
        ->toThrow(QuotaExceededException::class, 'ya se han usado 25');
    expect($this->graph->callsTo('POST', '#/media$#'))->toBeEmpty();
});

test('InstagramPublish: si no se puede consultar el limite (permisos) se intenta publicar igualmente', function () {
    $this->graph->on('GET', '#/content_publishing_limit$#', FakeGraph::error(10, '(#10) Application does not have permission'))
        ->on('POST', '#/media$#', ['id' => '9400'])
        ->on('GET', '#^9400$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '18400'])
        ->on('GET', '#^18400$#', ['permalink' => 'https://www.instagram.com/p/Z/']);

    expect($this->ig->publish($this->account, igRequest(ContentType::InstagramImage, [igImage()]))->isPublished())->toBeTrue();
});

test('InstagramPublish: los errores de Graph se mapean a transitorios o permanentes', function (callable $error, string $class, ?string $internal) {
    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', $error());

    try {
        $this->ig->publish($this->account, igRequest(ContentType::InstagramImage, [igImage()]));
        $this->fail('debia lanzar');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class);
        if ($internal !== null) {
            expect($e->errorCode)->toBe($internal);
        }
    }
})->with([
    'token caducado (190)' => [fn () => FakeGraph::error(190, 'Error validating access token', null, 401), ReconnectRequiredException::class, null],
    'limite diario (2207042)' => [fn () => FakeGraph::error(9, 'Daily limit', 2207042), QuotaExceededException::class, 'cuota'],
    'formato de video (2207026)' => [fn () => FakeGraph::error(9004, 'Unsupported', 2207026), PermanentPublishException::class, 'video_no_admitido'],
    'descarga lenta (2207003)' => [fn () => FakeGraph::error(2207003, 'Timeout', 2207003), TransientPublishException::class, 'descarga_lenta'],
    'rate limit (4)' => [fn () => FakeGraph::error(4, 'Application request limit reached'), TransientPublishException::class, 'rate_limit'],
    'media no lista (9007)' => [fn () => FakeGraph::error(9007, 'Media ID is not available'), TransientPublishException::class, 'media_no_lista'],
    'sin permiso (10)' => [fn () => FakeGraph::error(10, '(#10) permission denied'), PermanentPublishException::class, 'permisos'],
    'servidor (500)' => [fn () => FakeGraph::error(2, 'Service temporarily unavailable', null, 500), TransientPublishException::class, 'meta_no_disponible'],
    'cuerpo rechazado (100)' => [fn () => FakeGraph::error(100, 'Invalid parameter'), PermanentPublishException::class, 'rechazada'],
    'timeout de red' => [fn () => new ConnectionException('cURL error 28'), TransientPublishException::class, 'red'],
]);

test('InstagramPublish: findPublished reconoce una publicacion ya hecha por su texto y hora, y no inventa duplicados', function () {
    $this->graph->on('GET', '#^'.IG_ID.'/media$#', ['data' => [
        ['id' => '1', 'caption' => 'Otra cosa', 'permalink' => 'https://i/1', 'timestamp' => '2026-11-01T11:58:00+0000'],
        ['id' => '2', 'caption' => 'Mi texto', 'permalink' => 'https://i/2', 'timestamp' => '2026-11-01T11:59:30+0000'],
        ['id' => '3', 'caption' => 'Mi texto', 'permalink' => 'https://i/3', 'timestamp' => '2026-10-20T10:00:00+0000'],
    ]]);

    $found = $this->ig->findPublished($this->account, igRequest(ContentType::InstagramImage, [igImage()]));
    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('2');

    expect($this->ig->findPublished($this->account, igRequest(ContentType::InstagramImage, [igImage()], 'Texto distinto')))->toBeNull();
    // Sin texto no se puede reconocer: se devuelve null sin consultar la lista.
    expect($this->ig->findPublished($this->account, igRequest(ContentType::InstagramImage, [igImage()], null)))->toBeNull();
});

test('InstagramPublish: con un contenedor ya PUBLISHED se resuelve con la publicacion existente', function () {
    $this->graph->on('GET', '#^9500$#', ['status_code' => 'PUBLISHED'])
        ->on('GET', '#^'.IG_ID.'/media$#', ['data' => [['id' => '55', 'caption' => 'Mi texto', 'permalink' => 'https://i/55', 'timestamp' => '2026-11-01T11:59:50+0000']]]);

    $found = $this->ig->findPublished($this->account, igRequest(ContentType::InstagramReel, [igVideo()], reference: '9500'));

    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('55');
    expect($this->graph->callsTo('POST', '#.#'))->toBeEmpty();
});

test('InstagramPublish: de extremo a extremo por la cola, un timeout en media_publish se resuelve sin duplicar', function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, 'x');
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->ig));

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi texto', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'content_type' => ContentType::InstagramImage]);

    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9600'])
        // Tras el timeout el contenedor consta como PUBLISHED: la red SI habia publicado.
        ->on('GET', '#^9600$#', FakeGraph::seq(['status_code' => 'FINISHED'], ['status_code' => 'PUBLISHED']))
        ->on('POST', '#/media_publish$#', new ConnectionException('cURL error 28: timeout'))
        // La red SI habia publicado: aparece en la media reciente.
        ->on('GET', '#^'.IG_ID.'/media$#', ['data' => [['id' => '18600', 'caption' => 'Mi texto', 'permalink' => 'https://www.instagram.com/p/Q/', 'timestamp' => '2026-11-01T11:59:59+0000']]]);

    app(DispatchDuePosts::class)();
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->error_code)->toBe('red');

    Carbon::setTestNow(now()->addSeconds(61));
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('18600')->and($t->external_url)->toBe('https://www.instagram.com/p/Q/');
    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1); // un unico intento de publicar
});

test('InstagramPublish: de extremo a extremo un reel pasa por procesando y acaba publicado', function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $asset = MediaAsset::factory()->video()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, 'x');
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->ig));

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Reel', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'content_type' => ContentType::InstagramReel]);

    igBase($this->graph);
    $this->graph->on('POST', '#^'.IG_ID.'/media$#', ['id' => '9700'])
        ->on('GET', '#^9700$#', FakeGraph::seq(['status_code' => 'IN_PROGRESS'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED']))
        ->on('POST', '#/media_publish$#', ['id' => '18700']);

    app(DispatchDuePosts::class)(); // publish -> procesando (+ sondeo inmediato en cola sync: sigue procesando)
    expect($target->fresh()->status)->toBe(PostStatus::Processing)->and($target->fresh()->external_reference)->toBe('9700');

    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)();

    expect($target->fresh()->status)->toBe(PostStatus::Published)->and($target->fresh()->external_id)->toBe('18700');
    expect($this->graph->callsTo('POST', '#/media$#'))->toHaveCount(1);
});
