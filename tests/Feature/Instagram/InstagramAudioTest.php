<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Tests\Support\FakeGraph;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram,
        'external_id' => '17841400001', 'access_token' => 'page-secret', 'refresh_token' => 'user-secret',
    ]);
    $this->graph = new FakeGraph;
    $this->url = "/brands/{$this->brand->id}/accounts/{$this->account->id}/instagram-audio";
});

test('InstagramAudio: search uses the account User token and returns only safe catalog fields', function () {
    $this->graph->on('GET', '#^ig_audio$#', ['audio' => [[
        'audio_id' => '587784541076604', 'title' => 'Birthday Wish', 'display_artist' => 'Shuba',
        'duration_in_ms' => 153760, 'audio_type' => 'music',
        'download_url' => 'https://scontent.cdninstagram.com/preview.m4a?sig=abc',
        'access_token' => 'must-not-return',
    ]]]);

    $this->actingAs($this->user)->getJson($this->url.'?q=Birthday&type=music')
        ->assertOk()->assertJsonPath('results.0.audio_id', '587784541076604')
        ->assertJsonPath('results.0.title', 'Birthday Wish')
        ->assertJsonPath('results.0.preview_url', 'https://scontent.cdninstagram.com/preview.m4a?sig=abc')
        ->assertDontSee('must-not-return')->assertDontSee('user-secret')->assertDontSee('page-secret');
    expect($this->graph->calls[0]['params'])->toBe(['audio_type' => 'music', 'user_id' => '17841400001', 'search_query' => 'Birthday'])
        ->and($this->graph->calls[0]['authorization'])->toBe('Bearer user-secret')
        ->and($this->graph->calls[0]['url'])->not->toContain('access_token');
});

test('InstagramAudio: all searches both catalogs and metadata refreshes the temporary preview', function () {
    $this->graph->on('GET', '#^ig_audio$#', FakeGraph::seq(
        ['audio' => [['audio_id' => '11', 'title' => 'Music', 'audio_type' => 'music']]],
        ['audio' => [['audio_id' => '22', 'title' => 'Original', 'audio_type' => 'original_sound', 'ig_username' => 'creator']]],
    ))->on('GET', '#^22$#', ['audio_id' => '22', 'title' => 'Original', 'audio_type' => 'original_sound', 'download_url' => 'https://audio.fbcdn.net/new.m4a']);
    $this->actingAs($this->user)->getJson($this->url)->assertOk()->assertJsonCount(2, 'results')->assertJsonPath('results.1.artist', 'creator');
    $this->getJson($this->url.'/22')->assertOk()->assertJsonPath('track.preview_url', 'https://audio.fbcdn.net/new.m4a');
});

test('InstagramAudio: cursor-only pagination keeps both catalog positions and never follows provider URLs', function () {
    $this->graph->on('GET', '#^ig_audio$#', FakeGraph::seq(
        ['audio' => [['audio_id' => '11', 'title' => 'First music']], 'paging' => ['cursors' => ['after' => 'MUSIC-25'], 'next' => 'https://evil.test/?access_token=secret']],
        ['audio' => [['audio_id' => '22', 'title' => 'Original without another page']]],
        ['audio' => [['audio_id' => '33', 'title' => 'Second page music']]],
    ));
    $first = $this->actingAs($this->user)->getJson($this->url.'?q=artist')->assertOk()->assertJsonCount(2, 'results');
    expect($first->json('next_cursor'))->toBeString()->not->toBeEmpty();
    $this->getJson($this->url.'?'.http_build_query(['q' => 'artist', 'cursor' => $first->json('next_cursor')]))
        ->assertOk()->assertJsonPath('results.0.audio_id', '33')->assertJsonCount(1, 'results')
        ->assertJsonPath('next_cursor', null)->assertJsonPath('has_more', false)->assertDontSee('evil.test')->assertDontSee('secret');
    expect($this->graph->calls)->toHaveCount(3)
        ->and($this->graph->calls[2]['path'])->toBe('ig_audio')
        ->and($this->graph->calls[2]['params'])->toBe(['audio_type' => 'music', 'user_id' => '17841400001', 'search_query' => 'artist', 'after' => 'MUSIC-25']);
});

test('InstagramAudio: cursor cannot be changed reused across searches accounts or expired', function () {
    $this->graph->on('GET', '#^ig_audio$#', ['audio' => [['audio_id' => '11']], 'paging' => ['cursors' => ['after' => 'NEXT']]]);
    $cursor = $this->actingAs($this->user)->getJson($this->url.'?q=artist&type=music')->assertOk()->json('next_cursor');
    expect($cursor)->toBeString();
    $other = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    foreach ([
        [$this->url, ['q' => 'other', 'type' => 'music', 'cursor' => $cursor]],
        [$this->url, ['q' => 'artist', 'type' => 'all', 'cursor' => $cursor]],
        ["/brands/{$this->brand->id}/accounts/{$other->id}/instagram-audio", ['q' => 'artist', 'type' => 'music', 'cursor' => $cursor]],
        [$this->url, ['q' => 'artist', 'type' => 'music', 'cursor' => $cursor.'tampered']],
        [$this->url, ['q' => 'artist', 'type' => 'music', 'cursor' => str_repeat('x', 8193)]],
    ] as [$url, $params]) {
        $this->getJson($url.'?'.http_build_query($params))->assertUnprocessable();
    }
    $this->travel(31)->minutes();
    $this->getJson($this->url.'?'.http_build_query(['q' => 'artist', 'type' => 'music', 'cursor' => $cursor]))->assertUnprocessable();
    expect($this->graph->calls)->toHaveCount(1);
});

test('InstagramAudio: repeated and empty pages do not create endless continuation cursors', function () {
    $this->graph->on('GET', '#^ig_audio$#', FakeGraph::seq(
        ['audio' => [['audio_id' => '11']], 'paging' => ['cursors' => ['after' => 'SAME']]],
        ['audio' => [['audio_id' => '11']], 'paging' => ['cursors' => ['after' => 'SAME']]],
        ['audio' => [], 'paging' => ['cursors' => ['after' => 'EMPTY']]],
    ));
    $cursor = $this->actingAs($this->user)->getJson($this->url.'?type=music')->assertOk()->json('next_cursor');
    expect($cursor)->toBeString();
    $this->getJson($this->url.'?'.http_build_query(['type' => 'music', 'cursor' => $cursor]))
        ->assertOk()->assertJsonPath('next_cursor', null)->assertJsonPath('has_more', false);
    $this->getJson($this->url.'?type=music')->assertOk()->assertJsonCount(0, 'results')->assertJsonPath('next_cursor', null);
});

test('InstagramAudio: foreign brand account disconnected and wrong network never call Meta', function () {
    $other = User::factory()->create();
    $this->actingAs($other)->getJson($this->url)->assertNotFound();
    $otherBrand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user)->getJson("/brands/{$otherBrand->id}/accounts/{$this->account->id}/instagram-audio")->assertNotFound();
    foreach ([['status' => SocialAccount::STATUS_DISCONNECTED], ['status' => SocialAccount::STATUS_ACTIVE, 'network' => Network::Facebook]] as $changes) {
        $this->account->forceFill($changes)->save();
        $this->getJson($this->url)->assertUnprocessable();
    }
    expect($this->graph->calls)->toBeEmpty();
});

test('InstagramAudio: missing or expired User token requires reconnect without falling back to Page token', function () {
    foreach ([['refresh_token' => null], ['refresh_token' => 'user-secret', 'token_expires_at' => now()->subMinute()]] as $changes) {
        $this->account->forceFill($changes)->save();
        $this->actingAs($this->user)->getJson($this->url)->assertUnprocessable()->assertJsonPath('message', 'Reconecta Instagram mediante Facebook para usar el catálogo de audio.');
    }
    expect($this->graph->calls)->toBeEmpty();
});

test('InstagramAudio: invalid query or graph paths and dangerous provider URLs are rejected', function () {
    $this->actingAs($this->user)->getJson($this->url.'?type=unsupported')->assertUnprocessable();
    $this->getJson($this->url.'?q='.str_repeat('a', 201))->assertUnprocessable();
    $this->getJson($this->url.'/not-an-audio-id')->assertNotFound();
    $this->graph->on('GET', '#^ig_audio$#', ['audio' => [[
        'audio_id' => '11', 'title' => 'Track', 'audio_type' => 'music',
        'download_url' => 'https://evil.example/audio', 'cover_artwork_thumbnail_uri' => 'javascript:alert(1)',
        'on_platform_audio_preview_link' => 'https://www.instagram.com/reels/audio/11/?access_token=secret',
    ]]]);
    $this->getJson($this->url.'?type=music')->assertOk()->assertJsonPath('results.0.preview_url', null)
        ->assertJsonPath('results.0.artwork_url', null)->assertJsonPath('results.0.platform_url', null);
});

test('InstagramAudio: provider failures and mismatching metadata never become an empty successful selection', function () {
    $this->graph->on('GET', '#^ig_audio$#', FakeGraph::error(190, 'secret provider message'))
        ->on('GET', '#^11$#', ['audio_id' => '22', 'title' => 'Wrong track']);
    $this->actingAs($this->user)->getJson($this->url.'?type=music')->assertUnprocessable()->assertDontSee('secret provider message');
    $this->getJson($this->url.'/11')->assertUnprocessable();
});
