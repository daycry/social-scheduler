<?php

use App\Analytics\Metric;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\TikTok\Display;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->reader = new Display;
    $this->make = fn (array $scopes) => SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::TikTok, 'scopes' => $scopes, 'access_token' => 'tt-access',
    ]);
});

function ttTarget(SocialAccount $account, ?string $kind, ?string $id = '7300000000000000001'): PostTarget
{
    $post = Post::factory()->for($account->user)->create(['brand_id' => $account->brand_id]);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => Network::TikTok, 'content_type' => ContentType::TikTokVideo,
        'status' => PostStatus::Published, 'external_id' => $id, 'progress' => $kind ? ['external_id_kind' => $kind] : null,
    ]);
}

it('TikTokDisplay: sin los scopes de estadisticas todo es no disponible y no se llama a la API', function () {
    Http::fake();
    $account = ($this->make)(['user.info.basic', 'video.upload', 'video.publish']);

    $m = $this->reader->accountInsights($account, Carbon::parse('2026-10-31'));

    expect($m)->toHaveKeys(Metric::ACCOUNT)->and(Metric::isEmpty($m))->toBeTrue();
    expect($this->reader->postInsights($account, '730'))->toHaveKeys(Metric::POST);
    Http::assertNothingSent();
});

it('TikTokDisplay: con user.info.stats guarda seguidores y numero de videos (stock)', function () {
    Http::fake(['open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['follower_count' => 321, 'video_count' => 12]], 'error' => ['code' => 'ok']])]);
    $account = ($this->make)(['user.info.basic', 'user.info.stats']);

    $m = $this->reader->accountInsights($account, Carbon::parse('2026-10-31'));

    expect($m[Metric::FOLLOWERS])->toBe(321)->and($m[Metric::VIDEO_COUNT])->toBe(12)
        ->and($m[Metric::VIEWS])->toBeNull()->and($m[Metric::REACH])->toBeNull()->and($m[Metric::INTERACTIONS])->toBeNull();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tt-access') && str_contains($r->url(), 'fields=follower_count%2Cvideo_count'));
});

it('TikTokDisplay: un dia antiguo no tiene stock', function () {
    Http::fake();
    $account = ($this->make)(['user.info.stats']);

    expect(Metric::isEmpty($this->reader->accountInsights($account, Carbon::parse('2026-10-01'))))->toBeTrue();
    Http::assertNothingSent();
});

it('TikTokDisplay: un publish_id de borrador NO es un ID de video y no se lee', function () {
    $account = ($this->make)(['video.list']);

    expect($this->reader->canReadPost(ttTarget($account, 'publish_id')->load('account')))->toBeFalse()
        ->and($this->reader->canReadPost(ttTarget($account, null)->load('account')))->toBeFalse()
        ->and($this->reader->canReadPost(ttTarget($account, 'video_id')->load('account')))->toBeTrue();
});

it('TikTokDisplay: aunque el ID sea de video, sin video.list no se lee', function () {
    $account = ($this->make)(['user.info.basic']);

    expect($this->reader->canReadPost(ttTarget($account, 'video_id')->load('account')))->toBeFalse();
});

it('TikTokDisplay: metricas de un video con video.list', function () {
    Http::fake(['open.tiktokapis.com/v2/video/query/*' => Http::response(['data' => ['videos' => [
        ['id' => '7300000000000000001', 'view_count' => 1500, 'like_count' => 100, 'comment_count' => 7, 'share_count' => 3],
    ]], 'error' => ['code' => 'ok']])]);
    $account = ($this->make)(['video.list']);

    $m = $this->reader->postInsights($account, '7300000000000000001');

    expect($m[Metric::VIEWS])->toBe(1500)->and($m[Metric::LIKES])->toBe(100)->and($m[Metric::INTERACTIONS])->toBe(110)
        ->and($m[Metric::REACH])->toBeNull()->and($m[Metric::SAVED])->toBeNull();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['filters']['video_ids'] === ['7300000000000000001']);
});

it('TikTokDisplay: scope_not_authorized es permanente, token invalido pide reconectar y 5xx es transitorio', function () {
    $account = ($this->make)(['user.info.stats']);

    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'scope_not_authorized']], 403)
        ->push(['error' => ['code' => 'access_token_invalid']], 401)
        ->push([], 503)]);

    expect(fn () => $this->reader->accountInsights($account, Carbon::parse('2026-10-31')))->toThrow(PermanentPublishException::class)
        ->and(fn () => $this->reader->accountInsights($account, Carbon::parse('2026-10-31')))->toThrow(ReconnectRequiredException::class)
        ->and(fn () => $this->reader->accountInsights($account, Carbon::parse('2026-10-31')))->toThrow(TransientPublishException::class);
});
