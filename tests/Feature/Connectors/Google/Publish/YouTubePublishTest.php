<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Google\YouTubeQuota;
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
use App\Services\PostService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHttp;

const YT_CHANNEL = 'UCabcdefghij0123456789';
const YT_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESS1';
const YT_CHUNK = 256 * 1024;
const YT_SIZE = 600 * 1024; // 3 trozos: 256 KiB + 256 KiB + 88 KiB

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.youtube.chunk_bytes' => YT_CHUNK, 'social.youtube.daily_quota' => 10000, 'social.youtube.upload_cost' => 1600, 'social.youtube.upload_budget_seconds' => 80]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => YT_CHANNEL, 'access_token' => 'yt-access-token',
    ]);
    $this->http = new FakeHttp;
    $this->yt = new YouTubePublisher;

    $this->asset = MediaAsset::factory()->video(duration: 45)->for($this->user)->create(['size' => YT_SIZE]);
    Storage::disk('local')->put($this->asset->path, str_repeat('v', YT_SIZE));
});

function ytMedia(): PublishMedia
{
    $a = test()->asset;

    return new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 45.0, 30.0, YT_SIZE, $a->disk, $a->path);
}

function ytRequest(ContentType $type = ContentType::YouTubeVideo, ?string $text = "Mi titulo\nDescripcion del video", array $options = [], ?string $reference = null): PublishRequest
{
    return new PublishRequest(77, $type, $text, [ytMedia()], $options, $reference, now()->subMinutes(5));
}

/** Rutas felices de una subida de 3 trozos que termina con el video `VID1`. */
function ytHappyUpload(FakeHttp $http): void
{
    $http->on('POST', '#upload/youtube/v3/videos\?uploadType=resumable&part=snippet,status$#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],
            ['status' => 200, 'json' => ['id' => 'VID1', 'status' => ['uploadStatus' => 'uploaded']]],
        ));
}

test('YouTubePublish: sube en trozos reanudables leidos en streaming y deja el video procesando (CA-06)', function () {
    ytHappyUpload($this->http);

    $r = $this->yt->publish($this->account, ytRequest());

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('VID1');

    $puts = $this->http->callsTo('PUT', '#upload_id=SESS1$#');
    expect($puts)->toHaveCount(3);
    expect(array_column($puts, 'headers'))->each->toHaveKey('authorization');
    expect(array_map(fn ($c) => $c['headers']['content-range'], $puts))->toBe([
        'bytes 0-262143/'.YT_SIZE, 'bytes 262144-524287/'.YT_SIZE, 'bytes 524288-'.(YT_SIZE - 1).'/'.YT_SIZE,
    ]);
    // Streaming: ninguna peticion lleva mas que un trozo (el video de 600 KiB nunca viaja ni se carga entero).
    expect(max(array_column($puts, 'body_length')))->toBeLessThanOrEqual(YT_CHUNK)->and(array_sum(array_column($puts, 'body_length')))->toBe(YT_SIZE);
});

test('YouTubePublish: la sesion pide part=snippet,status con la longitud y la visibilidad pedidas (public no se fuerza a privado)', function (?string $asked, string $sent) {
    ytHappyUpload($this->http);

    $this->yt->publish($this->account, ytRequest(options: $asked === null ? [] : ['privacy' => $asked]));

    $init = $this->http->callsTo('POST', '#uploadType=resumable#')[0];
    expect($init['headers']['x-upload-content-length'])->toBe((string) YT_SIZE)->and($init['headers']['x-upload-content-type'])->toBe('video/mp4');
    expect($init['json']['status'])->toBe(['privacyStatus' => $sent, 'selfDeclaredMadeForKids' => false]);
    expect($init['json']['snippet']['title'])->toBe('Mi titulo')->and($init['json']['snippet']['description'])->toBe("Mi titulo\nDescripcion del video");
})->with([
    'por defecto publico' => [null, 'public'],
    'publico pedido' => ['public', 'public'],
    'no listado' => ['unlisted', 'unlisted'],
    'privado' => ['private', 'private'],
    'valor desconocido -> privado (lo mas seguro)' => ['otra-cosa', 'private'],
]);

test('YouTubePublish: el short anade #Shorts a la descripcion salvo que se desactive y el video no', function () {
    ytHappyUpload($this->http);
    $this->yt->publish($this->account, ytRequest(ContentType::YouTubeShort, 'Hola short'));
    expect($this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['description'])->toBe("Hola short\n\n#Shorts");

    $this->http->calls = [];
    $this->yt->publish($this->account, ytRequest(ContentType::YouTubeShort, 'Hola short', ['shorts_hashtag' => false]));
    expect($this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['description'])->toBe('Hola short');

    $this->http->calls = [];
    $this->yt->publish($this->account, ytRequest(ContentType::YouTubeVideo, 'Hola video'));
    expect($this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['description'])->toBe('Hola video');
});

test('YouTubePublish: el titulo sale de options.title o de la primera linea y se recorta a 100 sin < >', function () {
    ytHappyUpload($this->http);
    $this->yt->publish($this->account, ytRequest(text: 'x', options: ['title' => '<b>'.str_repeat('a', 150).'</b>']));

    $title = $this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['title'];
    expect(mb_strlen($title))->toBe(100)->and($title)->not->toContain('<');
});

test('YouTubePublish: el sondeo pasa de procesando a publicada con enlace (video y short)', function (ContentType $type, string $url) {
    $this->http->on('GET', '#youtube/v3/videos\?#', FakeHttp::seq(
        ['json' => ['items' => [['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'processing']]]]],
        ['json' => ['items' => [['status' => ['uploadStatus' => 'processed'], 'processingDetails' => ['processingStatus' => 'succeeded']]]]],
    ));

    expect($this->yt->poll($this->account, ytRequest($type, reference: 'VID1'))->isProcessing())->toBeTrue();

    $done = $this->yt->poll($this->account, ytRequest($type, reference: 'VID1'));
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('VID1')->and($done->url)->toBe($url);
    expect($this->http->callsTo('GET', '#youtube/v3/videos\?#')[0]['url'])->toContain('part=status%2CprocessingDetails')->toContain('id=VID1');
})->with([
    'video' => [ContentType::YouTubeVideo, 'https://www.youtube.com/watch?v=VID1'],
    'short' => [ContentType::YouTubeShort, 'https://www.youtube.com/shorts/VID1'],
]);

test('YouTubePublish: un video rechazado o con procesamiento fallido acaba fallido con el motivo', function (array $item, string $expected) {
    $this->http->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [$item]]]);

    $r = $this->yt->poll($this->account, ytRequest(reference: 'VID1'));
    expect($r->isFailed())->toBeTrue()->and($r->message)->toContain($expected);
})->with([
    'rechazado por copyright' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'copyright']], 'derechos de autor'],
    'fallo de codec' => [['status' => ['uploadStatus' => 'failed', 'failureReason' => 'codec']], 'codec no admitido'],
    'procesamiento terminado' => [['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'terminated']], 'no pudo procesar'],
]);

test('YouTubePublish: cuota agotada en local no llama a la red y sigue la politica de T-25 (reintento tras el reinicio)', function () {
    $quota = new YouTubeQuota;
    $quota->charge(9000); // quedan 1.000 < 1.600

    try {
        $this->yt->publish($this->account, ytRequest());
        $this->fail('Debia lanzar QuotaExceededException');
    } catch (QuotaExceededException $e) {
        expect($e->retryAt)->not->toBeNull()->and($e->retryAt->isFuture())->toBeTrue()->and($e->getMessage())->toContain('cupo diario');
    }

    expect($this->http->calls)->toBe([]);
});

test('YouTubePublish: cada subida abierta consume ~1.600 ud del cupo y a la 7a ya no cabe', function () {
    $quota = new YouTubeQuota;

    foreach (range(1, 6) as $i) {
        ytHappyUpload($this->http);
        $this->yt->publish($this->account, ytRequest());
        expect($quota->used())->toBe($i * 1600);
        $this->http = new FakeHttp; // rutas nuevas para la siguiente subida
    }

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow(QuotaExceededException::class);
});

test('YouTubePublish: si la API dice quotaExceeded se marca el dia agotado y se aplaza', function () {
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 403, 'json' => ['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]]]);

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow(QuotaExceededException::class);
    expect((new YouTubeQuota)->used())->toBe(10000);
});

test('YouTubePublish: errores de la API se clasifican (credenciales, permisos, contenido, 5xx, red)', function (array|Throwable $response, string $class) {
    $this->http->on('POST', '#uploadType=resumable#', $response);

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow($class);
})->with([
    '401 -> reconectar' => [['status' => 401, 'json' => ['error' => ['message' => 'Invalid Credentials']]], ReconnectRequiredException::class],
    '403 permisos -> permanente' => [['status' => 403, 'json' => ['error' => ['message' => 'x', 'errors' => [['reason' => 'forbidden']]]]], PermanentPublishException::class],
    '400 contenido -> permanente' => [['status' => 400, 'json' => ['error' => ['message' => 'invalid title']]], PermanentPublishException::class],
    '503 -> transitorio' => [['status' => 503, 'json' => ['error' => ['message' => 'backend']]], TransientPublishException::class],
    'sin red -> transitorio' => [new ConnectionException('timeout'), TransientPublishException::class],
]);

test('YouTubePublish: la sesion debe vivir en un host de Google', function () {
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => 'https://evil.test/upload']]);

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow(PermanentPublishException::class, 'sesion de subida valida');
});

test('YouTubePublish: un corte a mitad de subida pregunta a Google cuanto tiene y continua desde ahi (reanudable)', function () {
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // trozo 1 ok
            ['status' => 503],                                                            // trozo 2 falla
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // consulta: Google tiene el 1
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],  // trozo 2 reenviado
            ['status' => 200, 'json' => ['id' => 'VID2']],                                // trozo 3
        ));

    $r = $this->yt->publish($this->account, ytRequest());

    expect($r->reference)->toBe('VID2');
    $ranges = array_map(fn ($c) => $c['headers']['content-range'], $this->http->callsTo('PUT', '#upload_id=SESS1$#'));
    expect($ranges[2])->toBe('bytes */'.YT_SIZE)->and($ranges[3])->toBe('bytes 262144-524287/'.YT_SIZE);
});

test('YouTubePublish: demasiados cortes seguidos acaban en error transitorio (la cola reintenta)', function () {
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', ['status' => 500]);

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow(TransientPublishException::class);
});

test('YouTubePublish: sin presupuesto de tiempo la subida se corta y el sondeo la continua desde lo que Google tenga', function () {
    config(['social.youtube.upload_budget_seconds' => 0]);
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // publish: 1 trozo y corta
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // poll: consulta de estado
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],  // poll: trozo 2 y corta
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],  // poll: consulta
            ['status' => 200, 'json' => ['id' => 'VID3']],                                // poll: trozo 3
        ));

    $first = $this->yt->publish($this->account, ytRequest());
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe(YouTubePublisher::UPLOADING);

    $second = $this->yt->poll($this->account, ytRequest(reference: YouTubePublisher::UPLOADING));
    expect($second->reference)->toBe(YouTubePublisher::UPLOADING);

    $third = $this->yt->poll($this->account, ytRequest(reference: YouTubePublisher::UPLOADING));
    expect($third->reference)->toBe('VID3');
    expect(Cache::get('youtube:upload:77'))->toBeNull();
    // Cada pasada sigue leyendo por trozos: nada supera un trozo.
    expect(max(array_column($this->http->callsTo('PUT', '#upload_id=SESS1$#'), 'body_length')))->toBeLessThanOrEqual(YT_CHUNK);
});

test('YouTubePublish: si se pierde la sesion guardada el sondeo falla con motivo claro', function () {
    expect(fn () => $this->yt->poll($this->account, ytRequest(reference: YouTubePublisher::UPLOADING)))->toThrow(PermanentPublishException::class, 'sesion de subida');
});

test('YouTubePublish: findPublished localiza un video ya subido por titulo y hora, y no inventa uno', function () {
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', ['json' => ['items' => [
        ['snippet' => ['title' => 'Otro', 'publishedAt' => '2026-11-01T11:59:00Z', 'resourceId' => ['videoId' => 'X1']]],
        ['snippet' => ['title' => 'Mi titulo', 'publishedAt' => '2026-10-01T11:59:00Z', 'resourceId' => ['videoId' => 'OLD']]],
        ['snippet' => ['title' => 'Mi titulo', 'publishedAt' => '2026-11-01T11:59:30Z', 'resourceId' => ['videoId' => 'VID9']]],
    ]]])->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    $found = $this->yt->findPublished($this->account, ytRequest());
    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('VID9');
    expect($this->http->callsTo('GET', '#playlistItems#')[0]['url'])->toContain('playlistId=UU'.substr(YT_CHANNEL, 2));

    expect($this->yt->findPublished($this->account, ytRequest(text: 'Titulo distinto')))->toBeNull();
});

test('YouTubePublish: si la consulta de idempotencia falla, no se bloquea (null) y con referencia se sondea', function () {
    $this->http->on('GET', '#playlistItems#', ['status' => 500]);
    expect($this->yt->findPublished($this->account, ytRequest()))->toBeNull();

    $this->http->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [['status' => ['uploadStatus' => 'uploaded']]]]]);
    expect($this->yt->findPublished($this->account, ytRequest(reference: 'VID1'))->isProcessing())->toBeTrue();
});

test('YouTubePublish: borrar un video usa el scope youtube y distingue borrado, inexistente y error', function () {
    $this->http->on('DELETE', '#youtube/v3/videos\?#', FakeHttp::seq(['status' => 204], ['status' => 404], ['status' => 403, 'json' => ['error' => ['message' => 'x']]]));

    expect($this->yt->deleteVideo($this->account, 'VID1'))->toBeTrue()->and($this->yt->deleteVideo($this->account, 'VID1'))->toBeFalse();
    expect(fn () => $this->yt->deleteVideo($this->account, 'VID1'))->toThrow(PermanentPublishException::class);
    expect($this->http->callsTo('DELETE', '#videos#')[0]['url'])->toContain('id=VID1');
});

test('YouTubePublish: sin video o con tipo ajeno falla de forma permanente', function () {
    $noMedia = new PublishRequest(1, ContentType::YouTubeVideo, 'x', [], [], null, now());
    expect(fn () => $this->yt->publish($this->account, $noMedia))->toThrow(PermanentPublishException::class, 'video');

    $wrong = new PublishRequest(1, ContentType::FacebookText, 'x', [ytMedia()], [], null, now());
    expect(fn () => $this->yt->publish($this->account, $wrong))->toThrow(PermanentPublishException::class, 'no admitido');
});

test('YouTubePublish: de extremo a extremo por la cola el video queda publicado con enlace (E2E-06)', function () {
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::YouTube, $this->yt));
    ytHappyUpload($this->http);
    $this->http->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi titulo', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($this->asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id,
        'network' => Network::YouTube, 'content_type' => ContentType::YouTubeShort, 'options' => ['privacy' => 'public'],
    ]);

    app(DispatchDuePosts::class)();
    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)();

    $done = $target->fresh();
    expect($done->status)->toBe(PostStatus::Published)->and($done->external_id)->toBe('VID1')->and($done->external_url)->toBe('https://www.youtube.com/shorts/VID1');
});

test('YouTubePublish: con la cuota agotada la politica reschedule aplaza el destino al reinicio y fail lo da por fallido (T-25)', function (string $policy, PostStatus $expected) {
    config(['publishing.quota_policy' => $policy]);
    (new YouTubeQuota)->markExhausted();
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::YouTube, $this->yt));

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi titulo', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($this->asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id,
        'network' => Network::YouTube, 'content_type' => ContentType::YouTubeVideo,
    ]);

    app(DispatchDuePosts::class)();

    $fresh = $target->fresh();
    expect($fresh->status)->toBe($expected)->and($fresh->error_code)->toBe('cuota');
    if ($expected === PostStatus::Scheduled) {
        expect($fresh->next_attempt_at->isFuture())->toBeTrue()->and($fresh->attempts)->toBe(0);
    }
    expect($this->http->calls)->toBe([]);
})->with([
    'reschedule' => ['reschedule', PostStatus::Scheduled],
    'fail' => ['fail', PostStatus::Failed],
]);

test('YouTubePublish: la subida repartida en varias pasadas de sondeo persiste la referencia (subiendo -> ID) y acaba publicada, sin sesion_perdida (revision F4-5)', function () {
    config(['social.youtube.upload_budget_seconds' => 0]); // cada pasada sube UN trozo y devuelve «subiendo»
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::YouTube, $this->yt));
    $this->http->on('POST', '#upload/youtube/v3/videos\?uploadType=resumable&part=snippet,status$#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],            // publish: trozo 1
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],            // poll 1: consulta de offset
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],        // poll 1: trozo 2
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],        // poll 2: consulta de offset
            ['status' => 200, 'json' => ['id' => 'VID1', 'status' => ['uploadStatus' => 'uploaded']]], // poll 2: trozo 3 -> termina DENTRO del sondeo
        ))
        ->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi titulo', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($this->asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id,
        'network' => Network::YouTube, 'content_type' => ContentType::YouTubeVideo,
    ]);

    app(DispatchDuePosts::class)(); // publish (trozo 1) + sondeo inmediato (cola sync, trozo 2): sigue subiendo
    expect($target->fresh()->status)->toBe(PostStatus::Processing)->and($target->fresh()->external_reference)->toBe('subiendo');

    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)(); // sondeo: trozo 3, YouTube devuelve el ID DENTRO del sondeo
    expect($target->fresh()->status)->toBe(PostStatus::Processing)->and($target->fresh()->external_reference)->toBe('VID1');

    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)(); // sondeo: ya con la referencia del video
    $done = $target->fresh();
    expect($done->status)->toBe(PostStatus::Published)->and($done->external_id)->toBe('VID1')->and($done->error_code)->toBeNull();
});

test('YouTubePublish: reprogramar un destino fallido olvida la referencia, los intentos y la sesion de subida cacheada (revision F3 #3 / F4-5)', function () {
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi titulo', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Failed]);
    $post->media()->attach($this->asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'network' => Network::YouTube,
        'content_type' => ContentType::YouTubeVideo, 'status' => PostStatus::Failed, 'attempts' => 4, 'external_reference' => 'subiendo',
        'error_code' => 'sesion_perdida', 'error_message' => 'x',
    ]);
    Cache::put('youtube:upload:'.$target->id, YT_SESSION, now()->addDay());

    app(PostService::class)->reschedule($this->user, $post->fresh(), now()->addMinutes(5));

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(PostStatus::Scheduled)->and($fresh->external_reference)->toBeNull()->and($fresh->attempts)->toBe(0)
        ->and($fresh->error_code)->toBeNull()->and($fresh->started_at)->toBeNull();
    expect(Cache::has('youtube:upload:'.$target->id))->toBeFalse();
});
