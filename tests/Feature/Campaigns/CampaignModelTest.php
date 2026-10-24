<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;

// Campañas (C-03, CA-12): por marca, asignadas al post entero; un post solo admite campañas de su marca.

test('un usuario no ve las campañas de otro', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Campaign::factory()->for(Brand::factory()->for($a)->create(), 'brand')->for($a)->create(['name' => 'De A']);
    Campaign::factory()->for(Brand::factory()->for($b)->create(), 'brand')->for($b)->create(['name' => 'De B']);

    $this->actingAs($a);

    expect(Campaign::query()->pluck('name')->all())->toBe(['De A']);
});

test('borrar la campaña deja el post sin campaña', function () {
    $post = Post::factory()->create();
    $campaign = Campaign::factory()->create(['brand_id' => $post->brand_id, 'user_id' => $post->user_id]);
    $post->campaign_id = $campaign->id;
    $post->save();

    $campaign->delete();

    $fresh = Post::withoutUserScope()->find($post->id);
    expect($fresh)->not->toBeNull()->and($fresh->campaign_id)->toBeNull();
});

test('un post solo admite campañas de su marca', function () {
    $post = Post::factory()->create();
    $other = Campaign::factory()->create(); // otra marca

    $post->campaign_id = $other->id;

    expect(fn () => $post->save())->toThrow(InvalidArgumentException::class);

    $own = Campaign::factory()->create(['brand_id' => $post->brand_id, 'user_id' => $post->user_id]);
    $post->campaign_id = $own->id;
    $post->save();

    expect($post->fresh()->campaign_id)->toBe($own->id)->and($post->campaign->id)->toBe($own->id);
});

test('la campaña archivada y las fechas se castean', function () {
    $c = Campaign::factory()->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'archived_at' => now()]);

    expect($c->fresh()->starts_on->toDateString())->toBe('2026-11-01')->and($c->isArchived())->toBeTrue();
});
