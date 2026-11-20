<?php

use App\Jobs\Studio\RenderStudioThumbnail;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Sites\SiteService;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('detects implicit relationship queries on an Eloquent collection in testing', function () {
    $user = User::factory()->create();
    Brand::factory()->for($user)->count(2)->create();

    $brands = Brand::ownedBy($user)->get();

    expect($brands)->toHaveCount(2);
    expect(fn () => $brands->first()->user)->toThrow(LazyLoadingViolationException::class);
});

function countListQueries(Closure $request): int
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $request();

        return count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

test('the Studio index uses a fixed number of queries as the design list grows', function () {
    config(['cache.default' => 'array', 'studio.thumbnails.enabled' => true]);
    Queue::fake([RenderStudioThumbnail::class]);

    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->actingAs($user);
    $url = route('studio.index', ['brand_id' => $brand->id]);

    $createDesign = fn (int $i) => Design::create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "Diseño {$i}",
        'document' => ['pages' => []], 'width' => 100, 'height' => 100, 'page_count' => 1, 'revision' => 1,
    ]);
    $readIndex = fn (int $rows) => $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Studio/Index')->has('designs.designs', $rows)
        ->where('designs.designs.0.thumbnail_status', 'pending')
        ->where('designs.designs.'.($rows - 1).'.thumbnail_status', 'ready'));

    $createDesign(1)->update(['thumbnail_path' => 'studio-thumbnails/ready.jpg', 'thumbnail_revision' => 1]);
    $pending = $createDesign(2);
    $small = countListQueries(fn () => $readIndex(2));
    Queue::assertPushed(RenderStudioThumbnail::class, fn ($job) => $job->designId === $pending->id);

    foreach (range(3, 16) as $i) {
        $createDesign($i);
    }

    expect($small)->toBeGreaterThan(0);
    expect(countListQueries(fn () => $readIndex(16)))->toBe($small);
});

test('the Sites index uses a fixed number of queries up to the brand limit', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->actingAs($user);
    $url = route('sites.index', ['brand_id' => $brand->id]);
    $sites = app(SiteService::class);
    $readIndex = fn (int $rows) => $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Sites/Index')->has('sites', $rows)
        ->where('sites.0.is_published', false)
        ->where('sites.'.($rows - 1).'.is_published', true));

    $sites->publish($user, $sites->create($user, $brand, 'Sitio 1', 'sitio-1'));
    $sites->create($user, $brand, 'Sitio 2', 'sitio-2');
    $small = countListQueries(fn () => $readIndex(2));

    $limit = SiteService::MAX_SITES_PER_BRAND;
    for ($i = 3; $i <= $limit; $i++) {
        $sites->create($user, $brand, "Sitio {$i}", "sitio-{$i}");
    }

    expect($small)->toBeGreaterThan(0);
    expect(countListQueries(fn () => $readIndex($limit)))->toBe($small);
});
