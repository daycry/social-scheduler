<?php

use App\Connectors\Network;
use App\Models\Autolist;
use App\Models\AutolistSlot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AutolistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
});

test('N1 realign: franjas vecinas 10:00 y 11:00 Madrid -> London se realinean en UNA pasada sin chocar con el unique', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create(['timezone' => 'Europe/Madrid']);
    $ig = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram]);
    $img = MediaAsset::factory()->for($user)->create();
    $svc = app(AutolistService::class);
    $list = $svc->create($user, $brand, 'L', [['account_id' => $ig->id, 'content_type' => 'instagram_image']], [['day' => 3, 'time' => '10:00'], ['day' => 3, 'time' => '11:00']], true);
    $svc->addItem($user, $list, null, 'a', [$img->id]);
    $svc->addItem($user, $list, null, 'b', [$img->id]);

    expect($svc->generate($list->fresh(), 7)['created'])->toBe(2);

    $brand->forceFill(['timezone' => 'Europe/London'])->save();
    $res = $svc->generate($list->fresh(), 7);

    expect($res['created'])->toBe(0);
    $slots = AutolistSlot::withoutUserScope()->orderBy('id')->get();
    expect($slots)->toHaveCount(2)
        ->and($slots[0]->slot_at->toIso8601String())->toBe('2026-11-04T10:00:00+00:00')
        ->and($slots[1]->slot_at->toIso8601String())->toBe('2026-11-04T11:00:00+00:00')
        ->and(Post::withoutUserScope()->orderBy('scheduled_at')->pluck('scheduled_at')->map->toIso8601String()->all())
        ->toBe(['2026-11-04T10:00:00+00:00', '2026-11-04T11:00:00+00:00'])
        ->and(Post::withoutUserScope()->count())->toBe(2);
});

test('N2 migracion correctora: lista reciclada con cursor > count apunta a items[(cursor-1) % count]', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $lid = DB::table('autolists')->insertGetId([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'L', 'active' => 1, 'recycle' => 1,
        'targets' => '[]', 'slots' => '[]', 'cursor' => 4, 'last_position' => 3, 'last_item_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ids = [];
    foreach (['A', 'B', 'C'] as $i => $t) {
        $ids[$t] = DB::table('autolist_items')->insertGetId(['user_id' => $user->id, 'autolist_id' => $lid, 'position' => $i + 1, 'text' => $t, 'times_used' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    (include database_path('migrations/2026_11_03_090000_fix_autolist_cursor_recycled.php'))->up();

    $l = Autolist::withoutUserScope()->find($lid);
    // Semantica antigua: siguiente = items[4 % 3] = B, asi que el ultimo usado fue A.
    expect($l->last_item_id)->toBe($ids['A'])->and($l->last_position)->toBe(1);
});
