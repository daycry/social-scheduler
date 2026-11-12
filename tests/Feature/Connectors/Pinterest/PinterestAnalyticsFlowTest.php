<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Analytics\SnapshotCollector;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\AnalyticsService;
use App\Services\BestTimesService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\NewNetworksFake;

// T-21: con el lector registrado, los snapshots de Pinterest alimentan el panel de la red, el top de publicaciones y las mejores horas.

beforeEach(function () {
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
    $this->account = NewNetworksFake::pinterestAccount(['scopes' => ['boards:read', 'pins:read', 'user_accounts:read']]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('el colector guarda cuenta y pin de Pinterest y la pagina de la red los muestra', function () {
    Http::fake([
        'api.pinterest.com/v5/user_account/analytics*' => Http::response(['ALL' => ['daily_metrics' => [
            ['data_status' => 'READY', 'date' => '2026-11-09', 'metrics' => ['IMPRESSION' => 200, 'ENGAGEMENT' => 20, 'OUTBOUND_CLICK' => 5]],
        ], 'summary_metrics' => []]]),
        'api.pinterest.com/v5/user_account' => Http::response(['follower_count' => 50, 'pin_count' => 12]),
        'api.pinterest.com/v5/pins/777/analytics*' => Http::response(['ALL' => [
            'lifetime_metrics' => ['TOTAL_REACTIONS' => 4, 'TOTAL_COMMENTS' => 1],
            'summary_metrics' => ['IMPRESSION' => 300, 'SAVE' => 10, 'PIN_CLICK' => 5, 'OUTBOUND_CLICK' => 0],
        ]]),
    ]);

    $post = Post::factory()->for($this->account->user)->create(['brand_id' => $this->account->brand_id, 'text' => 'Receta de pan']);
    PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $this->account->id, 'network' => Network::Pinterest,
        'status' => PostStatus::Published, 'external_id' => '777', 'published_at' => '2026-11-05 10:00:00',
    ]);

    $collector = app(SnapshotCollector::class);
    $collector->collectAccount($this->account, CarbonImmutable::parse('2026-11-09'));
    $collector->collectPosts($this->account, 24 * 30);

    $user = $this->account->user;
    $brand = Brand::withoutGlobalScopes()->findOrFail($this->account->brand_id);
    $detail = app(AnalyticsService::class)->network($user, $brand, Network::Pinterest, Period::make('2026-11-01', '2026-11-09'));
    $metrics = collect($detail['metrics'])->keyBy('key');

    expect($metrics['views']['total'])->toBe(200)->and($metrics['interactions']['total'])->toBe(20)->and($metrics['clicks']['total'])->toBe(5)
        ->and($metrics['followers']['total'])->toBe(50)
        ->and($detail['posts'])->toHaveCount(1)
        ->and($detail['posts'][0]['metrics'][Metric::VIEWS])->toBe(300)
        ->and($detail['posts'][0]['metrics'][Metric::INTERACTIONS])->toBe(20)
        ->and($detail['posts'][0]['metrics'][Metric::SAVED])->toBe(10);

    $heatmap = app(BestTimesService::class)->heatmap($user, $brand, Network::Pinterest);
    expect($heatmap['posts'])->toBe(1)->and($heatmap['network'])->toBe('pinterest');
});

test('G4: el informe de la marca incluye la red Pinterest con sus metricas y su publicacion destacada', function () {
    Http::fake([
        'api.pinterest.com/v5/user_account/analytics*' => Http::response(['ALL' => ['daily_metrics' => [
            ['data_status' => 'READY', 'date' => '2026-11-09', 'metrics' => ['IMPRESSION' => 200, 'ENGAGEMENT' => 20, 'OUTBOUND_CLICK' => 5]],
        ], 'summary_metrics' => []]]),
        'api.pinterest.com/v5/user_account' => Http::response(['follower_count' => 50, 'pin_count' => 12]),
        'api.pinterest.com/v5/pins/777/analytics*' => Http::response(['ALL' => [
            'lifetime_metrics' => ['TOTAL_REACTIONS' => 4, 'TOTAL_COMMENTS' => 1],
            'summary_metrics' => ['IMPRESSION' => 300, 'SAVE' => 10, 'PIN_CLICK' => 5, 'OUTBOUND_CLICK' => 0],
        ]]),
    ]);
    $post = Post::factory()->for($this->account->user)->create(['brand_id' => $this->account->brand_id, 'text' => 'Receta de pan']);
    PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $this->account->id, 'network' => Network::Pinterest,
        'status' => PostStatus::Published, 'external_id' => '777', 'published_at' => '2026-11-05 10:00:00',
    ]);
    $collector = app(SnapshotCollector::class);
    $collector->collectAccount($this->account, CarbonImmutable::parse('2026-11-09'));
    $collector->collectPosts($this->account, 24 * 30);

    $brand = Brand::withoutGlobalScopes()->findOrFail($this->account->brand_id);
    $data = app(ReportService::class)->dataset($this->account->user, $brand, Period::make('2026-11-01', '2026-11-09'));
    $pinterest = collect($data['networks'])->firstWhere('value', 'pinterest');
    $metrics = collect($pinterest['metrics'])->keyBy('key');

    expect($pinterest)->not->toBeNull()->and($metrics['views']['total'])->toBe(200)->and($metrics['interactions']['total'])->toBe(20)
        ->and($metrics['followers']['total'])->toBe(50)
        ->and($pinterest['posts'])->toHaveCount(1)->and($pinterest['posts'][0]['metrics'][Metric::VIEWS])->toBe(300);
});
