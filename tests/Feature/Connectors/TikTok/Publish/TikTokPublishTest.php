<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
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

function ttRequest(PublishMedia|array $media, ContentType $type = ContentType::TikTokVideo, ?string $text = 'Mi video', array $options = [], ?string $reference = null): PublishRequest
{
    return new PublishRequest(55, $type, $text, is_array($media) ? $media : [$media], $options, $reference, now()->subMinutes(5));
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

test('TikTokPublish: foto/carrusel por PULL_FROM_URL solo con dominio verificado y con las URLs bajo ese dominio', function () {
    $img = fn ($n) => new PublishMedia($n, 'image', 'image/jpeg', "https://r2.test/media/$n.jpg?X-Sig=abc", 1080, 1080);

    expect(fn () => $this->tt->publish($this->account, ttRequest([$img(1)], ContentType::TikTokPhoto)))->toThrow(PermanentPublishException::class, 'dominio verificado');
    expect($this->http->calls)->toBe([]);

    config(['social.tiktok.verified_domain' => 'https://media.midominio.test']);
    $this->http->on('POST', '#/content/init/$#', ['json' => ['data' => ['publish_id' => 'ph1'], 'error' => ['code' => 'ok']]]);

    $r = $this->tt->publish($this->account, ttRequest([$img(1), $img(2)], ContentType::TikTokPhoto, "Titulo\nResto"));

    expect($r->reference)->toBe('ph1');
    $body = $this->http->callsTo('POST', '#/content/init/#')[0]['json'];
    expect($body['media_type'])->toBe('PHOTO')->and($body['post_mode'])->toBe('MEDIA_UPLOAD')
        ->and($body['source_info']['source'])->toBe('PULL_FROM_URL')
        ->and($body['source_info']['photo_images'])->toBe(['https://media.midominio.test/media/1.jpg?X-Sig=abc', 'https://media.midominio.test/media/2.jpg?X-Sig=abc'])
        ->and($body['post_info']['title'])->toBe('Titulo');
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
