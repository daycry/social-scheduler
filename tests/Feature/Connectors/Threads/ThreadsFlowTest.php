<?php

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsConnector;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\RefreshSocialAccount;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SocialAccountService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ThreadsFake;

// C-02, CA-07..CA-09 de extremo a extremo con `Http::fake`: OAuth -> programar -> publicar (texto, imagen, video, carrusel) -> cuota -> metricas.

const THF_UID = ThreadsFake::USER_ID;
const THF_API = 'graph.threads.com/v1.0/'.THF_UID;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    ThreadsFake::configure();
    app()->forgetInstance(ConnectorRegistry::class);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->service = app(PostService::class);
});

/** Conecta la cuenta por la ruta OAuth real (CA-07) y devuelve el modelo. */
function thfConnect(): SocialAccount
{
    ThreadsFake::connect();
    test()->actingAs(test()->user)->post(route('brands.connections.connect', [test()->brand, 'threads']));
    $state = session('oauth.threads.state');
    test()->actingAs(test()->user)->get(route('connections.callback', ['provider' => 'threads', 'code' => 'CODE#_', 'state' => $state]))->assertSessionHas('success');

    return SocialAccount::withoutUserScope()->where('network', 'threads')->firstOrFail();
}

function thfMedia(string $kind = 'image'): MediaAsset
{
    $asset = $kind === 'video'
        ? MediaAsset::factory()->for(test()->user)->video()->create()
        : MediaAsset::factory()->for(test()->user)->create();
    Storage::disk('local')->put($asset->path, 'DATA');

    return $asset;
}

function thfSchedule(SocialAccount $account, string $type, string $text, array $media = []): int
{
    $post = test()->service->create(test()->user, test()->brand, [
        'text' => $text, 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => $media,
        'targets' => [['account_id' => $account->id, 'content_type' => $type, 'text' => null]],
    ]);

    return $post->targets->first()->id;
}

function thfRun(): void
{
    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();
}

function thfTarget(int $id): PostTarget
{
    return PostTarget::withoutUserScope()->find($id);
}

/** Peticiones registradas con ese metodo cuya ruta acaba en $suffix. */
function thfSent(string $method, string $suffix): array
{
    return array_values(array_map(fn ($pair) => $pair[0], array_filter(
        Http::recorded()->all(),
        fn ($pair) => $pair[0]->method() === $method && str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), $suffix),
    )));
}

function thfLimit(int $used = 3): array
{
    return ['data' => [['quota_usage' => $used, 'config' => ['quota_total' => 250, 'quota_duration' => 86400]]]];
}

test('CA-07: conectar por OAuth guarda la cuenta con token largo y caducidad, y la renovacion corre antes del margen', function () {
    $account = thfConnect();

    expect($account->status)->toBe('activa')->and($account->external_id)->toBe(THF_UID)->and($account->access_token)->toBe('TH-LONG-TOKEN')
        ->and($account->refresh_token)->toBeNull()->and($account->token_expires_at->isFuture())->toBeTrue()
        ->and(app(ConnectorRegistry::class)->forProvider('threads'))->toBeInstanceOf(ThreadsConnector::class);

    // A 100 h de caducar (< 168 h de margen) toca renovar; el token nuevo sustituye al viejo.
    $account->forceFill(['token_expires_at' => now()->addHours(100), 'last_refreshed_at' => now()->subDays(40)])->save();
    Http::fake(['graph.threads.com/refresh_access_token*' => Http::response(['access_token' => 'TH-RENOVADO', 'expires_in' => 5183944])]);
    (new RefreshSocialAccount($account->id))->handle(app(ConnectorRegistry::class), app(SocialAccountService::class));

    expect($account->fresh()->access_token)->toBe('TH-RENOVADO')->and($account->fresh()->token_expires_at->gt(now()->addDays(59)))->toBeTrue();
});

test('CA-08: un texto se programa y el job lo publica con su enlace', function () {
    $account = thfConnect();
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST1']),
        THF_API.'/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/POST1*' => Http::response(['permalink' => 'https://www.threads.com/@ana.threads/post/abc']),
    ]);

    $id = thfSchedule($account, 'threads_text', 'Hola Threads desde Metricool');
    thfRun();

    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_id)->toBe('POST1')->and($target->external_url)->toBe('https://www.threads.com/@ana.threads/post/abc');
    expect(thfSent('POST', '/threads')[0]->data())->toMatchArray(['media_type' => 'TEXT', 'text' => 'Hola Threads desde Metricool']);
});

test('CA-08: una imagen viaja por URL publica firmada (image_url) y se publica', function () {
    $account = thfConnect();
    $image = thfMedia();
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST2']),
        THF_API.'/threads' => Http::response(['id' => 'CONT2']),
        'graph.threads.com/v1.0/CONT2*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/POST2*' => Http::response(['permalink' => 'https://www.threads.com/@ana.threads/post/img']),
    ]);

    $id = thfSchedule($account, 'threads_image', 'Foto', [$image->id]);
    thfRun();

    expect(thfTarget($id)->status)->toBe(PostStatus::Published);
    $create = thfSent('POST', '/threads')[0]->data();
    expect($create)->toMatchArray(['media_type' => 'IMAGE', 'text' => 'Foto'])->and($create['image_url'])->toStartWith('http');
});

test('CA-08: el video queda procesando y se publica cuando el contenedor termina', function () {
    $account = thfConnect();
    $video = thfMedia('video');
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST3']),
        THF_API.'/threads' => Http::response(['id' => 'CONT3']),
        'graph.threads.com/v1.0/CONT3*' => Http::sequence()->push(['status' => 'IN_PROGRESS'])->push(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/POST3*' => Http::response(['permalink' => 'https://www.threads.com/@ana.threads/post/vid']),
    ]);

    $id = thfSchedule($account, 'threads_video', 'Mi video', [$video->id]);
    thfRun();

    // La cola es `sync`: el sondeo vuelve a preguntar hasta que el contenedor termina (IN_PROGRESS -> FINISHED) y entonces publica.
    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_id)->toBe('POST3');
    $statusChecks = array_filter(thfSent('GET', '/CONT3'));
    expect(count($statusChecks))->toBeGreaterThanOrEqual(2);
    expect(thfSent('POST', '/threads')[0]->data())->toMatchArray(['media_type' => 'VIDEO']);
});

test('CA-08: un carrusel de 3 imagenes crea 3 hijos y 1 padre y publica el padre', function () {
    $account = thfConnect();
    $images = [thfMedia(), thfMedia(), thfMedia()];
    $ids = ['C1', 'C2', 'C3', 'PARENT'];
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST4']),
        THF_API.'/threads' => function () use (&$ids) {
            return Http::response(['id' => array_shift($ids)]);
        },
        'graph.threads.com/v1.0/POST4*' => Http::response(['permalink' => 'https://www.threads.com/@ana.threads/post/car']),
        'graph.threads.com/v1.0/*' => Http::response(['status' => 'FINISHED']),
    ]);

    $id = thfSchedule($account, 'threads_carousel', 'Carrusel', array_map(fn ($a) => $a->id, $images));
    thfRun();

    expect(thfTarget($id)->status)->toBe(PostStatus::Published);
    $creates = thfSent('POST', '/threads');
    expect($creates)->toHaveCount(4)->and($creates[3]->data())->toMatchArray(['media_type' => 'CAROUSEL', 'children' => 'C1,C2,C3'])
        ->and(thfSent('POST', '/threads_publish')[0]->data()['creation_id'])->toBe('PARENT');
});

test('CA-08: un contenedor en ERROR deja el destino fallido con el motivo traducido', function () {
    $account = thfConnect();
    $video = thfMedia('video');
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads' => Http::response(['id' => 'CONT5']),
        'graph.threads.com/v1.0/CONT5*' => Http::response(['status' => 'ERROR', 'error_message' => 'INVALID_FRAME_RATE']),
    ]);

    $id = thfSchedule($account, 'threads_video', 'Video', [$video->id]);
    thfRun();

    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Failed)->and($target->error_code)->toBe('contenedor_error')->and($target->error_message)->toContain('fotogramas');
});

test('CA-09: con la cuota agotada el destino se aplaza por QuotaPolicy sin crear contenedor ni publicar', function () {
    $account = thfConnect();
    Http::fake([THF_API.'/threads_publishing_limit*' => Http::response(thfLimit(250))]);

    $id = thfSchedule($account, 'threads_text', 'Hola');
    thfRun();

    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Scheduled)->and($target->error_code)->toBe('cuota');
    expect(thfSent('POST', '/threads'))->toBe([])->and(thfSent('POST', '/threads_publish'))->toBe([]);
});

test('CA-09: un timeout al crear el contenedor y su reintento no duplican el post', function () {
    $account = thfConnect();
    $creates = 0;
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST6']),
        THF_API.'/threads?*' => Http::response(['data' => [
            ['id' => 'POST6', 'text' => 'Solo texto', 'permalink' => 'https://www.threads.com/@ana.threads/post/dup', 'timestamp' => '2026-11-01T13:00:30+0000'],
        ]]),
        THF_API.'/threads' => function () use (&$creates) {
            $creates++;
            throw new ConnectionException('cURL error 28: timeout');
        },
    ]);

    $id = thfSchedule($account, 'threads_text', 'Solo texto');
    thfRun();

    // El primer intento termina en error transitorio (puede que Threads ya lo tenga); el reintento consulta antes de publicar.
    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Scheduled)->and($creates)->toBe(1);

    Carbon::setTestNow('2026-11-01 13:20:00');
    $target->forceFill(['next_attempt_at' => null])->save();
    app(DispatchDuePosts::class)();

    $target = thfTarget($id);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_id)->toBe('POST6')->and($creates)->toBe(1);
    expect(thfSent('POST', '/threads_publish'))->toBe([]);
});

test('metricas: la cuenta da visitas al perfil (views nulo) y la publicacion sus cifras', function () {
    $account = thfConnect();
    Http::fake([
        THF_API.'/threads_publishing_limit*' => Http::response(thfLimit()),
        THF_API.'/threads_publish' => Http::response(['id' => 'POST7']),
        THF_API.'/threads' => Http::response(['id' => 'CONT7']),
        'graph.threads.com/v1.0/CONT7*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/POST7?*' => Http::response(['permalink' => 'https://www.threads.com/@ana.threads/post/m']),
    ]);
    $id = thfSchedule($account, 'threads_text', 'Hola');
    thfRun();

    Http::fake([
        THF_API.'/threads_insights*' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(str_contains($q['metric'], 'followers_count')
                ? ['data' => [['name' => 'followers_count', 'total_value' => ['value' => 321]]]]
                : ['data' => [
                    ['name' => 'views', 'period' => 'day', 'values' => [['value' => 40, 'end_time' => '2026-11-01T07:00:00+0000']]],
                    ['name' => 'likes', 'period' => 'day', 'total_value' => ['value' => 12]],
                    ['name' => 'replies', 'period' => 'day', 'total_value' => ['value' => 3]],
                    ['name' => 'reposts', 'period' => 'day', 'total_value' => ['value' => 2]],
                    ['name' => 'quotes', 'period' => 'day', 'total_value' => ['value' => 1]],
                ]]);
        },
        'graph.threads.com/v1.0/POST7/insights*' => Http::response(['data' => [
            ['name' => 'views', 'values' => [['value' => 100]]], ['name' => 'likes', 'values' => [['value' => 10]]],
            ['name' => 'replies', 'values' => [['value' => 4]]], ['name' => 'reposts', 'values' => [['value' => 3]]],
            ['name' => 'quotes', 'values' => [['value' => 2]]], ['name' => 'shares', 'values' => [['value' => 1]]],
        ]]),
    ]);
    $collector = app(SnapshotCollector::class);
    $collector->collectAccount($account->fresh(), Days::closed(Network::Threads));
    $collector->collectPosts($account->fresh(), 48);

    $snapshot = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->first();
    expect($snapshot->metrics[Metric::PROFILE_VIEWS])->not->toBeNull()->and($snapshot->metrics[Metric::VIEWS])->toBeNull()
        ->and($snapshot->metrics[Metric::FOLLOWERS])->toBe(321)->and($snapshot->metrics[Metric::LIKES])->toBe(12);
    $post = PostMetricSnapshot::withoutUserScope()->where('post_target_id', $id)->first();
    expect($post->metrics[Metric::VIEWS])->toBe(100)->and($post->metrics[Metric::SHARES])->toBe(6)->and($post->metrics[Metric::INTERACTIONS])->toBe(20);
});

test('regresion: Threads no cambia el registro de las otras redes', function () {
    $registry = app(ConnectorRegistry::class);

    foreach ([Network::Instagram, Network::Facebook, Network::YouTube, Network::TikTok, Network::Threads] as $network) {
        expect($registry->publisherFor($network))->not->toBeNull($network->value)
            ->and($registry->insightsFor($network))->not->toBeNull($network->value)
            ->and($registry->has($network->provider()))->toBeTrue($network->value);
    }
    expect($registry->providers())->toBe(['meta', 'google', 'tiktok', 'bluesky', 'threads'])
        ->and($registry->forNetwork(Network::Instagram)->provider())->toBe('meta');
});
