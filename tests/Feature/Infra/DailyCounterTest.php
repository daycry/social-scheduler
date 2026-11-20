<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\Sites\SiteService;
use App\Support\DailyCounter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('the shared counter increments an existing bucket and keeps daily buckets distinct', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $site = app(SiteService::class)->create($user, $brand, 'Site', 'counter-site');
    $page = $site->draft['pages'][0]['key'];
    $key = ['site_id' => $site->id, 'page_key' => $page];
    $counter = app(DailyCounter::class);

    $counter->increment('site_visits', $key + ['date' => '2026-11-15']);
    $counter->increment('site_visits', $key + ['date' => '2026-11-15']);
    $counter->increment('site_visits', $key + ['date' => '2026-11-16']);

    expect(DB::table('site_visits')->where($key)->orderBy('date')->pluck('count', 'date')->all())
        ->toEqual(['2026-11-15' => 2, '2026-11-16' => 1]);
});

test('a collision between the first update and the insert increments the concurrent bucket', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $site = app(SiteService::class)->create($user, $brand, 'Site', 'counter-collision');
    $key = ['site_id' => $site->id, 'page_key' => $site->draft['pages'][0]['key'], 'date' => '2026-11-16'];
    $inserted = false;
    $update = 'update '.DB::getQueryGrammar()->wrapTable('site_visits');

    DB::listen(function (QueryExecuted $event) use ($key, $update, &$inserted): void {
        if (! $inserted && str_starts_with($event->sql, $update)) {
            $inserted = true;
            DB::table('site_visits')->insert($key + ['count' => 1]);
        }
    });

    app(DailyCounter::class)->increment('site_visits', $key);

    expect($inserted)->toBeTrue()->and(DB::table('site_visits')->where($key)->value('count'))->toBe(2);
});

test('a visit can finish when the site was removed before its statistics were inserted', function () {
    $key = ['site_id' => 9999, 'page_key' => 'home', 'date' => '2026-11-16'];

    app(DailyCounter::class)->increment('site_visits', $key);

    expect(DB::table('site_visits')->where($key)->exists())->toBeFalse();
});
