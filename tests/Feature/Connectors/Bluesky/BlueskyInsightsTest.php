<?php

use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\Bluesky\BlueskyInsights;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Services\AnalyticsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;

beforeEach(function () {
    $this->account = BlueskyFake::account();
    $this->insights = app(BlueskyInsights::class);
});

function bskyUri(string $rkey): string
{
    return 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/'.$rkey;
}

test('la cuenta guarda seguidores y publicaciones; el resto es null, nunca 0', function () {
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => BlueskyFake::DID, 'followersCount' => 1234, 'followsCount' => 10, 'postsCount' => 87])]);

    $out = $this->insights->accountInsights($this->account, now()->subDay());

    expect($out[Metric::FOLLOWERS])->toBe(1234)->and($out[Metric::POST_COUNT])->toBe(87);
    foreach (array_diff(Metric::ACCOUNT, [Metric::FOLLOWERS, Metric::POST_COUNT]) as $key) {
        expect($out[$key])->toBeNull($key);
    }
    Http::assertSent(fn ($r) => str_contains($r->url(), 'actor='.rawurlencode(BlueskyFake::DID)) && ! $r->hasHeader('Authorization'));
});

test('un dia pasado no tiene valor (es un stock): no se llama a la red', function () {
    Http::fake();

    $out = $this->insights->accountInsights($this->account, now()->subDays(10));

    expect(Metric::isEmpty($out))->toBeTrue();
    Http::assertNothingSent();
});

test('un perfil sin contadores deja null y no 0', function () {
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => BlueskyFake::DID])]);

    $out = $this->insights->accountInsights($this->account, now()->subDay());

    expect($out[Metric::FOLLOWERS])->toBeNull()->and($out[Metric::POST_COUNT])->toBeNull();
});

test('por post: likes, comentarios, compartidos (reposts + citas) e interacciones; views es null', function () {
    $uri = bskyUri('3kabcdefghij2');
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => [
        ['uri' => $uri, 'cid' => 'c', 'likeCount' => 10, 'replyCount' => 3, 'repostCount' => 4, 'quoteCount' => 2],
    ]])]);

    $out = $this->insights->postInsights($this->account, $uri);

    expect($out[Metric::LIKES])->toBe(10)->and($out[Metric::COMMENTS])->toBe(3)->and($out[Metric::SHARES])->toBe(6)
        ->and($out[Metric::INTERACTIONS])->toBe(19)
        ->and($out[Metric::VIEWS])->toBeNull()->and($out[Metric::REACH])->toBeNull()->and($out[Metric::SAVED])->toBeNull();
});

test('un post con contadores en cero guarda 0 (dato real), y uno ausente guarda null', function () {
    $uri = bskyUri('3kabcdefghij2');
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => [
        ['uri' => $uri, 'likeCount' => 0, 'replyCount' => 0, 'repostCount' => 0, 'quoteCount' => 0],
    ]])]);

    expect($this->insights->postInsights($this->account, $uri)[Metric::INTERACTIONS])->toBe(0);

    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => []])]);
    expect(Metric::isEmpty($this->insights->postInsights($this->account, bskyUri('3kotherpost22'))))->toBeTrue();
});

test('getPosts se pide en lotes de 25 con el parametro uris repetido', function () {
    $uris = array_map(fn ($i) => bskyUri('3k'.str_pad((string) $i, 11, 'a')), range(1, 60));
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => function ($request) {
        preg_match_all('/uris=([^&]+)/', $request->url(), $m);
        $posts = array_map(fn ($u) => ['uri' => urldecode($u), 'likeCount' => 1, 'replyCount' => 0, 'repostCount' => 0, 'quoteCount' => 0], $m[1]);

        return Http::response(['posts' => $posts]);
    }]);

    $out = $this->insights->postsInsights($uris);

    expect($out)->toHaveCount(60)->and(Http::recorded())->toHaveCount(3);
    $sizes = Http::recorded()->map(fn ($pair) => preg_match_all('/uris=/', $pair[0]->url()))->all();
    expect($sizes)->toBe([25, 25, 10]);
    expect(Http::recorded()->first()[0]->url())->not->toContain('uris%5B');
});

test('canReadPost solo con un at:// uri', function () {
    $target = new PostTarget(['external_id' => bskyUri('3kabcdefghij2')]);
    $none = new PostTarget(['external_id' => null]);

    expect($this->insights->canReadPost($target))->toBeTrue()->and($this->insights->canReadPost($none))->toBeFalse();
});

test('errores: red y 5xx son transitorios; un rechazo es permanente', function (Closure $response, string $exception) {
    Http::fake(['public.api.bsky.app/*' => $response]);

    expect(fn () => $this->insights->accountInsights($this->account, now()->subDay()))->toThrow($exception);
})->with([
    'red' => [fn () => throw new ConnectionException('timeout'), TransientPublishException::class],
    '5xx' => [fn () => Http::response('boom', 502), TransientPublishException::class],
    'limite' => [fn () => Http::response(['error' => 'RateLimitExceeded', 'message' => 'x'], 429), TransientPublishException::class],
    'rechazo' => [fn () => Http::response(['error' => 'InvalidRequest', 'message' => 'Profile not found'], 400), PermanentPublishException::class],
]);

test('Bluesky solo ofrece stocks de cuenta y se registra con publicador y lector de metricas', function () {
    $registry = app(ConnectorRegistry::class);

    expect(Metric::EXPECTED_FLOWS['bluesky'])->toBe([])
        ->and(Metric::isComplete('bluesky', [Metric::FOLLOWERS => 5]))->toBeTrue()
        ->and(Metric::isComplete('bluesky', Metric::blankAccount()))->toBeFalse()
        ->and(AnalyticsService::SUPPORTED['bluesky'])->toBe([Metric::FOLLOWERS, Metric::POST_COUNT])
        ->and(Metric::STOCKS)->toContain(Metric::POST_COUNT)
        ->and($registry->has('bluesky'))->toBeTrue()
        ->and($registry->publisherFor(Network::Bluesky))->not->toBeNull()
        ->and($registry->insightsFor(Network::Bluesky))->toBeInstanceOf(BlueskyInsights::class);
});

test('D-1: el colector de publicaciones pide un bloque de 25 con UN getPosts, no uno por publicacion', function () {
    foreach (range(1, 30) as $i) {
        PostTarget::factory()->create([
            'post_id' => Post::factory()->for($this->account->user)->create(['brand_id' => $this->account->brand_id])->id, 'social_account_id' => $this->account->id, 'network' => Network::Bluesky, 'content_type' => ContentType::BlueskyPost,
            'status' => PostStatus::Published, 'external_id' => bskyUri('3k'.str_pad((string) $i, 11, 'a')), 'published_at' => now()->subHours($i),
        ]);
    }
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => function ($request) {
        preg_match_all('/uris=([^&]+)/', $request->url(), $m);

        return Http::response(['posts' => array_map(fn ($u) => ['uri' => urldecode($u), 'likeCount' => 3, 'replyCount' => 1, 'repostCount' => 0, 'quoteCount' => 0], $m[1])]);
    }]);

    $result = app(SnapshotCollector::class)->collectPostsBatch($this->account->fresh(), 48, 0, 60.0);

    expect($result['saved'])->toBe(25)->and($result['next'])->toBe(25)
        ->and(Http::recorded()->map(fn ($p) => substr_count($p[0]->url(), 'uris='))->all())->toBe([25])
        ->and(PostMetricSnapshot::withoutUserScope()->count())->toBe(25)
        ->and(PostMetricSnapshot::withoutUserScope()->first()->metrics[Metric::LIKES])->toBe(3);
});
