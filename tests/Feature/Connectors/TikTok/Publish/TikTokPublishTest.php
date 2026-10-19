<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHttp;

const TT_UPLOAD = 'https://open-upload.tiktokapis.com/video/?upload_id=U1&upload_token=T';
const TT_MB = 1024 * 1024;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.tiktok.verified_domain' => null, 'social.tiktok.single_chunk_max_bytes' => 64 * TT_MB, 'social.tiktok.chunk_bytes' => 10 * TT_MB]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::TikTok, 'external_id' => 'OPEN1', 'username' => 'marble', 'access_token' => 'tt-access',
    ]);
    $this->http = new FakeHttp;
    $this->tt = new TikTokPublisher;
});

function ttAsset(int $size, float $fps = 30.0): PublishMedia
{
    $asset = MediaAsset::factory()->video(duration: 20, fps: $fps)->for(test()->user)->create(['size' => $size]);
    Storage::disk('local')->put($asset->path, str_repeat('t', $size));

    return new PublishMedia($asset->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4?sig=1', 1080, 1920, 20.0, $fps, $size, $asset->disk, $asset->path);
}

function ttRequest(PublishMedia|array $media, ContentType $type = ContentType::TikTokVideo, ?string $text = 'Mi video', array $options = [], ?string $reference = null, array $progress = []): PublishRequest
{
    return new PublishRequest(55, $type, $text, is_array($media) ? $media : [$media], $options, $reference, now()->subMinutes(5), $progress);
}

function ttInit(FakeHttp $http, string $path = 'inbox/video'): void
{
    $http->on('POST', '#/v2/post/publish/'.$path.'/init/$#', ['json' => ['data' => ['publish_id' => 'v_pub_1', 'upload_url' => TT_UPLOAD], 'error' => ['code' => 'ok', 'message' => '']]]);
}

test('TikTokPublish: por defecto sube como borrador a la bandeja con un unico trozo si <= 64 MB (G1)', function () {
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 201]);
    $size = 3 * TT_MB;

    $r = $this->tt->publish($this->account, ttRequest(ttAsset($size)));

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('v_pub_1');
    $init = $this->http->callsTo('POST', '#/inbox/video/init/#')[0];
    expect($init['json'])->toBe(['source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $size, 'total_chunk_count' => 1]]);
    expect($init['headers']['authorization'])->toBe('Bearer tt-access');
    expect($this->http->callsTo('POST', '#/publish/video/init/#'))->toBe([]); // nunca Direct Post por defecto

    $puts = $this->http->callsTo('PUT', '#upload_id=U1#');
    expect($puts)->toHaveCount(1)->and($puts[0]['headers']['content-range'])->toBe('bytes 0-'.($size - 1).'/'.$size)->and($puts[0]['body_length'])->toBe($size);
});

test('TikTokPublish: un video grande va en trozos de 10 MB y el ultimo absorbe el resto, siempre en streaming', function () {
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', FakeHttp::seq(['status' => 206], ['status' => 206], ['status' => 201]));
    config(['social.tiktok.single_chunk_max_bytes' => 5 * TT_MB]);
    $size = 25 * TT_MB + 512 * 1024; // 2 trozos de 10 MB + ultimo de 15,5 MB -> total_chunk_count 2? floor(25.5/10)=2

    $this->tt->publish($this->account, ttRequest(ttAsset($size)));

    $init = $this->http->callsTo('POST', '#/inbox/video/init/#')[0]['json']['source_info'];
    expect($init['chunk_size'])->toBe(10 * TT_MB)->and($init['total_chunk_count'])->toBe(2);

    $puts = $this->http->callsTo('PUT', '#upload_id=U1#');
    expect($puts)->toHaveCount(2);
    expect(array_map(fn ($c) => $c['headers']['content-range'], $puts))->toBe([
        'bytes 0-'.(10 * TT_MB - 1).'/'.$size, 'bytes '.(10 * TT_MB).'-'.($size - 1).'/'.$size,
    ]);
    expect(array_sum(array_column($puts, 'body_length')))->toBe($size);
});

test('TikTokPublish: Direct Post solo con meta.direct_post y usa post_info con la visibilidad que permite creator_info', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    $this->http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE']], 'error' => ['code' => 'ok']]]);
    ttInit($this->http, 'video');
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 201]);

    $this->tt->publish($this->account->fresh(), ttRequest(ttAsset(TT_MB), options: ['privacy_level' => 'PUBLIC_TO_EVERYONE']));

    expect($this->http->callsTo('POST', '#/inbox/#'))->toBe([]);
    $info = $this->http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info'];
    expect($info['privacy_level'])->toBe('PUBLIC_TO_EVERYONE')->and($info['title'])->toBe('Mi video');
    // creator_info/query se llama con el objeto vacio {} y no con [].
    expect($this->http->callsTo('POST', '#creator_info/query#')[0]['body'])->toBe('{}');
});

test('TikTokPublish: una visibilidad que la cuenta no permite baja a la mas restrictiva, nunca se abre mas', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    $this->http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]]);
    ttInit($this->http, 'video');
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 201]);

    $this->tt->publish($this->account->fresh(), ttRequest(ttAsset(TT_MB), options: ['privacy_level' => 'PUBLIC_TO_EVERYONE']));

    expect($this->http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info']['privacy_level'])->toBe('SELF_ONLY');
});

test('TikTokPublish: unaudited_client... es un fallo permanente que sugiere desactivar Direct Post', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    $this->http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]]);
    $this->http->on('POST', '#/publish/video/init/$#', ['status' => 403, 'json' => ['error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts', 'message' => 'x']]]);

    try {
        $this->tt->publish($this->account->fresh(), ttRequest(ttAsset(TT_MB)));
        $this->fail('Debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->errorCode)->toBe('unaudited_client_can_only_post_to_private_accounts')->and($e->getMessage())->toContain('Desactiva Direct Post')->toContain('bandeja');
    }
});

test('TikTokPublish: un video por debajo de 23 fps se rechaza antes de subir nada', function () {
    expect(fn () => $this->tt->publish($this->account, ttRequest(ttAsset(TT_MB, fps: 15.0))))
        ->toThrow(PermanentPublishException::class, '23 fps');
    expect($this->http->calls)->toBe([]);
});

test('TikTokPublish: el estado de la bandeja se publica con nota, el directo con enlace y los fallos con motivo', function () {
    $this->http->on('POST', '#status/fetch/$#', FakeHttp::seq(
        ['json' => ['data' => ['status' => 'PROCESSING_UPLOAD'], 'error' => ['code' => 'ok']]],
        ['json' => ['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']]],
        ['json' => ['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7001']], 'error' => ['code' => 'ok']]],
        ['json' => ['data' => ['status' => 'FAILED', 'fail_reason' => 'frame_rate_check_failed'], 'error' => ['code' => 'ok']]],
    ));
    $req = ttRequest([], reference: 'v_pub_1');

    expect($this->tt->poll($this->account, $req)->isProcessing())->toBeTrue();

    $inbox = $this->tt->poll($this->account, $req);
    expect($inbox->isPublished())->toBeTrue()->and($inbox->note)->toContain('bandeja de TikTok')->and($inbox->url)->toBeNull();

    $direct = $this->tt->poll($this->account, $req);
    expect($direct->externalId)->toBe('7001')->and($direct->url)->toBe('https://www.tiktok.com/@marble/video/7001')->and($direct->note)->toBeNull();

    $failed = $this->tt->poll($this->account, $req);
    expect($failed->isFailed())->toBeTrue()->and($failed->message)->toContain('23 fps');
    expect($this->http->callsTo('POST', '#status/fetch#')[0]['json'])->toBe(['publish_id' => 'v_pub_1']);
});

test('TikTokPublish: errores de la API se clasifican (cuota, credenciales, transitorio, desconocido)', function (array|Throwable $response, string $class) {
    $this->http->on('POST', '#inbox/video/init/$#', $response);

    expect(fn () => $this->tt->publish($this->account, ttRequest(ttAsset(TT_MB))))->toThrow($class);
})->with([
    'cuota de spam' => [['status' => 429, 'json' => ['error' => ['code' => 'spam_risk_too_many_posts']]], QuotaExceededException::class],
    'token invalido' => [['status' => 401, 'json' => ['error' => ['code' => 'access_token_invalid']]], ReconnectRequiredException::class],
    'scope' => [['status' => 403, 'json' => ['error' => ['code' => 'scope_not_authorized']]], ReconnectRequiredException::class],
    '5xx' => [['status' => 503, 'json' => ['error' => ['code' => 'internal_error']]], TransientPublishException::class],
    'sin red' => [new ConnectionException('t'), TransientPublishException::class],
    'desconocido' => [['status' => 400, 'json' => ['error' => ['code' => 'invalid_params', 'message' => 'bad']]], PermanentPublishException::class],
]);

test('TikTokPublish: la upload_url debe ser de TikTok y un trozo rechazado falla', function () {
    $this->http->on('POST', '#inbox/video/init/$#', ['json' => ['data' => ['publish_id' => 'p', 'upload_url' => 'https://evil.test/up'], 'error' => ['code' => 'ok']]]);
    expect(fn () => $this->tt->publish($this->account, ttRequest(ttAsset(TT_MB))))->toThrow(PermanentPublishException::class, 'URL de subida');

});

test('TikTokPublish: un trozo que TikTok rechaza con 5xx se reintenta como transitorio', function () {
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 500]);
    expect(fn () => $this->tt->publish($this->account, ttRequest(ttAsset(TT_MB))))->toThrow(TransientPublishException::class);
});

test('TikTokPublish: foto/carrusel por PULL_FROM_URL con la clave del objeto bajo el dominio verificado, sin firma ni /bucket (revision F4-5 #15)', function () {
    $img = fn ($n) => new PublishMedia($n, 'image', 'image/jpeg', "https://acct.r2.cloudflarestorage.com/bucket/media/$n.jpg?X-Amz-Signature=abc", 1080, 1080, disk: 'local', path: "media/mi foto $n.jpg");

    expect(fn () => $this->tt->publish($this->account, ttRequest([$img(1)], ContentType::TikTokPhoto)))->toThrow(PermanentPublishException::class, 'dominio verificado');
    expect($this->http->calls)->toBe([]);

    config(['social.tiktok.verified_domain' => 'http://media.midominio.test']); // http: no vale
    expect(fn () => $this->tt->publish($this->account, ttRequest([$img(1)], ContentType::TikTokPhoto)))->toThrow(PermanentPublishException::class, 'https');
    expect($this->http->calls)->toBe([]);

    config(['social.tiktok.verified_domain' => 'https://media.midominio.test/']);
    $this->http->on('POST', '#/content/init/$#', ['json' => ['data' => ['publish_id' => 'ph1'], 'error' => ['code' => 'ok']]]);

    $r = $this->tt->publish($this->account, ttRequest([$img(1), $img(2)], ContentType::TikTokPhoto, "Titulo\nResto"));

    expect($r->reference)->toBe('ph1');
    $body = $this->http->callsTo('POST', '#/content/init/#')[0]['json'];
    expect($body['media_type'])->toBe('PHOTO')->and($body['post_mode'])->toBe('MEDIA_UPLOAD')
        ->and($body['source_info']['source'])->toBe('PULL_FROM_URL')
        ->and($body['source_info']['photo_images'])->toBe(['https://media.midominio.test/media/mi%20foto%201.jpg', 'https://media.midominio.test/media/mi%20foto%202.jpg'])
        ->and($body['post_info']['title'])->toBe('Titulo');
});

test('TikTokPublish: el titulo de una foto en Direct Post se recorta a 90 caracteres (revision F4-5 #16)', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    config(['social.tiktok.verified_domain' => 'https://media.midominio.test']);
    $this->http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]])
        ->on('POST', '#/content/init/$#', ['json' => ['data' => ['publish_id' => 'ph1'], 'error' => ['code' => 'ok']]]);
    $img = new PublishMedia(1, 'image', 'image/jpeg', 'https://x.test/1.jpg', 1080, 1080, disk: 'local', path: 'media/1.jpg');

    $this->tt->publish($this->account, ttRequest([$img], ContentType::TikTokPhoto, str_repeat('t', 300)));

    $info = $this->http->callsTo('POST', '#/content/init/#')[0]['json']['post_info'];
    expect(mb_strlen($info['title']))->toBe(90)->and(mb_strlen($info['description']))->toBe(300);
});

test('TikTokPublish: findPublished sin publish_id no inventa nada y con el consulta su estado', function () {
    expect($this->tt->findPublished($this->account, ttRequest([])))->toBeNull();

    $this->http->on('POST', '#status/fetch/$#', ['json' => ['data' => ['status' => 'PROCESSING_DOWNLOAD'], 'error' => ['code' => 'ok']]]);
    expect($this->tt->findPublished($this->account, ttRequest([], reference: 'p1'))->isProcessing())->toBeTrue();
});

test('TikTokPublish: el tipo foto solo se ofrece (y acepta) con dominio verificado', function () {
    expect(ContentType::TikTokPhoto->isOffered())->toBeFalse()->and(ContentType::TikTokVideo->isOffered())->toBeTrue();
    config(['social.tiktok.verified_domain' => 'https://media.midominio.test']);
    expect(ContentType::TikTokPhoto->isOffered())->toBeTrue();
});

test('TikTokPublish: de extremo a extremo por la cola el borrador queda publicado con la nota de bandeja (E2E-06)', function () {
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::TikTok, $this->tt));
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 201])
        ->on('POST', '#status/fetch/$#', ['json' => ['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']]]);

    $media = ttAsset(2 * TT_MB);
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi video', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $post->media()->attach($media->id, ['position' => 0]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id,
        'network' => Network::TikTok, 'content_type' => ContentType::TikTokVideo,
    ]);

    app(DispatchDuePosts::class)();
    Carbon::setTestNow(now()->addSeconds(31));
    app(DispatchDuePosts::class)();

    $done = $target->fresh();
    expect($done->status)->toBe(PostStatus::Published)->and($done->external_id)->toBe('v_pub_1')->and($done->external_url)->toBeNull()->and($done->status_note)->toContain('bandeja de TikTok');
});

test('TikTokPublish: el publish_id y el estado de la subida se guardan ANTES del primer PUT; un corte se reconcilia por el (revision F4-5 #11)', function () {
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', FakeHttp::seq(new ConnectionException('corte'), ['status' => 201]));
    $saved = [];
    $req = new PublishRequest(55, ContentType::TikTokVideo, 'Mi video', [ttAsset(3 * TT_MB)], [], null, now()->subMinutes(5), [], function ($ref, $progress) use (&$saved) {
        $saved[] = [$ref, $progress, count($this->http->callsTo('PUT', '#upload_id=U1#'))];
    });

    expect(fn () => $this->tt->publish($this->account, $req))->toThrow(TransientPublishException::class);

    expect($saved)->toHaveCount(1)->and($saved[0][0])->toBe('v_pub_1')->and($saved[0][2])->toBe(0) // guardado con 0 PUT hechos
        ->and($saved[0][1])->toMatchArray(['phase' => 'uploading', 'upload_url' => TT_UPLOAD, 'next' => 0, 'chunk_count' => 1]);

    // Reintento: con el publish_id y el progreso guardados NO se llama a init otra vez; se continua el trozo pendiente.
    $this->http->calls = [];
    $retry = new PublishRequest(55, ContentType::TikTokVideo, 'Mi video', [ttAsset(3 * TT_MB)], [], 'v_pub_1', now()->subMinutes(5), $saved[0][1]);

    $r = $this->tt->poll($this->account, $retry);

    expect($r->isProcessing())->toBeTrue()->and($r->progress['phase'])->toBe('processing')->and($r->progress['uploaded_at'])->toBeInt();
    expect($this->http->callsTo('POST', '#/init/#'))->toBe([]);
});

test('TikTokPublish: la subida se reparte en pasadas con presupuesto: guarda el trozo siguiente y el sondeo la continua (revision F4-5 #8)', function () {
    config(['social.tiktok.single_chunk_max_bytes' => 5 * TT_MB, 'social.tiktok.upload_budget_seconds' => 0]);
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', FakeHttp::seq(['status' => 206], ['status' => 206], ['status' => 201]));
    $size = 35 * TT_MB; // 3 trozos de 10 MB + el ultimo absorbe el resto -> total 3
    $media = ttAsset($size);

    $first = $this->tt->publish($this->account, ttRequest($media));
    expect($first->reference)->toBe('v_pub_1')->and($first->progress)->toMatchArray(['phase' => 'uploading', 'next' => 1, 'chunk_count' => 3]);
    expect(PublishResult::isUploadingState($first->reference, $first->progress))->toBeTrue();

    $second = $this->tt->poll($this->account, ttRequest($media, reference: 'v_pub_1', progress: $first->progress));
    expect($second->progress)->toMatchArray(['phase' => 'uploading', 'next' => 2]);

    $third = $this->tt->poll($this->account, ttRequest($media, reference: 'v_pub_1', progress: $second->progress));
    expect($third->progress['phase'])->toBe('processing')->and(PublishResult::isUploadingState($third->reference, $third->progress))->toBeFalse();

    $ranges = array_map(fn ($c) => $c['headers']['content-range'], $this->http->callsTo('PUT', '#upload_id=U1#'));
    expect($ranges)->toBe([
        'bytes 0-'.(10 * TT_MB - 1).'/'.$size, 'bytes '.(10 * TT_MB).'-'.(20 * TT_MB - 1).'/'.$size, 'bytes '.(20 * TT_MB).'-'.($size - 1).'/'.$size,
    ]);
    expect($this->http->callsTo('POST', '#/inbox/video/init/#'))->toHaveCount(1); // un solo init en toda la subida
});

test('TikTokPublish: un borrador atascado en PROCESSING_UPLOAD tras la subida completa se da por publicado con nota, no fallido (revision F4-5 #6)', function () {
    $this->http->on('POST', '#status/fetch/$#', ['json' => ['data' => ['status' => 'PROCESSING_UPLOAD'], 'error' => ['code' => 'ok']]]);
    $at = fn (int $minutes) => ['phase' => 'processing', 'uploaded_at' => now()->subMinutes($minutes)->timestamp];

    // Dentro del margen: sigue en proceso.
    expect($this->tt->poll($this->account, ttRequest([], reference: 'v_pub_1', progress: $at(3)))->isProcessing())->toBeTrue();

    // Pasado el margen: publicada con la nota y el publish_id marcado como tal.
    $done = $this->tt->poll($this->account, ttRequest([], reference: 'v_pub_1', progress: $at(11)));
    expect($done->isPublished())->toBeTrue()->and($done->note)->toBe(TikTokPublisher::INBOX_UNCONFIRMED_NOTE)->and($done->externalId)->toBe('v_pub_1')
        ->and($done->progress['external_id_kind'])->toBe('publish_id');

    // Direct Post NO se resuelve asi: ahi PROCESSING_UPLOAD sigue su plazo normal.
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    expect($this->tt->poll($this->account->fresh(), ttRequest([], reference: 'v_pub_1', progress: $at(11)))->isProcessing())->toBeTrue();
});

test('TikTokPublish: tras SEND_TO_USER_INBOX el external_id se marca como publish_id y no como ID de video, para T-34 (revision F4-5 #13)', function () {
    $this->http->on('POST', '#status/fetch/$#', FakeHttp::seq(
        ['json' => ['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']]],
        ['json' => ['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7001']], 'error' => ['code' => 'ok']]],
    ));

    expect($this->tt->poll($this->account, ttRequest([], reference: 'v_pub_1'))->progress['external_id_kind'])->toBe('publish_id');
    expect($this->tt->poll($this->account, ttRequest([], reference: 'v_pub_1'))->progress['external_id_kind'])->toBe('video_id');
});

test('TikTokPublish N2: la upload_url caducada (>= TTL) reinicia la subida con un init nuevo y progreso limpio (revision F4-5 i2 N2)', function () {
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', ['status' => 201]);
    $media = ttAsset(3 * TT_MB);
    $old = ['phase' => 'uploading', 'upload_url' => TT_UPLOAD, 'upload_url_at' => now()->subMinutes(56)->timestamp, 'restarts' => 0, 'chunk_size' => 3 * TT_MB, 'chunk_count' => 1, 'next' => 0, 'size' => 3 * TT_MB];
    $saved = [];
    $req = new PublishRequest(55, ContentType::TikTokVideo, 'Mi video', [$media], [], 'v_old', now()->subMinutes(70), $old, function ($ref, $progress) use (&$saved) {
        $saved[] = [$ref, $progress];
    });

    $r = $this->tt->poll($this->account, $req);

    expect($this->http->callsTo('POST', '#/inbox/video/init/#'))->toHaveCount(1); // init nuevo
    expect($saved[0][0])->toBe('v_pub_1')->and($saved[0][1])->toMatchArray(['restarts' => 1, 'next' => 0])->and($saved[0][1]['upload_url_at'])->toBe(now()->timestamp);
    expect($r->isProcessing())->toBeTrue()->and($r->progress['phase'])->toBe('processing');
});

test('TikTokPublish N2: tras agotar los reinicios falla con motivo claro; un PUT 403 de URL caducada tambien reinicia', function () {
    $media = ttAsset(3 * TT_MB);
    $progress = ['phase' => 'uploading', 'upload_url' => TT_UPLOAD, 'upload_url_at' => now()->subMinutes(56)->timestamp, 'restarts' => 1, 'chunk_size' => 3 * TT_MB, 'chunk_count' => 1, 'next' => 0, 'size' => 3 * TT_MB];

    try {
        $this->tt->poll($this->account, ttRequest($media, reference: 'v_old', progress: $progress));
        $this->fail('debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->errorCode)->toBe('subida_caducada')->and($e->getMessage())->toContain('caduco')->toContain('reprograma');
    }
    expect($this->http->callsTo('POST', '#/init/#'))->toBe([]);

    // PUT 403 (URL caducada/revocada) dentro de plazo: se reinicia una vez; si vuelve a pasar, falla claro.
    ttInit($this->http);
    $this->http->on('PUT', '#upload_id=U1#', FakeHttp::seq(['status' => 403], ['status' => 201]));
    $fresh = $progress;
    $fresh['upload_url_at'] = now()->timestamp;
    $fresh['restarts'] = 0;

    $r = $this->tt->poll($this->account, ttRequest($media, reference: 'v_old', progress: $fresh));

    expect($r->progress['phase'])->toBe('processing')->and($this->http->callsTo('POST', '#/inbox/video/init/#'))->toHaveCount(1);
});

test('TikTokPublish N2: la fase de subida de TikTok usa su propio plazo (social.tiktok.upload_max_minutes) en PollPublishStatus', function () {
    config(['social.tiktok.upload_max_minutes' => 120, 'publishing.upload_max_minutes' => 240]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::TikTok, $this->tt));
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Mi video', 'scheduled_at' => now()->subHours(4), 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'network' => Network::TikTok,
        'content_type' => ContentType::TikTokVideo, 'status' => PostStatus::Processing, 'external_reference' => 'v_old',
        'progress' => ['phase' => 'uploading', 'upload_url' => TT_UPLOAD, 'next' => 0],
        'started_at' => now()->subMinutes(130), 'next_attempt_at' => now()->subSecond(),
    ]);

    dispatch_sync(new PollPublishStatus($target->id));

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(PostStatus::Failed)->and($fresh->error_code)->toBe('timeout_procesado')->and($fresh->error_message)->toContain('reprograma');
});
