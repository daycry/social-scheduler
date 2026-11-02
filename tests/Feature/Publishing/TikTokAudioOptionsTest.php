<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['social.tiktok.verified_domain' => 'https://media.example.test']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'meta' => ['direct_post' => true]]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->args = ['text' => 'Fotos', 'schedule' => false, 'media' => [$this->image->id], 'targets' => [[
        'account_id' => $this->account->id, 'content_type' => 'tiktok_photo', 'options' => ['auto_add_music' => true],
    ]]];
});

test('TikTokAudio: photo direct post sends the explicit recommended music choice', function () {
    Http::fake([
        '*/creator_info/query/' => Http::response(['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]),
        '*/content/init/' => Http::response(['data' => ['publish_id' => 'photo-1'], 'error' => ['code' => 'ok']]),
    ]);
    $request = new PublishRequest(1, ContentType::TikTokPhoto, 'Fotos', [new PublishMedia(1, 'image', 'image/jpeg', 'https://media.example.test/photo.jpg', 1080, 1080, disk: 'local', path: 'media/photo.jpg')], ['auto_add_music' => true, 'privacy_level' => 'SELF_ONLY']);
    (new TikTokPublisher)->publish($this->account, $request);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content/init/') && ($r['post_info']['auto_add_music'] ?? null) === true);
});

test('TikTokAudio: videos and inbox mode cannot silently ignore recommended photo music', function () {
    $this->args['targets'][0]['content_type'] = 'tiktok_video';
    $video = MediaAsset::factory()->video(duration: 30)->for($this->user)->create();
    $this->args['media'] = [$video->id];
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->args))->toThrow(PostValidationException::class);
    $this->args['targets'][0]['content_type'] = 'tiktok_photo';
    $this->args['media'] = [$this->image->id];
    $this->account->forceFill(['meta' => ['direct_post' => false]])->save();
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->args))->toThrow(PostValidationException::class);
});

test('TikTokAudio: web and MCP preserve false as an explicit choice and reject malformed music options', function () {
    $this->args['targets'][0]['options']['auto_add_music'] = false;
    $this->actingAs($this->user)->post("/brands/{$this->brand->id}/posts", $this->args)->assertSessionHasNoErrors();
    $post = Post::where('brand_id', $this->brand->id)->firstOrFail();
    expect($post->targets()->first()->options['auto_add_music'] ?? null)->toBeFalse();
    $args = $this->args;
    $args['brand_id'] = $this->brand->id;
    $args['media_ids'] = $args['media'];
    unset($args['media']);
    [$err, $created] = $this->callTool($this->mcpKey($this->user), 'create_post', $args);
    expect($err)->toBeFalse()->and($created['targets'][0]['options']['auto_add_music'] ?? null)->toBeFalse();
    $args['targets'][0]['options']['auto_add_music'] = 'perhaps';
    [$err] = $this->callTool($this->mcpKey($this->user), 'create_post', $args);
    expect($err)->toBeTrue();
});

test('TikTokAudio: raw worker requests also reject automatic music on videos', function () {
    $request = new PublishRequest(1, ContentType::TikTokVideo, 'Vídeo', [], ['auto_add_music' => true]);
    expect(fn () => (new TikTokPublisher)->publish($this->account, $request))->toThrow(PermanentPublishException::class, 'música');
    Http::assertNothingSent();
});
