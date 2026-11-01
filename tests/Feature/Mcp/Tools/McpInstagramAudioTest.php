<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGraph;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->key = $this->mcpKey($this->user);
    $this->graph = new FakeGraph;
    $this->graph->on('GET', '#^ig_audio$#', ['audio' => [['audio_id' => '11', 'title' => 'Track', 'audio_type' => 'music']]])
        ->on('GET', '#^11$#', ['audio_id' => '11', 'title' => 'Track', 'audio_type' => 'music']);
});

test('McpInstagramAudio: agent can discover search and refresh a track without exposing credentials', function () {
    [$error, $found] = $this->callTool($this->key, 'search_instagram_audio', ['account_id' => $this->account->id, 'q' => 'Track', 'type' => 'music']);
    expect($error)->toBeFalse()->and($found['results'][0]['audio_id'])->toBe('11');
    [$error, $track] = $this->callTool($this->key, 'get_instagram_audio', ['account_id' => $this->account->id, 'audio_id' => '11']);
    expect($error)->toBeFalse()->and($track['track']['title'])->toBe('Track');
    $tools = $this->rpc($this->key, 'tools/list')->json('result.tools');
    expect(collect($tools)->pluck('name')->all())->toContain('search_instagram_audio', 'get_instagram_audio');
    expect(collect($tools)->firstWhere('name', 'search_instagram_audio')['annotations']['readOnlyHint'])->toBeTrue();
});

test('McpInstagramAudio: agent can request the next audio page with the same search', function () {
    // Http::fake adds callbacks; replace the default fixture factory for this paged scenario.
    Http::swap(new Factory);
    $this->graph = new FakeGraph;
    $this->graph->on('GET', '#^ig_audio$#', FakeGraph::seq(
        ['audio' => [['audio_id' => '11', 'title' => 'First']], 'paging' => ['cursors' => ['after' => 'PAGE-2']]],
        ['audio' => [['audio_id' => '22', 'title' => 'Later']]],
    ));
    $args = ['account_id' => $this->account->id, 'q' => 'artist', 'type' => 'music'];
    [$error, $first] = $this->callTool($this->key, 'search_instagram_audio', $args);
    expect($error)->toBeFalse()->and($first['next_cursor'] ?? null)->toBeString();
    [$error, $next] = $this->callTool($this->key, 'search_instagram_audio', $args + ['cursor' => $first['next_cursor']]);
    expect($error)->toBeFalse()->and($next['results'][0]['audio_id'])->toBe('22')
        ->and($next['next_cursor'])->toBeNull()->and($next['has_more'])->toBeFalse()
        ->and($this->graph->calls[1]['params']['after'])->toBe('PAGE-2');
    [$error] = $this->callTool($this->key, 'search_instagram_audio', $args + ['cursor' => 'invalid']);
    expect($error)->toBeTrue()->and($this->graph->calls)->toHaveCount(2);
    $tools = $this->rpc($this->key, 'tools/list')->json('result.tools');
    expect(collect($tools)->firstWhere('name', 'search_instagram_audio')['inputSchema']['properties'])->toHaveKey('cursor');
});

test('McpInstagramAudio: foreign accounts abilities and malformed identifiers cannot call Meta', function () {
    $other = SocialAccount::factory()->create();
    [$error] = $this->callTool($this->key, 'search_instagram_audio', ['account_id' => $other->id]);
    expect($error)->toBeTrue();
    [$error] = $this->callTool($this->mcpKey($this->user, ['posts:read']), 'search_instagram_audio', ['account_id' => $this->account->id]);
    expect($error)->toBeTrue();
    [$error] = $this->callTool($this->key, 'get_instagram_audio', ['account_id' => $this->account->id, 'audio_id' => '../me']);
    expect($error)->toBeTrue()->and($this->graph->calls)->toBeEmpty();
});

test('McpInstagramAudio: create get update and remove preserve the exact per-target selection', function () {
    $video = MediaAsset::factory()->video(duration: 30)->for($this->user)->create();
    $audio = ['audio_id' => '11', 'audio_volume' => 80, 'video_volume' => 20];
    $args = ['brand_id' => $this->brand->id, 'schedule' => false, 'text' => 'Reel', 'media_ids' => [$video->id], 'targets' => [[
        'account_id' => $this->account->id, 'content_type' => 'instagram_reel', 'options' => ['instagram_audio' => $audio],
    ]]];
    [$error, $post] = $this->callTool($this->key, 'create_post', $args);
    expect($error)->toBeFalse()->and($post['targets'][0]['options']['instagram_audio'])->toBe($audio);
    [$error, $read] = $this->callTool($this->key, 'get_post', ['post_id' => $post['id']]);
    expect($error)->toBeFalse()->and($read['targets'][0]['options']['instagram_audio'])->toBe($audio);
    $args['post_id'] = $post['id'];
    $args['targets'][0]['options']['instagram_audio'] = null;
    [$error, $updated] = $this->callTool($this->key, 'update_post', $args);
    expect($error)->toBeFalse()->and($updated['targets'][0]['options']['instagram_audio'] ?? null)->toBeNull();
});

test('McpInstagramAudio: all six networks describe supported audio without promising a universal catalog', function () {
    config(['social.threads.enabled' => true, 'services.threads.client_id' => 'fake-app', 'social.tiktok.verified_domain' => 'https://media.example.test']);
    foreach ([Network::Facebook, Network::TikTok, Network::YouTube, Network::Bluesky, Network::Threads] as $network) {
        SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => $network, 'meta' => ['direct_post' => true]]);
    }
    [$err, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $accounts = collect($data['accounts'])->keyBy('network');
    expect($err)->toBeFalse()->and($accounts)->toHaveCount(6);
    foreach ($accounts as $account) {
        expect($account)->toHaveKey('audio');
        expect($account['audio']['embedded_video_types'])->not->toBeEmpty();
    }
    expect($accounts['instagram']['audio']['catalog_types'])->toBe(['instagram_reel'])
        ->and($accounts['facebook']['audio']['catalog_types'])->toBe([])
        ->and($accounts['tiktok']['audio']['recommended_music_types'])->toBe(['tiktok_photo']);
});

test('McpInstagramAudio: agent can create read and update Instagram collaborators and AI', function () {
    $video = MediaAsset::factory()->video(duration: 30)->for($this->user)->create();
    $args = ['brand_id' => $this->brand->id, 'schedule' => false, 'text' => 'Reel', 'media_ids' => [$video->id], 'targets' => [[
        'account_id' => $this->account->id, 'content_type' => 'instagram_reel', 'options' => ['collaborators' => ['@Artist'], 'is_ai_generated' => true],
    ]]];
    [$error, $post] = $this->callTool($this->key, 'create_post', $args);
    expect($error)->toBeFalse()->and($post['targets'][0]['options'])->toBe(['collaborators' => ['artist'], 'is_ai_generated' => true]);
    [$error, $read] = $this->callTool($this->key, 'get_post', ['post_id' => $post['id']]);
    expect($error)->toBeFalse()->and($read['targets'][0]['options'])->toBe($post['targets'][0]['options']);
    $args['post_id'] = $post['id'];
    $args['targets'][0]['options'] = ['collaborators' => [], 'is_ai_generated' => false];
    [$error, $updated] = $this->callTool($this->key, 'update_post', $args);
    expect($error)->toBeFalse()->and($updated['targets'][0]['options'])->toBe(['collaborators' => [], 'is_ai_generated' => false]);
    [$error, $accounts] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    expect($error)->toBeFalse()->and($accounts['accounts'][0]['instagram_presets']['max_collaborators'])->toBe(3);
});
