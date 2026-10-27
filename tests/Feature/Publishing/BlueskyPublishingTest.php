<?php

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueskyFake;

// Bluesky sobre el nucleo de publicacion (ADR-005): reintentos con la misma rkey, reconexion, cuota diaria de video y regresion del
// registro de las otras redes. `PublishPostTarget` y `PollPublishStatus` no se tocan.

const PUB_URI = 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->account = BlueskyFake::account([], $this->user);
    $this->brand = Brand::withoutGlobalScopes()->find($this->account->brand_id);
});

function pubSchedule(string $type = 'bluesky_post', string $text = 'Hola', array $media = []): PostTarget
{
    $post = app(PostService::class)->create(test()->user, test()->brand, [
        'text' => $text, 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => $media,
        'targets' => [['account_id' => test()->account->id, 'content_type' => $type, 'text' => null]],
    ]);

    return $post->targets->first();
}

function pubRun(): void
{
    Carbon::setTestNow(now()->addHour()->addMinute());
    app(DispatchDuePosts::class)();
}

test('un fallo transitorio se reintenta con la misma rkey y publica una sola vez', function () {
    $rkeys = [];
    $creates = 0;
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => function ($request) use (&$rkeys, &$creates) {
            $rkeys[] = $request['rkey'];
            $creates++;

            return $creates === 1 ? Http::response('Bad gateway', 502) : Http::response(['uri' => PUB_URI.$request['rkey'], 'cid' => 'c']);
        },
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'x'], 400),
    ]);

    $target = pubSchedule();
    pubRun();
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->attempts)->toBe(1);

    Carbon::setTestNow(now()->addMinutes(10));
    app(DispatchDuePosts::class)();

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(PostStatus::Published)->and($creates)->toBe(2)->and($rkeys[0])->toBe($rkeys[1])
        ->and($fresh->external_id)->toBe(PUB_URI.$rkeys[0]);
});

test('si la sesion y la app password fallan, la cuenta pasa a requiere_reconexion y el post se conserva', function () {
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'ExpiredToken', 'message' => 'x'], 400),
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'InvalidToken', 'message' => 'x'], 400),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'x'], 401),
    ]);

    $target = pubSchedule();
    pubRun();

    expect($this->account->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT)
        ->and($target->fresh()->status)->not->toBe(PostStatus::Failed)
        ->and($target->fresh()->status)->not->toBe(PostStatus::Published);
});

test('cupo diario de video agotado: se aplaza segun la politica de cuota y no se sube nada', function () {
    $video = MediaAsset::factory()->for($this->user)->video()->create();
    Storage::disk('local')->put($video->path, 'V');
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'SERVICE']),
        'video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 0, 'remainingDailyBytes' => 0]),
    ]);

    $target = pubSchedule('bluesky_video', 'Video', [$video->id]);
    pubRun();

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(PostStatus::Scheduled)->and($fresh->error_code)->toBe('cuota');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'uploadVideo'));
});

test('regresion: el registro mantiene los publicadores y lectores de las otras redes junto a Bluesky', function () {
    $registry = app(ConnectorRegistry::class);

    foreach (Network::cases() as $network) {
        expect($registry->publisherFor($network))->not->toBeNull($network->value)
            ->and($registry->insightsFor($network))->not->toBeNull($network->value)
            ->and($registry->has($network->provider()))->toBeTrue($network->value);
    }
    expect($registry->providers())->toBe(['meta', 'google', 'tiktok', 'bluesky'])
        ->and($registry->forNetwork(Network::Bluesky))->toBeInstanceOf(BlueskyConnector::class);
});

test('BLUESKY_ENABLED=false retira Bluesky del registro y del catalogo ofrecido', function () {
    config(['social.bluesky.enabled' => false]);
    $this->app->forgetInstance(ConnectorRegistry::class); // el singleton del proveedor se reconstruye con la configuracion apagada

    $registry = $this->app->make(ConnectorRegistry::class);

    expect($registry->has('bluesky'))->toBeFalse()->and($registry->publisherFor(Network::Bluesky))->toBeNull()
        ->and(Network::offered())->not->toContain(Network::Bluesky)->and(Network::offered())->toContain(Network::Instagram);
});
