<?php

use App\Analytics\Metric;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Google\Analytics;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const YT_REPORTS = 'youtubeanalytics.googleapis.com/v2/reports';
const YT_CHANNELS = 'www.googleapis.com/youtube/v3/channels';

function reportBody(array $names, array $values): array
{
    return [
        'kind' => 'youtubeAnalytics#resultTable',
        'columnHeaders' => array_map(fn ($n) => ['name' => $n, 'columnType' => 'METRIC', 'dataType' => 'INTEGER'], $names),
        'rows' => $values === [] ? [] : [$values],
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->account = SocialAccount::factory()->for($user)->create([
        'brand_id' => $brand->id, 'network' => Network::YouTube, 'external_id' => 'UCabc', 'access_token' => 'yt-access',
    ]);
    $this->reader = new Analytics;
    $this->requests = [];
});

function fakeYouTube(array $reports, array $channels, ?array &$requests = null): void
{
    Http::fake(function (Request $request) use ($reports, $channels, &$requests) {
        $requests[] = $request;
        $url = $request->url();

        if (str_contains($url, YT_REPORTS)) {
            $r = array_shift($reports) ?? [];

            return Http::response($r['body'] ?? $r, $r['status'] ?? 200);
        }

        if (str_contains($url, YT_CHANNELS)) {
            return Http::response($channels['body'] ?? $channels, $channels['status'] ?? 200);
        }

        return Http::response('unexpected', 500);
    });
}

it('YouTubeAnalytics: metricas diarias del canal con Analytics API y seguidores de la Data API', function () {
    $names = ['views', 'estimatedMinutesWatched', 'likes', 'comments', 'shares', 'subscribersGained', 'subscribersLost'];
    fakeYouTube([reportBody($names, [340, 1200, 25, 6, 3, 9, 2])], ['items' => [['statistics' => ['subscriberCount' => '1520', 'videoCount' => '48', 'hiddenSubscriberCount' => false]]]], $this->requests);

    $m = $this->reader->accountInsights($this->account, Carbon::parse('2026-10-31'));

    expect($m)->toHaveKeys(Metric::ACCOUNT)
        ->and($m[Metric::VIEWS])->toBe(340)->and($m[Metric::WATCH_MINUTES])->toBe(1200)
        ->and($m[Metric::LIKES])->toBe(25)->and($m[Metric::COMMENTS])->toBe(6)->and($m[Metric::SHARES])->toBe(3)
        ->and($m[Metric::INTERACTIONS])->toBe(34)
        ->and($m[Metric::FOLLOWERS_GAINED])->toBe(9)->and($m[Metric::FOLLOWERS_LOST])->toBe(2)
        ->and($m[Metric::FOLLOWERS])->toBe(1520)->and($m[Metric::VIDEO_COUNT])->toBe(48)
        // YouTube Analytics no ofrece impresiones ni alcance: no disponible.
        ->and($m[Metric::REACH])->toBeNull()->and($m[Metric::PROFILE_VIEWS])->toBeNull();

    $report = collect($this->requests)->first(fn ($r) => str_contains($r->url(), YT_REPORTS));
    parse_str(parse_url($report->url(), PHP_URL_QUERY), $q);
    expect($q['ids'])->toBe('channel==MINE')->and($q['startDate'])->toBe('2026-10-31')->and($q['endDate'])->toBe('2026-10-31')
        ->and($q['metrics'])->toContain('subscribersGained')
        ->and($report->header('Authorization')[0])->toBe('Bearer yt-access');
});

it('YouTubeAnalytics: sin filas para el dia los flujos son null (no 0) y el dia se reintenta', function () {
    fakeYouTube([reportBody(['views'], [])], ['items' => [['statistics' => ['subscriberCount' => '10']]]], $this->requests);

    $m = $this->reader->accountInsights($this->account, Carbon::parse('2026-10-31'));

    expect($m[Metric::VIEWS])->toBeNull()->and($m[Metric::LIKES])->toBeNull()->and($m[Metric::INTERACTIONS])->toBeNull()
        ->and($m[Metric::FOLLOWERS])->toBe(10);
});

it('YouTubeAnalytics: suscriptores ocultos son no disponibles', function () {
    fakeYouTube([reportBody(['views'], [5])], ['items' => [['statistics' => ['hiddenSubscriberCount' => true, 'videoCount' => '3']]]], $this->requests);

    $m = $this->reader->accountInsights($this->account, Carbon::parse('2026-10-31'));

    expect($m[Metric::FOLLOWERS])->toBeNull()->and($m[Metric::VIDEO_COUNT])->toBe(3);
});

it('YouTubeAnalytics: sin scope de Analytics (403) queda no disponible sin romper los stocks', function () {
    fakeYouTube(
        [['status' => 403, 'body' => ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'errors' => [['reason' => 'insufficientPermissions']]]]]],
        ['items' => [['statistics' => ['subscriberCount' => '77']]]],
        $this->requests,
    );

    $m = $this->reader->accountInsights($this->account, Carbon::parse('2026-10-31'));

    expect($m[Metric::VIEWS])->toBeNull()->and($m[Metric::FOLLOWERS])->toBe(77);
});

it('YouTubeAnalytics: un dia antiguo no pide los stocks (la Data API solo da el valor actual)', function () {
    fakeYouTube([reportBody(['views'], [5])], ['items' => []], $this->requests);

    $m = $this->reader->accountInsights($this->account, Carbon::parse('2026-09-01'));

    expect($m[Metric::VIEWS])->toBe(5)->and($m[Metric::FOLLOWERS])->toBeNull();
    expect(collect($this->requests)->filter(fn ($r) => str_contains($r->url(), YT_CHANNELS)))->toBeEmpty();
});

it('YouTubeAnalytics: 5xx, cuota y 401 son transitorios', function () {
    Http::fake([YT_REPORTS.'*' => Http::sequence()
        ->push([], 503)
        ->push(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403)
        ->push([], 401)]);

    foreach (range(1, 3) as $_) {
        expect(fn () => $this->reader->accountInsights($this->account, Carbon::parse('2026-09-01')))->toThrow(TransientPublishException::class);
    }
});

it('YouTubeAnalytics: metricas acumuladas de un video filtradas por video', function () {
    fakeYouTube([reportBody(['views', 'estimatedMinutesWatched', 'likes', 'comments', 'shares'], [900, 400, 40, 8, 5])], ['items' => []], $this->requests);

    $m = $this->reader->postInsights($this->account, 'f-pIxNXaDq0');

    expect($m[Metric::VIEWS])->toBe(900)->and($m[Metric::INTERACTIONS])->toBe(53)->and($m[Metric::REACH])->toBeNull()->and($m[Metric::SAVED])->toBeNull();
    parse_str(parse_url($this->requests[0]->url(), PHP_URL_QUERY), $q);
    expect($q['filters'])->toBe('video==f-pIxNXaDq0');
});
