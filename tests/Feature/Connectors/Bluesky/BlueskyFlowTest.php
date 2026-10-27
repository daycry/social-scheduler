<?php

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\ConnectorRegistry;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PollPublishStatus;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueskyFake;

// C-01, CA-01..CA-06 de extremo a extremo con `Http::fake`: conectar -> programar -> publicar (texto, imagenes, video) -> metricas.

const FLOW_URI = 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.bluesky.default_langs' => ['es'], 'social.bluesky.image_max_bytes' => 2_000_000]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->service = app(PostService::class);
});

/** Conecta la cuenta por la ruta real (CA-01) y devuelve el modelo. */
function flowConnect(): SocialAccount
{
    BlueskyFake::connect();
    test()->actingAs(test()->user)->post(route('brands.connections.credentials', [test()->brand, 'bluesky']), ['handle' => 'ana.example.com', 'app_password' => 'abcd-efgh-ijkl-mnop'])
        ->assertSessionHas('success');

    return SocialAccount::withoutUserScope()->where('network', 'bluesky')->firstOrFail();
}

function flowImage(): MediaAsset
{
    $im = imagecreatetruecolor(120, 80);
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    $asset = MediaAsset::factory()->for(test()->user)->create(['size' => strlen($bytes), 'declared_size' => strlen($bytes), 'width' => 120, 'height' => 80]);
    Storage::disk('local')->put($asset->path, $bytes);

    return $asset;
}

function flowSchedule(SocialAccount $account, string $type, string $text, array $media = []): int
{
    $post = test()->service->create(test()->user, test()->brand, [
        'text' => $text, 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => $media,
        'targets' => [['account_id' => $account->id, 'content_type' => $type, 'text' => null]],
    ]);

    return $post->targets->first()->id;
}

function flowTarget(int $id): PostTarget
{
    return PostTarget::withoutUserScope()->find($id);
}

test('CA-01: conectar guarda la cuenta, tokens y app password cifrados, y aparece conectada', function () {
    $account = flowConnect();

    expect($account->status)->toBe('activa')->and($account->external_id)->toBe(BlueskyFake::DID)->and($account->credential_secret)->toBe('abcd-efgh-ijkl-mnop')
        ->and($account->meta['pds'])->toBe(BlueskyFake::PDS);
    expect(app(ConnectorRegistry::class)->forProvider('bluesky'))->toBeInstanceOf(BlueskyConnector::class);
});

test('CA-02/CA-03: texto con facets e imagenes, programado y publicado por el job', function () {
    $account = flowConnect();
    $images = [flowImage(), flowImage()];
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.uploadBlob' => Http::sequence()
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'b1'], 'mimeType' => 'image/jpeg', 'size' => 10]])
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'b2'], 'mimeType' => 'image/jpeg', 'size' => 10]]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => fn ($request) => Http::response(['uri' => FLOW_URI.$request['rkey'], 'cid' => 'c']),
    ]);

    $targetId = flowSchedule($account, 'bluesky_post', 'Hola mundo https://example.com #flow', array_map(fn ($a) => $a->id, $images));
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    $target = flowTarget($targetId);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_id)->toStartWith(FLOW_URI)
        ->and($target->external_url)->toStartWith('https://bsky.app/profile/ana.example.com/post/');

    $record = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), 'createRecord'))[0];
    expect($record['record']['embed']['images'])->toHaveCount(2)->and($record['record']['facets'])->toHaveCount(2)
        ->and($record['record']['langs'])->toBe(['es'])->and($record['rkey'])->toBe(substr((string) $target->external_id, strlen(FLOW_URI)));
});

test('CA-02: 301 grafemas se rechaza al validar y al programar, con el mismo mensaje', function () {
    $account = flowConnect();
    $body = ['text' => str_repeat('a', 301), 'scheduled_at' => now()->addHour()->toIso8601String(), 'schedule' => true, 'media' => [], 'targets' => [['account_id' => $account->id, 'content_type' => 'bluesky_post']]];
    $message = 'El texto tiene 301 caracteres y una publicacion de Bluesky admite como maximo 300.';

    $this->postJson(route('brands.posts.validate', $this->brand), $body)->assertOk()->assertJsonPath('errors', ['targets.0' => [$message]]);
    $this->post(route('brands.posts.store', $this->brand), $body)->assertSessionHasErrors(['targets.0' => $message]);
});

test('CA-04: timeout en createRecord y reintento sin duplicar el post', function () {
    $account = flowConnect();
    $createCalls = 0;
    $created = null;
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => function ($request) use (&$createCalls, &$created) {
            $createCalls++;
            $created = $request['rkey'];
            throw new ConnectionException('cURL error 28: timeout');
        },
        // El PDS SI lo habia creado: el getRecord de la comprobacion lo encuentra.
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => function () use (&$created) {
            return Http::response(['uri' => FLOW_URI.$created, 'cid' => 'c', 'value' => []]);
        },
    ]);

    $targetId = flowSchedule($account, 'bluesky_post', 'Solo texto');
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    $target = flowTarget($targetId);
    expect($target->status)->toBe(PostStatus::Published)->and($createCalls)->toBe(1)->and($target->external_id)->toBe(FLOW_URI.$created);
});

test('CA-05: el video queda procesando y se publica cuando el trabajo termina', function () {
    $account = flowConnect();
    $video = MediaAsset::factory()->for($this->user)->video()->create();
    Storage::disk('local')->put($video->path, str_repeat('V', 100_000));
    $blob = ['$type' => 'blob', 'ref' => ['$link' => 'bv'], 'mimeType' => 'video/mp4', 'size' => 100_000];
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'SERVICE']),
        'video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 10, 'remainingDailyBytes' => 9_000_000_000]),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job-1', 'state' => 'JOB_STATE_CREATED']),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::sequence()
            ->push(['jobStatus' => ['jobId' => 'job-1', 'state' => 'JOB_STATE_ENCODING']])
            ->push(['jobStatus' => ['jobId' => 'job-1', 'state' => 'JOB_STATE_COMPLETED', 'blob' => $blob]]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => fn ($request) => Http::response(['uri' => FLOW_URI.$request['rkey'], 'cid' => 'c']),
    ]);

    $targetId = flowSchedule($account, 'bluesky_video', 'Mi video', [$video->id]);
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    // La cola es `sync`: el primer sondeo (ENCODING) deja el destino procesando; el siguiente publica.
    $target = flowTarget($targetId);
    expect($target->status)->toBe(PostStatus::Processing)->and($target->external_reference)->toBe('job-1');

    dispatch_sync(new PollPublishStatus($targetId));

    $target = flowTarget($targetId);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_url)->toContain('https://bsky.app/profile/ana.example.com/post/');
    $record = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), 'createRecord'))[0];
    expect($record['record']['embed']['$type'])->toBe('app.bsky.embed.video');
});

test('CA-05: video con el email sin verificar falla con un mensaje claro', function () {
    $account = flowConnect();
    $video = MediaAsset::factory()->for($this->user)->video()->create();
    Storage::disk('local')->put($video->path, 'V');
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'SERVICE']),
        'video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => false, 'error' => 'unconfirmed_email', 'message' => 'verify']),
    ]);

    $targetId = flowSchedule($account, 'bluesky_video', 'Mi video', [$video->id]);
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    $target = flowTarget($targetId);
    expect($target->status)->toBe(PostStatus::Failed)->and($target->error_message)->toContain('email')->and($target->error_code)->toBe('video_no_permitido');
});

test('CA-06: metricas de cuenta y de post, con views nulo', function () {
    $account = flowConnect();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => fn ($request) => Http::response(['uri' => FLOW_URI.$request['rkey'], 'cid' => 'c']),
    ]);
    $targetId = flowSchedule($account, 'bluesky_post', 'Hola');
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();
    $uri = flowTarget($targetId)->external_id;

    Http::fake([
        'public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['followersCount' => 321, 'postsCount' => 45]),
        'public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => [['uri' => $uri, 'likeCount' => 7, 'replyCount' => 1, 'repostCount' => 2, 'quoteCount' => 0]]]),
    ]);
    $collector = app(SnapshotCollector::class);
    $collector->collectAccount($account->fresh(), Days::closed($account->network));
    $collector->collectPosts($account->fresh(), 48);

    $snapshot = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->first();
    expect($snapshot->metrics[Metric::FOLLOWERS])->toBe(321)->and($snapshot->metrics[Metric::POST_COUNT])->toBe(45)->and($snapshot->metrics[Metric::VIEWS])->toBeNull();
    $post = PostMetricSnapshot::withoutUserScope()->where('post_target_id', $targetId)->first();
    expect($post->metrics[Metric::LIKES])->toBe(7)->and($post->metrics[Metric::SHARES])->toBe(2)->and($post->metrics[Metric::INTERACTIONS])->toBe(10)->and($post->metrics[Metric::VIEWS])->toBeNull();
});
