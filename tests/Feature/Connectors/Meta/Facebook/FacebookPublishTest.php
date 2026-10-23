<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Facebook\FacebookPublisher;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;

const PAGE_ID = '1065269806670394';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => PAGE_ID, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->fb = new FacebookPublisher;
});

function fbPhoto(): PublishMedia
{
    return new PublishMedia(1, 'image', 'image/png', 'https://cdn.test/a.png', 1080, 1080);
}

function fbVideo(): PublishMedia
{
    return new PublishMedia(2, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 30.0, 30.0);
}

function fbRequest(ContentType $type, array $media = [], ?string $text = 'Hola Pagina', ?string $reference = null, array $options = []): PublishRequest
{
    return new PublishRequest(88, $type, $text, $media, $options, $reference, now()->subMinutes(5));
}

test('FacebookPublish: texto con enlace usa /feed con message y link y devuelve ID y enlace (CA-06)', function () {
    $this->graph->on('POST', '#^'.PAGE_ID.'/feed$#', ['id' => PAGE_ID.'_555']);

    $r = $this->fb->publish($this->account, fbRequest(ContentType::FacebookText, [], 'Mira esto', options: ['link' => 'https://ejemplo.test/post']));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe(PAGE_ID.'_555')->and($r->url)->toBe('https://www.facebook.com/'.PAGE_ID.'/posts/555');
    expect($this->graph->callsTo('POST', '#/feed$#')[0]['params'])->toMatchArray(['message' => 'Mira esto', 'link' => 'https://ejemplo.test/post'])->not->toHaveKey('access_token');
    expect($this->graph->callsTo('POST', '#/feed$#')[0]['authorization'])->toBe('Bearer page-token-xyz');
});

test('FacebookPublish: la foto usa /photos con url y caption (como el spike) y devuelve el post_id', function () {
    $this->graph->on('POST', '#^'.PAGE_ID.'/photos$#', ['id' => '7001', 'post_id' => PAGE_ID.'_7002']);

    $r = $this->fb->publish($this->account, fbRequest(ContentType::FacebookPhoto, [fbPhoto()]));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe(PAGE_ID.'_7002');
    expect($this->graph->callsTo('POST', '#/photos$#')[0]['params'])->toMatchArray(['url' => 'https://cdn.test/a.png', 'caption' => 'Hola Pagina', 'published' => 'true']);
});

test('FacebookPublish: el video usa /videos con file_url, queda en proceso y el sondeo lo publica', function () {
    $this->graph->on('POST', '#^'.PAGE_ID.'/videos$#', ['id' => '8001'])
        ->on('GET', '#^8001$#', FakeGraph::seq(
            ['status' => ['video_status' => 'processing']],
            ['status' => ['video_status' => 'ready'], 'permalink_url' => '/'.PAGE_ID.'/videos/8001/'],
        ));

    $first = $this->fb->publish($this->account, fbRequest(ContentType::FacebookVideo, [fbVideo()]));
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('8001');
    expect($this->graph->callsTo('POST', '#/videos$#')[0]['params'])->toMatchArray(['file_url' => 'https://cdn.test/v.mp4', 'description' => 'Hola Pagina']);

    expect($this->fb->poll($this->account, fbRequest(ContentType::FacebookVideo, [fbVideo()], reference: '8001'))->isProcessing())->toBeTrue();
    $done = $this->fb->poll($this->account, fbRequest(ContentType::FacebookVideo, [fbVideo()], reference: '8001'));
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('8001')->and($done->url)->toBe('https://www.facebook.com/'.PAGE_ID.'/videos/8001/');
});

test('FacebookPublish: el reel abre sesion, sube por file_url con cabeceras y cierra publicando', function () {
    $this->graph->on('POST', '#^'.PAGE_ID.'/video_reels$#', FakeGraph::seq(
        ['video_id' => '9001', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/9001'],
        ['success' => true],
    ))
        ->on('POST', '#^video-upload/v23.0/9001$#', ['success' => true])
        ->on('GET', '#^9001$#', ['status' => ['video_status' => 'ready', 'publishing_phase' => ['status' => 'complete']], 'permalink_url' => '/reel/9001']);

    $r = $this->fb->publish($this->account, fbRequest(ContentType::FacebookReel, [fbVideo()]));
    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('9001');

    $calls = $this->graph->calls;
    expect($calls[0]['params'])->toMatchArray(['upload_phase' => 'start'])
        ->and($calls[2]['params'])->toMatchArray(['upload_phase' => 'finish', 'video_id' => '9001', 'video_state' => 'PUBLISHED', 'description' => 'Hola Pagina']);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'rupload.facebook.com') && $req->hasHeader('file_url', 'https://cdn.test/v.mp4') && $req->hasHeader('Authorization', 'OAuth page-token-xyz'));

    $done = $this->fb->poll($this->account, fbRequest(ContentType::FacebookReel, [fbVideo()], reference: '9001'));
    expect($done->isPublished())->toBeTrue()->and($done->url)->toBe('https://www.facebook.com/reel/9001');
});

test('FacebookPublish: no sube a URLs de subida que no sean de Meta', function () {
    $this->graph->on('POST', '#/video_reels$#', ['video_id' => '9002', 'upload_url' => 'https://evil.example.com/steal']);

    expect(fn () => $this->fb->publish($this->account, fbRequest(ContentType::FacebookReel, [fbVideo()])))
        ->toThrow(PermanentPublishException::class, 'URL de subida no valida');
    expect($this->graph->callsTo('POST', '#steal#'))->toBeEmpty();
});

test('FacebookPublish: un video con error de procesamiento falla con el motivo', function () {
    $this->graph->on('GET', '#^8002$#', ['status' => ['video_status' => 'error', 'processing_phase' => ['status' => 'error', 'error' => ['message' => 'Formato no admitido']]]]);

    $r = $this->fb->poll($this->account, fbRequest(ContentType::FacebookVideo, [fbVideo()], reference: '8002'));

    expect($r->isFailed())->toBeTrue()->and($r->message)->toContain('Formato no admitido');
});

test('FacebookPublish: los errores de Graph se mapean a transitorios o permanentes', function (callable $error, string $class) {
    $this->graph->on('POST', '#/photos$#', $error());

    expect(fn () => $this->fb->publish($this->account, fbRequest(ContentType::FacebookPhoto, [fbPhoto()])))->toThrow($class);
})->with([
    'token (190)' => [fn () => FakeGraph::error(190, 'Invalid OAuth access token', null, 401), ReconnectRequiredException::class],
    'sin permiso (200)' => [fn () => FakeGraph::error(200, '(#200) Requires pages_manage_posts permission'), PermanentPublishException::class],
    'rate limit (32)' => [fn () => FakeGraph::error(32, 'Page request limit reached'), TransientPublishException::class],
    'servidor (503)' => [fn () => FakeGraph::error(2, 'Unavailable', null, 503), TransientPublishException::class],
    'parametro (100)' => [fn () => FakeGraph::error(100, 'Invalid parameter'), PermanentPublishException::class],
    'timeout' => [fn () => new ConnectionException('cURL error 28'), TransientPublishException::class],
]);

test('FacebookPublish: findPublished reconoce lo ya publicado por texto y hora, y un video por su ID', function () {
    $this->graph->on('GET', '#^'.PAGE_ID.'/posts$#', ['data' => [
        ['id' => PAGE_ID.'_1', 'message' => 'Otro', 'created_time' => '2026-11-01T11:58:00+0000'],
        ['id' => PAGE_ID.'_2', 'message' => 'Hola Pagina', 'created_time' => '2026-11-01T11:59:40+0000', 'permalink_url' => 'https://www.facebook.com/p/2'],
        ['id' => PAGE_ID.'_3', 'message' => 'Hola Pagina', 'created_time' => '2026-10-01T11:59:40+0000'],
    ]])->on('GET', '#^8003$#', ['status' => ['video_status' => 'processing']]);

    $found = $this->fb->findPublished($this->account, fbRequest(ContentType::FacebookText, [], 'Hola Pagina'));
    expect($found->externalId)->toBe(PAGE_ID.'_2')->and($found->url)->toBe('https://www.facebook.com/p/2');

    expect($this->fb->findPublished($this->account, fbRequest(ContentType::FacebookText, [], 'Nada que ver')))->toBeNull();
    expect($this->fb->findPublished($this->account, fbRequest(ContentType::FacebookVideo, [fbVideo()], reference: '8003'))->isProcessing())->toBeTrue();
});

test('FacebookPublish: de extremo a extremo por la cola los 4 tipos quedan publicados con ID externo (CA-06)', function (ContentType $type, string $mediaKind, array $routes) {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Facebook, $this->fb));

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Texto FB', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    if ($mediaKind !== 'none') {
        $asset = $mediaKind === 'video' ? MediaAsset::factory()->video()->for($this->user)->create() : MediaAsset::factory()->for($this->user)->create();
        Storage::disk('local')->put($asset->path, 'x');
        $post->media()->attach($asset->id, ['position' => 0]);
    }
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'network' => Network::Facebook, 'content_type' => $type]);

    foreach ($routes as [$method, $pattern, $response]) {
        $this->graph->on($method, $pattern, $response);
    }

    app(DispatchDuePosts::class)();
    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)();

    expect($target->fresh()->status)->toBe(PostStatus::Published)->and($target->fresh()->external_id)->not->toBeNull();
})->with([
    'texto' => [ContentType::FacebookText, 'none', [['POST', '#/feed$#', ['id' => PAGE_ID.'_1']]]],
    'foto' => [ContentType::FacebookPhoto, 'image', [['POST', '#/photos$#', ['id' => '1', 'post_id' => PAGE_ID.'_2']]]],
    'video' => [ContentType::FacebookVideo, 'video', [['POST', '#/videos$#', ['id' => '3']], ['GET', '#^3$#', ['status' => ['video_status' => 'ready'], 'permalink_url' => '/v/3']]]],
    'reel' => [ContentType::FacebookReel, 'video', [
        ['POST', '#/video_reels$#', FakeGraph::seq(['video_id' => '4', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/4'], ['success' => true])],
        ['POST', '#^video-upload/v23.0/4$#', ['success' => true]],
        ['GET', '#^4$#', ['status' => ['video_status' => 'ready'], 'permalink_url' => '/reel/4']],
    ]],
]);
