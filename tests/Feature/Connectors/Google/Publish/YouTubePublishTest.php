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
use App\Jobs\PollPublishStatus;
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

test('YouTubePublish: findPublished localiza SU video por el tag del destino (no por titulo) y no inventa uno (revision F4-5 #4)', function () {
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', ['json' => ['items' => [
        ['snippet' => ['title' => 'Mi titulo', 'publishedAt' => '2026-11-01T11:59:00Z', 'resourceId' => ['videoId' => 'AJENO']]], // mismo titulo, otro destino
        ['snippet' => ['title' => 'Mi titulo', 'publishedAt' => '2026-10-01T11:59:00Z', 'resourceId' => ['videoId' => 'OLD']]],     // anterior a `since`
        ['snippet' => ['title' => 'Mi titulo', 'publishedAt' => '2026-11-01T11:59:30Z', 'resourceId' => ['videoId' => 'VID9']]],
    ]]])->on('GET', '#youtube/v3/videos\?part=snippet&id=#', ['json' => ['items' => [
        ['id' => 'AJENO', 'snippet' => ['tags' => [YouTubePublisher::targetTag(12)]]],
        ['id' => 'VID9', 'snippet' => ['tags' => ['otro', YouTubePublisher::targetTag(77)]]],
    ]]])->on('GET', '#youtube/v3/videos\?part=status#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    $found = $this->yt->findPublished($this->account, ytRequest());
    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('VID9');
    expect($this->http->callsTo('GET', '#playlistItems#')[0]['url'])->toContain('playlistId=UU'.substr(YT_CHANNEL, 2));
    expect($this->http->callsTo('GET', '#videos\?part=snippet#')[0]['url'])->toContain('id=AJENO%2CVID9')->not->toContain('OLD');

    // Otro destino con el MISMO titulo no se queda con el video de este.
    $other = new PublishRequest(78, ContentType::YouTubeVideo, "Mi titulo\nx", [ytMedia()], [], null, now()->subMinutes(5));
    expect($this->yt->findPublished($this->account, $other))->toBeNull();
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

test('YouTubePublish N7: la sesion marca el video con un tag OPACO por destino que no expone el id secuencial (revision F4-5 i2 N7)', function () {
    ytHappyUpload($this->http);
    $this->yt->publish($this->account, ytRequest());

    $tags = $this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['tags'];
    expect($tags)->toBe([YouTubePublisher::targetTag(77)])
        ->and($tags[0])->toMatch('/^ss-[0-9a-f]{16}$/')->not->toBe('ss-77')
        ->and(YouTubePublisher::targetTag(77))->not->toBe(YouTubePublisher::targetTag(78));
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    expect(YouTubePublisher::targetTag(77))->not->toBe($tags[0]); // depende de APP_KEY: no se deduce del id
});

test('YouTubePublish N5: la idempotencia pagina las subidas desde `since` con tope de paginas (revision F4-5 i2 N5)', function () {
    $item = fn (string $id, string $when) => ['snippet' => ['publishedAt' => $when, 'resourceId' => ['videoId' => $id]]];
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', FakeHttp::seq(
        ['json' => ['nextPageToken' => 'P2', 'items' => [$item('A1', '2026-11-01T11:59:50Z'), $item('A2', '2026-11-01T11:59:40Z')]]],
        ['json' => ['nextPageToken' => 'P3', 'items' => [$item('B1', '2026-11-01T11:59:30Z'), $item('MIO', '2026-11-01T11:59:20Z')]]],
    ))->on('GET', '#youtube/v3/videos\?part=snippet&id=#', FakeHttp::seq(
        ['json' => ['items' => [['id' => 'A1', 'snippet' => ['tags' => []]], ['id' => 'A2', 'snippet' => ['tags' => []]]]]],
        ['json' => ['items' => [['id' => 'B1', 'snippet' => ['tags' => []]], ['id' => 'MIO', 'snippet' => ['tags' => [YouTubePublisher::targetTag(77)]]]]]],
    ))->on('GET', '#youtube/v3/videos\?part=status#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    expect($this->yt->findPublished($this->account, ytRequest())->externalId)->toBe('MIO');
    $lists = $this->http->callsTo('GET', '#playlistItems#');
    expect($lists)->toHaveCount(2)->and($lists[1]['url'])->toContain('pageToken=P2');
});

test('YouTubePublish N5: se detiene al llegar a un video anterior a `since` o en el tope de paginas', function () {
    $item = fn (string $id, string $when) => ['snippet' => ['publishedAt' => $when, 'resourceId' => ['videoId' => $id]]];

    // Pagina 1 ya contiene uno anterior a `since` -> no se pide la siguiente aunque haya token.
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', ['json' => ['nextPageToken' => 'P2', 'items' => [$item('N1', '2026-11-01T11:59:50Z'), $item('OLD', '2026-01-01T00:00:00Z')]]])
        ->on('GET', '#youtube/v3/videos\?part=snippet&id=#', ['json' => ['items' => [['id' => 'N1', 'snippet' => ['tags' => []]]]]]);
    expect($this->yt->findPublished($this->account, ytRequest()))->toBeNull()->and($this->http->callsTo('GET', '#playlistItems#'))->toHaveCount(1);

});

test('YouTubePublish N5: el tope de paginas (lookup_max_pages) acota el coste de cuota', function () {
    $item = fn (string $id, string $when) => ['snippet' => ['publishedAt' => $when, 'resourceId' => ['videoId' => $id]]];
    config(['social.youtube.lookup_max_pages' => 2]);
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', ['json' => ['nextPageToken' => 'MAS', 'items' => [$item('N2', '2026-11-01T11:59:50Z')]]])
        ->on('GET', '#youtube/v3/videos\?part=snippet&id=#', ['json' => ['items' => [['id' => 'N2', 'snippet' => ['tags' => []]]]]]);
    expect($this->yt->findPublished($this->account, ytRequest()))->toBeNull()->and($this->http->callsTo('GET', '#playlistItems#'))->toHaveCount(2);
});

test('YouTubePublish: la consulta de offset con 200/201 toma el id del cuerpo, sin depender de la lista de subidas (revision F4-5 #3)', function () {
    config(['social.youtube.upload_budget_seconds' => 0]);
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],                    // publish: trozo 1 y corta
            ['status' => 201, 'json' => ['id' => 'VIDQ', 'status' => ['uploadStatus' => 'uploaded']]], // sondeo: la consulta ya devuelve el video
        ));
    $saved = [];
    $req = new PublishRequest(77, ContentType::YouTubeVideo, 'Mi titulo', [ytMedia()], [], YouTubePublisher::UPLOADING, now()->subMinutes(5), [], function ($ref) use (&$saved) {
        $saved[] = $ref;
    });

    $this->yt->publish($this->account, ytRequest());
    $r = $this->yt->poll($this->account, $req);

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('VIDQ')->and($saved)->toBe(['VIDQ']); // ID guardado en el acto (checkpoint)
    expect($this->http->callsTo('GET', '#playlistItems#'))->toBe([]); // ni se mira la playlist
    expect(Cache::get('youtube:upload:77'))->toBeNull();
});

test('YouTubePublish: un reintento de publish reutiliza la sesion cacheada: no abre otra ni vuelve a cobrar cuota (revision F4-5 #5)', function () {
    config(['social.youtube.upload_budget_seconds' => 0]);
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // 1a pasada: trozo 1
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],      // reintento: consulta -> Google tiene el 1
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],  // reintento: trozo 2
        ));

    $this->yt->publish($this->account, ytRequest());
    $this->yt->publish($this->account, ytRequest()); // el job reintenta (p. ej. tras un error transitorio)

    expect($this->http->callsTo('POST', '#uploadType=resumable#'))->toHaveCount(1)->and((new YouTubeQuota)->used())->toBe(1600);
    $ranges = array_map(fn ($c) => $c['headers']['content-range'], $this->http->callsTo('PUT', '#upload_id=SESS1$#'));
    expect($ranges[1])->toBe('bytes */'.YT_SIZE)->and($ranges[2])->toBe('bytes 262144-524287/'.YT_SIZE); // continua, no re-sube desde 0
});

test('YouTubePublish: si la sesion cacheada caduco, el reintento abre una nueva (y esta si cuesta cuota)', function () {
    Cache::put('youtube:upload:77', YT_SESSION, now()->addDay());
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=NEW']])
        ->on('PUT', '#upload_id=SESS1$#', ['status' => 404])
        ->on('PUT', '#upload_id=NEW$#', FakeHttp::seq(
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]],
            ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],
            ['status' => 200, 'json' => ['id' => 'VIDN']],
        ));

    expect($this->yt->publish($this->account, ytRequest())->reference)->toBe('VIDN')->and((new YouTubeQuota)->used())->toBe(1600);
});

test('YouTubePublish: el timeout de cada llamada se acota a lo que queda de la pasada, nunca supera la marca dura (revision F4-5 #7)', function () {
    config(['social.youtube.pass_seconds' => 100, 'social.youtube.upload_timeout' => 60]);
    $timeout = fn (float $elapsed) => (function () use ($elapsed) {
        $this->passStartedAt = microtime(true) - $elapsed;

        return $this->callTimeout(60.0);
    })->call($this->yt);

    expect($timeout(0))->toBe(60.0)                       // pasada recien empezada: el tope propio
        ->and($timeout(70))->toBeGreaterThan(29.0)->toBeLessThanOrEqual(30.0) // quedan ~30 s: el PUT no puede pasarse
        ->and($timeout(99.5))->toBeLessThanOrEqual(1.0)  // al borde de la marca: 1 s
        ->and($timeout(120))->toBe(1.0);
});

test('YouTubePublish: el presupuesto se comprueba antes de cada PUT: sin margen para otro trozo la pasada devuelve «subiendo»', function () {
    config(['social.youtube.upload_budget_seconds' => 5, 'social.youtube.upload_min_slice_seconds' => 10]); // < trozo minimo
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YT_SESSION]])
        ->on('PUT', '#upload_id=SESS1$#', ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(YT_CHUNK - 1)]]);

    $r = $this->yt->publish($this->account, ytRequest());

    expect($r->reference)->toBe(YouTubePublisher::UPLOADING)->and($this->http->callsTo('PUT', '#upload_id=SESS1$#'))->toHaveCount(1);
});

test('YouTubePublish: chunk_bytes se redondea a multiplo de 256 KiB (revision F4-5 #17)', function () {
    config(['social.youtube.chunk_bytes' => 300000]); // no multiplo
    ytHappyUpload($this->http);

    $this->yt->publish($this->account, ytRequest());

    expect($this->http->callsTo('PUT', '#upload_id=SESS1$#')[0]['headers']['content-range'])->toBe('bytes 0-262143/'.YT_SIZE);
});

test('YouTubePublish: rateLimitExceeded es un limite de ritmo transitorio (backoff), no agota el cupo del dia (revision F4-5 #12)', function () {
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 403, 'json' => ['error' => ['code' => 403, 'message' => 'x', 'errors' => [['reason' => 'rateLimitExceeded']]]]]);

    expect(fn () => $this->yt->publish($this->account, ytRequest()))->toThrow(TransientPublishException::class);
    expect((new YouTubeQuota)->used())->toBe(0); // el dia NO queda marcado como agotado
});

test('YouTubePublish: la descripcion se recorta a 5.000 BYTES de UTF-8 sin partir caracteres (revision F4-5 #14)', function (string $char) {
    ytHappyUpload($this->http);

    $this->yt->publish($this->account, ytRequest(ContentType::YouTubeShort, "Titulo\n".str_repeat($char, 4000)));

    $desc = $this->http->callsTo('POST', '#uploadType=resumable#')[0]['json']['snippet']['description'];
    expect(strlen($desc))->toBeLessThanOrEqual(5000)->and(mb_check_encoding($desc, 'UTF-8'))->toBeTrue()->and($desc)->toEndWith('#Shorts');
})->with(['ascii' => ['a'], 'dos bytes' => ['é'], 'tres bytes' => ['€'], 'emoji' => ['😀']]);

test('YouTubePublish: la fase de subida tiene plazo propio y poll_max_minutes cuenta desde que termina (revision F4-5 #10)', function () {
    config(['publishing.poll_max_minutes' => 30, 'publishing.upload_max_minutes' => 240]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::YouTube, $this->yt));
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi titulo', 'scheduled_at' => now()->subHours(2), 'status' => PostStatus::Publishing]);
    $post->media()->attach($this->asset->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'network' => Network::YouTube,
        'content_type' => ContentType::YouTubeVideo, 'status' => PostStatus::Processing, 'external_reference' => 'subiendo',
        'started_at' => now()->subMinutes(100), 'next_attempt_at' => now()->subSecond(),
    ]);
    Cache::put('youtube:upload:'.$target->id, YT_SESSION, now()->addDay());
    // Tras 100 min SUBIENDO (> poll_max_minutes) sigue vivo; esta pasada termina la subida y devuelve el ID.
    $this->http->on('PUT', '#upload_id=SESS1$#', FakeHttp::seq(
        ['status' => 308, 'headers' => ['Range' => 'bytes=0-'.(2 * YT_CHUNK - 1)]],
        ['status' => 200, 'json' => ['id' => 'VIDL']],
    ))->on('GET', '#youtube/v3/videos\?#', ['json' => ['items' => [['status' => ['uploadStatus' => 'uploaded']]]]]);

    dispatch_sync(new PollPublishStatus($target->id));

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(PostStatus::Processing)->and($fresh->external_reference)->toBe('VIDL');
    expect($fresh->started_at->diffInMinutes(now()))->toBeLessThan(2); // el reloj del procesado arranca ahora

    // Y el procesado SI vence a los 30 min (no a los 240).
    $fresh->forceFill(['started_at' => now()->subMinutes(31)])->save();
    dispatch_sync(new PollPublishStatus($target->id));
    expect($target->fresh()->status)->toBe(PostStatus::Failed)->and($target->fresh()->error_code)->toBe('timeout_procesado');
});

test('YouTubePublish N1: editar la privacidad de un destino programado con sesion cacheada abre una sesion NUEVA con private; solo la fecha la conserva', function () {
    $service = app(PostService::class);
    $post = $service->create($this->user, $this->brand, [
        'text' => 'Mi titulo', 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [$this->asset->id],
        'targets' => [['account_id' => $this->account->id, 'content_type' => 'youtube_video', 'options' => ['privacy' => 'public']]],
    ]);
    $target = $post->targets->first();
    $edit = fn (array $over) => $service->update($this->user, $post->fresh(), array_merge([
        'text' => 'Mi titulo', 'scheduled_at' => $post->fresh()->scheduled_at, 'schedule' => true, 'media' => [$this->asset->id],
        'targets' => [['account_id' => $this->account->id, 'content_type' => 'youtube_video', 'options' => ['privacy' => 'public']]],
    ], $over));
    $target->forceFill(['external_reference' => 'subiendo', 'progress' => ['phase' => 'uploading'], 'attempts' => 1])->save();
    Cache::put('youtube:upload:'.$target->id, YT_SESSION, now()->addDay());

    $edit(['scheduled_at' => now()->addHours(5)]); // solo la fecha
    expect(Cache::has('youtube:upload:'.$target->id))->toBeTrue()->and($target->fresh()->external_reference)->toBe('subiendo');

    $edit(['targets' => [['account_id' => $this->account->id, 'content_type' => 'youtube_video', 'options' => ['privacy' => 'private']]]]);
    $t = $target->fresh();
    expect(Cache::has('youtube:upload:'.$target->id))->toBeFalse()->and($t->external_reference)->toBeNull()->and($t->progress['phase'] ?? null)->toBeNull()->and($t->progress['upload_url'] ?? null)->toBeNull()->and($t->attempts)->toBe(0);

    ytHappyUpload($this->http);
    $this->yt->publish($this->account, new PublishRequest($target->id, ContentType::YouTubeVideo, 'Mi titulo', [ytMedia()], ['privacy' => 'private'], null, now()->subMinutes(5)));

    $sessions = $this->http->callsTo('POST', '#uploadType=resumable#');
    expect($sessions)->toHaveCount(1)->and($sessions[0]['json']['status']['privacyStatus'])->toBe('private');
});

test('TikTok N1: editar el contenido de un destino programado descarta publish_id, upload_url y fase (sesion nueva)', function () {
    $service = app(PostService::class);
    $tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    $post = $service->create($this->user, $this->brand, [
        'text' => 'Titulo', 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [$this->asset->id],
        'targets' => [['account_id' => $tt->id, 'content_type' => 'tiktok_video', 'options' => []]],
    ]);
    $target = $post->targets->first();
    $target->forceFill(['external_reference' => 'PUB1', 'progress' => ['phase' => 'uploading', 'upload_url' => 'https://upload.tiktokapis.com/x', 'next_chunk' => 2], 'attempts' => 1])->save();

    $service->update($this->user, $post->fresh(), [
        'text' => 'Titulo NUEVO', 'scheduled_at' => $post->fresh()->scheduled_at, 'schedule' => true, 'media' => [$this->asset->id],
        'targets' => [['account_id' => $tt->id, 'content_type' => 'tiktok_video', 'options' => []]],
    ]);

    $t = $target->fresh();
    expect($t->external_reference)->toBeNull()->and($t->progress['phase'] ?? null)->toBeNull()->and($t->progress['upload_url'] ?? null)->toBeNull()->and($t->attempts)->toBe(0);
});
