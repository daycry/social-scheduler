<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00 UTC');
    config(['social.best_times.min_posts' => 3, 'social.best_times.cache_minutes' => 0]);
});

function suggestionHistory(Brand $brand, array $times, array $scores = []): void
{
    $owner = User::findOrFail($brand->user_id);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Instagram]);
    foreach ($times as $index => $time) {
        $post = Post::factory()->for($owner)->for($brand)->create(['status' => PostStatus::Published]);
        $target = PostTarget::factory()->for($owner)->for($post)->create([
            'social_account_id' => $account->id, 'network' => Network::Instagram,
            'status' => PostStatus::Published, 'published_at' => $time,
        ]);
        PostMetricSnapshot::withoutUserScope()->create([
            'user_id' => $owner->id, 'post_target_id' => $target->id,
            'social_account_id' => $account->id, 'network' => Network::Instagram->value,
            'date' => Carbon::parse($time)->toDateString(),
            'metrics' => array_replace(Metric::blankPost(), [Metric::INTERACTIONS => $scores[$index] ?? 20]),
        ]);
    }
}

test('BestTimeSuggestion: a future date comes from the brand heatmap in its local timezone', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    suggestionHistory($brand, ['2026-10-05 08:30:00', '2026-10-12 08:30:00', '2026-10-19 08:30:00']);

    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('available', true)
        ->assertJsonPath('scheduled_at', '2026-11-02T10:00')
        ->assertJsonPath('scheduled_at_utc', '2026-11-02T09:00:00+00:00')
        ->assertJsonPath('timezone', 'Europe/Madrid')
        ->assertJsonPath('posts', 3);
});

test('BestTimeSuggestion: insufficient history explains the reason and never invents a date', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $response = $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('available', false)->assertJsonPath('scheduled_at', null)
        ->assertJsonPath('scheduled_at_utc', null)->assertJsonPath('posts', 0)->assertJsonPath('min_posts', 3);
    expect($response->json('reason'))->toBeString()->not->toBeEmpty();
});

test('BestTimeSuggestion: nonexistent and repeated hours are skipped rather than silently changed', function (string $now, array $history, string $local, string $utc) {
    Carbon::setTestNow($now);
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    suggestionHistory($brand, $history);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('scheduled_at', $local)->assertJsonPath('scheduled_at_utc', $utc);
})->with([
    'spring gap' => ['2026-03-28 12:00:00 UTC', ['2026-03-08 01:15:00', '2026-03-15 01:15:00', '2026-03-22 01:15:00'], '2026-04-05T02:00', '2026-04-05T00:00:00+00:00'],
    'autumn overlap' => ['2026-10-24 12:00:00 UTC', ['2026-10-04 00:15:00', '2026-10-11 00:15:00', '2026-10-18 00:15:00'], '2026-11-01T02:00', '2026-11-01T01:00:00+00:00'],
]);

test('BestTimeSuggestion: fractional timezone offsets are preserved', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Asia/Kathmandu']);
    suggestionHistory($brand, ['2026-10-05 04:45:00', '2026-10-12 04:45:00', '2026-10-19 04:45:00']);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('scheduled_at', '2026-11-02T10:00')
        ->assertJsonPath('scheduled_at_utc', '2026-11-02T04:15:00+00:00');
});

test('BestTimeSuggestion: the current hour is not a future recommendation', function () {
    Carbon::setTestNow('2026-11-02 09:00:00 UTC');
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    suggestionHistory($brand, ['2026-10-05 08:30:00', '2026-10-12 08:30:00', '2026-10-19 08:30:00']);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('scheduled_at', '2026-11-09T10:00');
});

test('BestTimeSuggestion: a foreign and nonexistent brand both return 404', function () {
    $owner = User::factory()->create();
    $foreign = Brand::factory()->create();
    $this->actingAs($owner)->getJson('/brands/'.$foreign->id.'/best-time-suggestion?network=instagram')->assertNotFound();
    $this->getJson('/brands/9999999/best-time-suggestion?network=instagram')->assertNotFound();
});

test('BestTimeSuggestion: unsupported or missing networks are rejected', function (?string $network) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?'.http_build_query(['network' => $network]))
        ->assertUnprocessable()->assertJsonValidationErrors('network');
})->with([null, 'unknown']);

test('BestTimeSuggestion: oversized route identifiers behave like nonexistent brands', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/brands/999999999999999999999999999/best-time-suggestion?network=instagram')->assertNotFound();
});

test('BestTimeSuggestion: an authenticated verified session is required', function () {
    $brand = Brand::factory()->create();
    $url = '/brands/'.$brand->id.'/best-time-suggestion?network=instagram';
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->unverified()->create())->getJson($url)->assertForbidden();
});

test('BestTimeSuggestion: the selected network is not replaced by Instagram', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    suggestionHistory($brand, ['2026-10-05 08:30:00', '2026-10-12 08:30:00', '2026-10-19 08:30:00']);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=facebook')
        ->assertOk()->assertJsonPath('network', 'facebook')->assertJsonPath('available', false)
        ->assertJsonPath('posts', 0)->assertJsonPath('scheduled_at', null);
});

test('BestTimeSuggestion: caching the heatmap never caches a date that has become past', function () {
    config(['social.best_times.cache_minutes' => 10]);
    Carbon::setTestNow('2026-11-02 08:59:00 UTC');
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    suggestionHistory($brand, ['2026-10-05 08:30:00', '2026-10-12 08:30:00', '2026-10-19 08:30:00']);
    $url = '/brands/'.$brand->id.'/best-time-suggestion?network=instagram';
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('scheduled_at', '2026-11-02T10:00');
    Carbon::setTestNow('2026-11-02 09:01:00 UTC');
    $this->getJson($url)->assertOk()->assertJsonPath('scheduled_at', '2026-11-09T10:00');
});

test('BestTimeSuggestion: a new composer accepts a future unambiguous local proposal', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $this->actingAs($owner)->get('/brands/'.$brand->id.'/posts/create?best_time=2028-01-15T10%3A30')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Posts/Compose')
        ->where('initialScheduledAt', '2028-01-15T10:30')->where('post', null));
});

test('BestTimeSuggestion: editing an existing draft ignores the create-only proposal', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $post = Post::factory()->for($owner)->for($brand)->create();
    $this->actingAs($owner)->get('/posts/'.$post->id.'/edit?best_time=2028-01-15T10%3A30')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('post.id', $post->id)->where('initialScheduledAt', null));
});

test('BestTimeSuggestion: malformed past or ambiguous deep links render a usable composer with a notice', function (string $now, string $local) {
    Carbon::setTestNow($now);
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $this->actingAs($owner)->get('/brands/'.$brand->id.'/posts/create?'.http_build_query(['best_time' => $local]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Posts/Compose')
        ->where('initialScheduledAt', null)->where('bestTimeNotice', 'La fecha sugerida del enlace no es válida. Elige otra fecha o consulta Mejor hora.'));
})->with([
    'invalid date' => ['2026-11-01 12:00:00 UTC', '2028-02-30T10:00'],
    'past date' => ['2026-11-01 12:00:00 UTC', '2026-11-01T12:00'],
    'missing hour' => ['2026-03-28 12:00:00 UTC', '2026-03-29T02:00'],
    'repeated hour' => ['2026-10-24 12:00:00 UTC', '2026-10-25T02:00'],
    'embedded null byte' => ['2026-11-01 12:00:00 UTC', "2028-01-15T10:\0".'00'],
]);

test('BestTimeSuggestion: a non-scalar prefill is an informational notice rather than a broken page', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->get('/brands/'.$brand->id.'/posts/create?best_time[]=unexpected')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Posts/Compose')
        ->where('initialScheduledAt', null)->where('bestTimeNotice', 'La fecha sugerida del enlace no es válida. Elige otra fecha o consulta Mejor hora.'));
});

test('BestTimeSuggestion: ranking prefers average then sample count then nearest future', function (array $times, array $scores, string $expected) {
    Carbon::setTestNow('2026-11-04 12:00:00 UTC');
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    suggestionHistory($brand, $times, $scores);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('available', true)->assertJsonPath('scheduled_at', $expected);
})->with([
    'average outranks a closer lower score' => [
        ['2026-10-05 10:00:00', '2026-10-07 10:00:00', '2026-10-09 10:00:00'],
        [40, 20, 10], '2026-11-09T10:00',
    ],
    'sample count outranks proximity on equal averages' => [
        ['2026-10-05 10:00:00', '2026-10-12 10:00:00', '2026-10-19 10:00:00', '2026-10-07 10:00:00', '2026-10-14 10:00:00', '2026-10-09 10:00:00'],
        [20, 20, 20, 20, 20, 20], '2026-11-09T10:00',
    ],
    'nearest future wins independently of heatmap weekday order' => [
        ['2026-10-05 10:00:00', '2026-10-07 10:00:00', '2026-10-09 10:00:00'],
        [20, 20, 20], '2026-11-06T10:00',
    ],
]);

test('BestTimeSuggestion: Calendar brand props retain the non-UTC timezone used by the suggestion', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $this->actingAs($owner)->get('/calendar?brand_id='.$brand->id.'&network=instagram')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Calendar/Index')
        ->where('brands.0.id', $brand->id)->where('brands.0.timezone', 'Europe/Madrid'));
});

test('BestTimeSuggestion: an empty stored timezone uses the same UTC fallback as the widget', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => '']);
    $this->actingAs($owner)->getJson('/brands/'.$brand->id.'/best-time-suggestion?network=instagram')
        ->assertOk()->assertJsonPath('timezone', 'UTC')->assertJsonPath('available', false);
});
